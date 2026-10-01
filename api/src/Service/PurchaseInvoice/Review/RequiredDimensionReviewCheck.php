<?php

declare(strict_types=1);

namespace MyInvoice\Service\PurchaseInvoice\Review;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\DimensionAssignmentRepository;
use MyInvoice\Repository\DimensionRepository;
use MyInvoice\Service\Accounting\Dimension\DimensionDefaults;
use MyInvoice\Service\Accounting\Dimension\DimensionRuleEngine;
use MyInvoice\Service\Accounting\Dimension\DimensionRuleService;
use MyInvoice\Service\Accounting\PostingService;
use PDO;

/**
 * Doklad, kterému chybí dimenze povinná podle pravidla (Firma → Dimenze → Pravidla,
 * vynucení `error`) na účtu některé položky — zaúčtování by ho odmítlo
 * (`dimension_required`). Okno kontroly ho proto nabídne hned po vytěžení, ne až
 * při účtování.
 *
 * Dimenze položky se skládají stejně jako při zaúčtování ({@see \MyInvoice\Service\Accounting\Dimension\DimensionStamper}):
 * položka > produkt > kategorie > hlavička dokladu > zakázka > dodavatel > výchozí
 * hodnota pravidla (vč. vozidla podle karty). Účet položky dává SSOT
 * {@see PostingService::purchaseItemExpenseAccounts()}. Kontroluje se jen
 * nezaúčtovaný a nestornovaný doklad — zaúčtovaný už pravidly prošel.
 */
final class RequiredDimensionReviewCheck implements PurchaseReviewCheck
{
    public const REASON = 'missing_required_dimension';

    public function __construct(
        private readonly Connection $db,
        private readonly PostingService $posting,
    ) {}

    public function reason(): string
    {
        return self::REASON;
    }

    public function evaluate(int $supplierId, array $invoice): ?array
    {
        $id = (int) ($invoice['id'] ?? 0);
        if ($id <= 0 || (string) ($invoice['status'] ?? '') === 'cancelled') {
            return null;
        }
        $ruleService = new DimensionRuleService($this->db);
        $rules = $ruleService->usableRules($supplierId);
        $errorRules = array_values(array_filter($rules, static fn (array $r): bool => $r['enforcement'] === 'error'));
        if ($errorRules === [] || $this->isPosted($supplierId, $id)) {
            return null;
        }
        try {
            $itemAccounts = $this->posting->purchaseItemExpenseAccounts($supplierId, $id);
        } catch (\Throwable) {
            return null;
        }
        if ($itemAccounts === []) {
            return null;
        }
        $date = (string) ($invoice['tax_date'] ?? '') !== '' ? (string) $invoice['tax_date']
            : ((string) ($invoice['issue_date'] ?? '') !== '' ? (string) $invoice['issue_date'] : date('Y-m-d'));
        $lines = $this->itemLines($supplierId, $id, $itemAccounts);
        if ($lines === []) {
            return null;
        }
        $lines = $ruleService->applyDefaults($supplierId, 'purchase_invoice', $id, $lines, $date);
        $violations = DimensionRuleEngine::apply($errorRules, $lines, $date, null, false)['violations'];
        if ($violations === []) {
            return null;
        }
        $typeNames = [];
        foreach ((new DimensionRepository($this->db))->listTypes($supplierId) as $t) {
            $typeNames[$t['id']] = (string) $t['name'];
        }
        $missing = [];
        foreach ($violations as $v) {
            $typeId = (int) $v['type_id'];
            $missing[$typeId] ??= ['type_id' => $typeId, 'type_name' => $typeNames[$typeId] ?? ('#' . $typeId), 'account_codes' => []];
            if (!in_array($v['account_code'], $missing[$typeId]['account_codes'], true)) {
                $missing[$typeId]['account_codes'][] = (string) $v['account_code'];
            }
        }
        ksort($missing);
        return ['missing_dimensions' => array_values($missing)];
    }

    /**
     * Řádky pro jádro pravidel: jeden za položku s účtem a dimenzemi, které by nesla
     * po zaúčtování.
     *
     * @param array<int,string> $itemAccounts id položky => kód účtu
     * @return list<array<string,mixed>>
     */
    private function itemLines(int $supplierId, int $invoiceId, array $itemAccounts): array
    {
        $pdo = $this->db->pdo();
        $items = $pdo->prepare(
            'SELECT pii.id, pii.stock_item_id, pii.total_without_vat
               FROM purchase_invoice_items pii
               JOIN purchase_invoices pi ON pi.id = pii.purchase_invoice_id AND pi.supplier_id = ?
              WHERE pii.purchase_invoice_id = ?
              ORDER BY pii.order_index, pii.id'
        );
        $items->execute([$supplierId, $invoiceId]);
        $rows = $items->fetchAll(PDO::FETCH_ASSOC);
        if ($rows === []) {
            return [];
        }
        $chart = $pdo->prepare('SELECT id, account_code FROM chart_of_accounts WHERE supplier_id = ?');
        $chart->execute([$supplierId]);
        $accountIds = [];
        foreach ($chart->fetchAll(PDO::FETCH_ASSOC) as $a) {
            $accountIds[(string) $a['account_code']] = (int) $a['id'];
        }

        $assignments = new DimensionAssignmentRepository($this->db);
        $own = $assignments->documentDimensions($supplierId, 'purchase_invoice', $invoiceId);
        $splits = $assignments->documentSplits($supplierId, 'purchase_invoice', $invoiceId);
        $defaults = new DimensionDefaults($this->db);
        $header = DimensionDefaults::fill($own['header'], $defaults->forSource($supplierId, 'purchase_invoice', $invoiceId));
        $products = $defaults->forProducts($supplierId, array_map(static fn (array $r): int => (int) ($r['stock_item_id'] ?? 0), $rows));

        $lines = [];
        foreach ($rows as $i => $row) {
            $code = $itemAccounts[(int) $row['id']] ?? null;
            if ($code === null) {
                continue;
            }
            $dims = DimensionDefaults::fill(
                DimensionDefaults::fill($own['items'][$i + 1] ?? [], $products[(int) ($row['stock_item_id'] ?? 0)]['header'] ?? []),
                $header,
            );
            $lines[] = [
                'account_id' => $accountIds[$code] ?? 0,
                'account_code' => $code,
                'side' => 'debit',
                'amount' => (float) $row['total_without_vat'],
                'dimensions' => $dims,
                'dimension_splits' => ($splits[$i + 1] ?? []) + ($splits[0] ?? []),
            ];
        }
        return $lines;
    }

    private function isPosted(int $supplierId, int $invoiceId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT 1 FROM journal_entries
              WHERE supplier_id = ? AND source_type = 'purchase_invoice' AND source_id = ? AND reversed_by IS NULL
              LIMIT 1"
        );
        $stmt->execute([$supplierId, $invoiceId]);
        return $stmt->fetchColumn() !== false;
    }
}
