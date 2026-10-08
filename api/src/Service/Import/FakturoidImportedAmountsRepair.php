<?php

declare(strict_types=1);

namespace MyInvoice\Service\Import;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\PurchaseInvoiceRepository;
use MyInvoice\Service\Accounting\DocumentLockService;
use MyInvoice\Service\Invoice\InvoiceCalculator;
use MyInvoice\Service\Invoice\InvoiceMath;
use MyInvoice\Service\Invoice\InvoiceRounding;
use MyInvoice\Service\Invoice\PurchaseInvoiceCalculator;
use MyInvoice\Service\Pdf\InvoicePdfRenderer;

/**
 * Oprava částek dokladů, které import z Fakturoidu převzal před opravou #128 a #131.
 * Opakovaný import je přeskočí podle `fakturoid_id`, proto je potřeba samostatná
 * cesta: zdrojové doklady se znovu načtou z API (payload se při importu neukládá)
 * a porovnají s tím, co je v systému.
 *
 * Co se opravuje (stejnými pravidly jako import, {@see FakturoidSourceDocument}):
 *  - `prices_include_vat` a jednotkové ceny položek (#128),
 *  - u přijatého dokladu rekapitulace DPH do haléřového zaokrouhlení
 *    (`vat_overrides`) a zaokrouhlení (`rounding`) (#131).
 * Přepočet jde přes {@see InvoiceCalculator} / {@see PurchaseInvoiceCalculator},
 * položky se tedy přepočtou stejně jako po ruční úpravě dokladu.
 *
 * Co se NEopravuje, jen hlásí ke kontrole:
 *  - zamčený doklad (zaúčtovaný, v uzavřeném období, v zamčeném datu),
 *  - doklad v období, za které je podané přiznání, kontrolní hlášení nebo souhrnné
 *    hlášení k DPH (změna by tiše rozešla doklad s podáním, oprava patří do
 *    dodatečného podání),
 *  - doklad s úhradou, která není jen převzatá z importu (párování z banky, pokladní
 *    doklad, příkaz k úhradě, daňový doklad k platbě),
 *  - doklad, jehož položky nejdou spárovat se zdrojem, nebo rozdíl větší než
 *    zaokrouhlení.
 *
 * Doklad bez DPH (neplátce, sazba 0) má po importu i po opravě tytéž položky,
 * režim cen i rekapitulaci, takže se nehlásí ani nepřepočítává. Jediné, co u něj
 * může přibýt, je zaokrouhlení přijatého dokladu z `rounding_adjustment` (#131),
 * a to bez přepočtu DPH.
 */
final class FakturoidImportedAmountsRepair
{
    private const PRICE_EPSILON = 0.00005;
    private const AMOUNT_EPSILON = 0.005;

    public function __construct(
        private readonly Connection $db,
        private readonly InvoiceCalculator $invCalc,
        private readonly PurchaseInvoiceCalculator $purCalc,
        private readonly PurchaseInvoiceRepository $purchaseRepo,
        private readonly DocumentLockService $locks,
        private readonly InvoicePdfRenderer $pdf,
    ) {}

    /**
     * @param iterable<array<string,mixed>> $invoices zdrojové vydané doklady (invoices.json)
     * @param iterable<array<string,mixed>> $expenses zdrojové přijaté doklady (expenses.json)
     * @return array{
     *     changed: list<array<string,mixed>>,
     *     review: list<array<string,mixed>>,
     *     unchanged: int,
     *     not_in_source: int,
     *     applied: bool
     * }
     */
    public function run(int $supplierId, iterable $invoices, iterable $expenses, bool $apply): array
    {
        $report = ['changed' => [], 'review' => [], 'unchanged' => 0, 'not_in_source' => 0, 'applied' => $apply];

        $local = $this->localIds($supplierId, 'invoices');
        foreach ($invoices as $src) {
            $fid = (int) ($src['id'] ?? 0);
            if (!isset($local[$fid])) continue;
            $this->collect($report, $this->repairIssued($supplierId, $local[$fid], $src, $apply));
            unset($local[$fid]);
        }
        $report['not_in_source'] += count($local);

        $local = $this->localIds($supplierId, 'purchase_invoices');
        foreach ($expenses as $src) {
            $fid = (int) ($src['id'] ?? 0);
            if (!isset($local[$fid])) continue;
            $this->collect($report, $this->repairReceived($supplierId, $local[$fid], $src, $apply));
            unset($local[$fid]);
        }
        $report['not_in_source'] += count($local);

        return $report;
    }

    /** @param array<string,mixed>|null $entry */
    private function collect(array &$report, ?array $entry): void
    {
        if ($entry === null) {
            $report['unchanged']++;
            return;
        }
        if (!empty($entry['changed'])) {
            $report['changed'][] = $entry;
        }
        if (!empty($entry['review'])) {
            $report['review'][] = $entry;
        }
    }

    /** @return array<int,int> fakturoid_id => id */
    private function localIds(int $supplierId, string $table): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT fakturoid_id, id FROM {$table} WHERE supplier_id = ? AND fakturoid_id IS NOT NULL"
        );
        $stmt->execute([$supplierId]);
        $out = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $out[(int) $row['fakturoid_id']] = (int) $row['id'];
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $src
     * @return array<string,mixed>|null null = doklad odpovídá zdroji
     */
    private function repairIssued(int $supplierId, int $id, array $src, bool $apply): ?array
    {
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare(
            'SELECT i.*, c.code AS currency_code FROM invoices i JOIN currencies c ON c.id = i.currency_id
              WHERE i.id = ? AND i.supplier_id = ?'
        );
        $stmt->execute([$id, $supplierId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) return null;
        $items = $this->items('invoice_items', 'invoice_id', $id);
        $entry = $this->entry('issued', $row, $src);
        $reverseCharge = (bool) $row['reverse_charge'];
        $summary = FakturoidSourceDocument::vatSummary($src);

        $planned = $this->plannedItems($items, $src);
        if ($planned === null) {
            return $entry + ['review' => 'Položky dokladu nejdou spárovat s Fakturoidem (jiný počet řádků).'];
        }
        $flag = FakturoidSourceDocument::pricesIncludeVat($src);
        $needsChange = $flag !== (bool) $row['prices_include_vat'] || $this->pricesDiffer($items, $planned);

        if (!$needsChange) {
            $diffs = FakturoidSourceDocument::differences(InvoiceMath::compute($items, $reverseCharge, $flag)['vat_breakdown'], $summary, $reverseCharge);
            return $diffs === [] ? null : $entry + ['review' => FakturoidSourceDocument::describe($diffs)];
        }

        $computed = InvoiceMath::compute($planned, $reverseCharge, $flag);
        $rounding = InvoiceRounding::adjustment(
            $computed['totals']['with_vat'] - (float) $row['advance_paid_amount'],
            (string) $row['rounding_mode'],
            (string) $row['currency_code'],
            (string) $row['payment_method'],
            (string) $row['invoice_type'],
        );
        $entry['after'] = self::amounts($computed['totals']['without_vat'], $computed['totals']['vat'], round($computed['totals']['with_vat'] + $rounding, 2), $rounding);
        $diffs = FakturoidSourceDocument::differences($computed['vat_breakdown'], $summary, $reverseCharge);

        $blockers = array_merge(
            $this->locks->forInvoice($row)->reasons(),
            $this->vatFiledBlocker($supplierId, (string) ($row['effective_tax_date'] ?? $row['issue_date'])),
            $this->issuedPaymentBlockers($id),
        );
        if ($blockers !== []) {
            return $entry + ['review' => 'Doklad nejde opravit automaticky: ' . implode(', ', $blockers) . '. Opravte ho ručně nebo opravným dokladem.', 'blocked' => $blockers];
        }

        if ($apply) {
            $pdo->beginTransaction();
            try {
                $pdo->prepare('UPDATE invoices SET prices_include_vat = ? WHERE id = ? AND supplier_id = ?')
                    ->execute([$flag ? 1 : 0, $id, $supplierId]);
                $this->updatePrices('invoice_items', $items, $planned);
                $this->invCalc->recompute($id);
                // Úhradu převzatou importem (ImportedPaidInvoicePayment) zapsal import na
                // tehdejší chybnou částku k úhradě, posouvá se s dokladem.
                $pdo->prepare(
                    "UPDATE invoice_payments p JOIN invoices i ON i.id = p.invoice_id
                        SET p.amount = i.amount_to_pay
                      WHERE p.invoice_id = ? AND p.source = 'legacy' AND i.amount_to_pay > 0"
                )->execute([$id]);
                $pdo->prepare(
                    'UPDATE invoices i SET i.paid_total = (SELECT COALESCE(SUM(p.amount), 0) FROM invoice_payments p WHERE p.invoice_id = i.id)
                      WHERE i.id = ? AND EXISTS (SELECT 1 FROM invoice_payments p2 WHERE p2.invoice_id = i.id)'
                )->execute([$id]);
                $pdo->commit();
            } catch (\Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }
            $this->pdf->invalidate($id, 'fakturoid_amount_repair', archive: (string) $row['status'] !== 'draft');
        }

        $entry['changed'] = true;
        if ($diffs !== []) {
            $entry['review'] = FakturoidSourceDocument::describe($diffs);
        }
        return $entry;
    }

    /**
     * @param array<string,mixed> $src
     * @return array<string,mixed>|null null = doklad odpovídá zdroji
     */
    private function repairReceived(int $supplierId, int $id, array $src, bool $apply): ?array
    {
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare('SELECT * FROM purchase_invoices WHERE id = ? AND supplier_id = ?');
        $stmt->execute([$id, $supplierId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) return null;
        $items = $this->items('purchase_invoice_items', 'purchase_invoice_id', $id);
        $entry = $this->entry('received', $row, $src);
        $reverseCharge = (bool) $row['reverse_charge'];
        $summary = FakturoidSourceDocument::vatSummary($src);

        $planned = $this->plannedItems($items, $src);
        if ($planned === null) {
            return $entry + ['review' => 'Položky dokladu nejdou spárovat s Fakturoidem (jiný počet řádků).'];
        }
        $flag = FakturoidSourceDocument::pricesIncludeVat($src);
        $pricesChange = $flag !== (bool) $row['prices_include_vat'] || $this->pricesDiffer($items, $planned);

        // Cílový stav stejným postupem jako import: přepočet, srovnání rekapitulace.
        $computed = InvoiceMath::compute($planned, $reverseCharge, $flag);
        $diffs = FakturoidSourceDocument::differences($computed['vat_breakdown'], $summary, $reverseCharge);
        $overrides = null;
        if ($diffs !== [] && !$reverseCharge) {
            $overrides = FakturoidSourceDocument::alignableOverrides($diffs, $computed['vat_breakdown']);
            if ($overrides !== null) {
                $computed = InvoiceMath::compute($planned, $reverseCharge, $flag, $overrides);
                $diffs = FakturoidSourceDocument::differences($computed['vat_breakdown'], $summary, $reverseCharge);
            }
        }
        $rounding = FakturoidSourceDocument::roundingAdjustment($src);
        $totalsChange = abs($computed['totals']['without_vat'] - (float) $row['total_without_vat']) > self::AMOUNT_EPSILON
            || abs($computed['totals']['vat'] - (float) $row['total_vat']) > self::AMOUNT_EPSILON;
        $roundingChange = $rounding !== 0.0 && abs($rounding - (float) $row['rounding']) > self::AMOUNT_EPSILON;

        if (!$pricesChange && !$totalsChange && !$roundingChange) {
            return $diffs === [] ? null : $entry + ['review' => FakturoidSourceDocument::describe($diffs)];
        }

        $entry['after'] = self::amounts(
            $computed['totals']['without_vat'],
            $computed['totals']['vat'],
            $computed['totals']['with_vat'],
            $roundingChange ? $rounding : (float) $row['rounding'],
        );

        $blockers = array_merge(
            $this->locks->forPurchaseInvoice($row)->reasons(),
            $this->vatFiledBlocker($supplierId, (string) ($row['effective_cost_date'] ?? $row['issue_date'])),
            $this->purchasePaymentBlockers($id, $row),
            // Ruční rekapitulaci DPH zadala účetní, oprava ji nepřepíše.
            ($pricesChange || $totalsChange) && $row['vat_overrides'] !== null ? ['manual_vat_overrides'] : [],
        );
        if ($blockers !== []) {
            return $entry + ['review' => 'Doklad nejde opravit automaticky: ' . implode(', ', $blockers) . '. Opravte ho ručně nebo opravným dokladem.', 'blocked' => $blockers];
        }

        if ($apply) {
            $pdo->beginTransaction();
            try {
                if ($pricesChange || $totalsChange) {
                    $pdo->prepare('UPDATE purchase_invoices SET prices_include_vat = ? WHERE id = ? AND supplier_id = ?')
                        ->execute([$flag ? 1 : 0, $id, $supplierId]);
                    $this->updatePrices('purchase_invoice_items', $items, $planned);
                    if ($overrides !== null && $overrides !== []) {
                        $this->purchaseRepo->setVatOverrides($id, $supplierId, $overrides);
                    }
                    $this->purCalc->recompute($id);
                }
                if ($roundingChange) {
                    $this->purchaseRepo->setRounding($id, $supplierId, $rounding);
                }
                if ($diffs !== []) {
                    $this->purchaseRepo->appendExtractionWarning(
                        $id,
                        $supplierId,
                        'Oprava importu z Fakturoidu: ' . FakturoidSourceDocument::describe($diffs) . ' Doklad zkontrolujte proti originálu.',
                    );
                }
                $pdo->commit();
            } catch (\Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }
        }

        $entry['changed'] = true;
        if ($diffs !== []) {
            $entry['review'] = FakturoidSourceDocument::describe($diffs);
        }
        return $entry;
    }

    /**
     * Položky s cenou ze zdroje. Páruje se pořadím; jiný počet řádků = nejde.
     *
     * @param list<array<string,mixed>> $items
     * @param array<string,mixed> $src
     * @return list<array<string,mixed>>|null
     */
    private function plannedItems(array $items, array $src): ?array
    {
        $lines = array_values(array_filter($src['lines'] ?? [], 'is_array'));
        if (count($lines) !== count($items)) {
            return null;
        }
        $planned = [];
        foreach ($items as $idx => $item) {
            $planned[] = ['unit_price_without_vat' => FakturoidSourceDocument::lineUnitPrice($lines[$idx])] + $item;
        }
        return $planned;
    }

    /**
     * @param list<array<string,mixed>> $items
     * @param list<array<string,mixed>> $planned
     */
    private function pricesDiffer(array $items, array $planned): bool
    {
        foreach ($items as $idx => $item) {
            if (abs((float) $item['unit_price_without_vat'] - (float) $planned[$idx]['unit_price_without_vat']) > self::PRICE_EPSILON) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param list<array<string,mixed>> $items
     * @param list<array<string,mixed>> $planned
     */
    private function updatePrices(string $table, array $items, array $planned): void
    {
        $stmt = $this->db->pdo()->prepare("UPDATE {$table} SET unit_price_without_vat = ? WHERE id = ?");
        foreach ($items as $idx => $item) {
            $stmt->execute([$planned[$idx]['unit_price_without_vat'], (int) $item['id']]);
        }
    }

    /** @return list<array<string,mixed>> */
    private function items(string $table, string $fk, int $id): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT id, quantity, duration_minutes, unit_price_without_vat, vat_rate_snapshot
               FROM {$table} WHERE {$fk} = ? ORDER BY order_index, id"
        );
        $stmt->execute([$id]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Podané přiznání, kontrolní nebo souhrnné hlášení k DPH za období, které končí
     * v den dokladu nebo později. U přijatého dokladu může odpočet spadnout i do
     * pozdějšího období než DUZP, proto se bere každé podání po datu dokladu.
     *
     * @return list<string>
     */
    private function vatFiledBlocker(int $supplierId, string $docDate): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT 1 FROM tax_submissions
              WHERE supplier_id = ?
                AND form_code IN ('DPHDP3', 'DPHKH1', 'DPHSHV')
                AND status IN ('submitted', 'accepted')
                AND LAST_DAY(MAKEDATE(period_year, 1) + INTERVAL (COALESCE(period_month, period_quarter * 3) - 1) MONTH) >= ?
              LIMIT 1"
        );
        $stmt->execute([$supplierId, $docDate]);
        return $stmt->fetchColumn() !== false ? ['vat_filed'] : [];
    }

    /** @return list<string> */
    private function issuedPaymentBlockers(int $id): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT
                (SELECT COUNT(*) FROM invoice_payments WHERE invoice_id = ?
                    AND (source <> 'legacy' OR bank_transaction_id IS NOT NULL OR tax_document_invoice_id IS NOT NULL))
              + (SELECT COUNT(*) FROM payment_matches WHERE invoice_id = ?)
              + (SELECT COUNT(*) FROM cash_documents WHERE invoice_id = ?)"
        );
        $stmt->execute([$id, $id, $id]);
        return (int) $stmt->fetchColumn() > 0 ? ['payment'] : [];
    }

    /**
     * @param array<string,mixed> $row
     * @return list<string>
     */
    private function purchasePaymentBlockers(int $id, array $row): array
    {
        if ($row['paid_amount_invoice_ccy'] !== null || $row['paid_amount_payment_ccy'] !== null) {
            return ['payment'];
        }
        $stmt = $this->db->pdo()->prepare(
            'SELECT (SELECT COUNT(*) FROM payment_matches WHERE purchase_invoice_id = ?)
                  + (SELECT COUNT(*) FROM cash_documents WHERE purchase_invoice_id = ?)
                  + (SELECT COUNT(*) FROM payment_order_items WHERE purchase_invoice_id = ?)'
        );
        $stmt->execute([$id, $id, $id]);
        return (int) $stmt->fetchColumn() > 0 ? ['payment'] : [];
    }

    /**
     * @param array<string,mixed> $row
     * @param array<string,mixed> $src
     * @return array<string,mixed>
     */
    private function entry(string $agenda, array $row, array $src): array
    {
        return [
            'agenda' => $agenda,
            'local_id' => (int) $row['id'],
            'fakturoid_id' => (int) ($src['id'] ?? 0),
            'number' => (string) (($agenda === 'issued' ? ($row['varsymbol'] ?? '') : ($row['vendor_invoice_number'] ?? '')) ?: ($src['number'] ?? '')),
            'before' => self::amounts((float) $row['total_without_vat'], (float) $row['total_vat'], (float) $row['total_with_vat'], (float) $row['rounding']),
        ];
    }

    /** @return array{base: float, vat: float, total: float, rounding: float} */
    private static function amounts(float $base, float $vat, float $total, float $rounding): array
    {
        return ['base' => round($base, 2), 'vat' => round($vat, 2), 'total' => round($total, 2), 'rounding' => round($rounding, 2)];
    }
}
