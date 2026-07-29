<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Expense;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\ExpenseClassificationRuleRepository;
use MyInvoice\Service\Accounting\Bank\BankMessageNormalizer;
use PDO;

/**
 * Návrh časového rozlišení ročního předplatného (381) pro řádky přijaté faktury (Automatizace 2026).
 *
 * Spojuje dvě existující věci: pravidla klasifikace nákladů s příznakem `recurring_prepaid` (1102)
 * a sloupce `accrual_from/accrual_to` na položce (1100). U faktury od dodavatele označeného jako
 * „roční předplatné přes přelom roku" (cloud/hosting, parkovné, pojistné)
 * NAVRHNE období rozlišení; účetní ho v editoru potvrdí a teprve uzávěrka odloží zbytek na 381.
 *
 * READ-ONLY. Nic neukládá ani neúčtuje — stejný kontrakt jako {@see ExpenseClassificationService}
 * (návrh, který uživatel přijme nebo přepíše). Datovou heuristiku drží pure
 * {@see RecurringPrepaidAccrualSuggester}, tady je jen obstarání vstupů z DB a spárování dodavatele.
 */
final class RecurringPrepaidSuggestionService
{
    /** Druhy výdaje, které se NErozlišují — věc (notebook, materiál) se spotřebuje pořízením. */
    private const NON_ACCRUABLE_KINDS = ['material', 'small_asset', 'fixed_asset'];

    public function __construct(
        private readonly Connection $db,
        private readonly ExpenseClassificationRuleRepository $rules,
    ) {}

    /**
     * Návrh accrual_from/accrual_to pro řádky JEDNÉ přijaté faktury; klíčem je id položky. Řádek,
     * který se nekvalifikuje (dodavatel bez recurring_prepaid pravidla, faktura z 1. pololetí,
     * již vyplněné rozlišení, věcný druh výdaje), ve výstupu chybí.
     *
     * @return array<int,array<string,mixed>>
     */
    public function suggestForInvoice(int $supplierId, int $purchaseInvoiceId): array
    {
        $header = $this->header($supplierId, $purchaseInvoiceId);
        if ($header === null) {
            return [];
        }

        $recurringRules = array_values(array_filter(
            $this->rules->activeFor($supplierId),
            static fn (array $r): bool => (bool) ($r['recurring_prepaid'] ?? false),
        ));
        if ($recurringRules === []) {
            return [];
        }

        $vendorName = $header['vendor_name'] !== null ? (string) $header['vendor_name'] : '';
        $vendorClientId = $header['vendor_id'] !== null ? (int) $header['vendor_id'] : null;
        $coverageStart = (string) $header['coverage_start'];

        $out = [];
        foreach ($this->items($purchaseInvoiceId) as $item) {
            if (in_array((string) ($item['expense_kind'] ?? ''), self::NON_ACCRUABLE_KINDS, true)) {
                continue;
            }
            $rule = $this->matchRule($recurringRules, $vendorName, $vendorClientId, (string) $item['description']);
            if ($rule === null) {
                continue;
            }
            $dates = RecurringPrepaidAccrualSuggester::suggest(
                $coverageStart,
                $item['accrual_from'] !== null ? substr((string) $item['accrual_from'], 0, 10) : null,
                $item['accrual_to'] !== null ? substr((string) $item['accrual_to'], 0, 10) : null,
            );
            if ($dates === null) {
                continue;
            }
            $out[(int) $item['id']] = [
                'item_id' => (int) $item['id'],
                'accrual_from' => $dates['from'],
                'accrual_to' => $dates['to'],
                'rule_id' => (int) $rule['id'],
                'rule_name' => (string) $rule['name'],
                'source' => 'recurring_rule',
                'reason' => sprintf(
                    'dodavatel dle pravidla „%s" (roční předplatné) — návrh časového rozlišení 381 na období %s – %s; potvrď v editoru',
                    (string) $rule['name'],
                    $dates['from'],
                    $dates['to'],
                ),
            ];
        }
        return $out;
    }

    /** @return array<string,mixed>|null */
    private function header(int $supplierId, int $purchaseInvoiceId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT pi.id, pi.vendor_id,
                    COALESCE(pi.tax_date, pi.issue_date) AS coverage_start,
                    COALESCE(NULLIF(c.company_name, ''), JSON_UNQUOTE(JSON_EXTRACT(pi.vendor_snapshot, '$.name'))) AS vendor_name
               FROM purchase_invoices pi
               LEFT JOIN clients c ON c.id = pi.vendor_id AND c.supplier_id = pi.supplier_id
              WHERE pi.id = ? AND pi.supplier_id = ?
                AND pi.status NOT IN ('draft', 'cancelled')"
        );
        $stmt->execute([$purchaseInvoiceId, $supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /** @return list<array<string,mixed>> */
    private function items(int $purchaseInvoiceId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, description, expense_kind, accrual_from, accrual_to
               FROM purchase_invoice_items
              WHERE purchase_invoice_id = ?
              ORDER BY order_index, id'
        );
        $stmt->execute([$purchaseInvoiceId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * První recurring_prepaid pravidlo (v pořadí priority), které sedí na dodavatele a řádek.
     * Kritéria jsou AND přes vyplněná — týž kontrakt jako ExpenseKindClassifier::fromRules.
     *
     * @param list<array<string,mixed>> $rules
     * @return array<string,mixed>|null
     */
    private function matchRule(array $rules, string $vendorName, ?int $vendorClientId, string $description): ?array
    {
        $vendor = BankMessageNormalizer::normalizeKeepDigits($vendorName);
        $text = BankMessageNormalizer::normalizeKeepDigits($description);

        foreach ($rules as $rule) {
            $matched = 0;

            $ruleClientId = isset($rule['vendor_client_id']) ? (int) $rule['vendor_client_id'] : 0;
            if ($ruleClientId > 0) {
                if ($vendorClientId !== $ruleClientId) {
                    continue;
                }
                $matched++;
            }

            $vendorFragment = self::str($rule['vendor_name_contains'] ?? null);
            if ($vendorFragment !== null) {
                if ($vendor === '' || !str_contains($vendor, BankMessageNormalizer::normalizeKeepDigits($vendorFragment))) {
                    continue;
                }
                $matched++;
            }

            $textFragment = self::str($rule['description_contains'] ?? null);
            if ($textFragment !== null) {
                if (!str_contains($text, BankMessageNormalizer::normalizeKeepDigits($textFragment))) {
                    continue;
                }
                $matched++;
            }

            if ($matched > 0) {
                return $rule;
            }
        }
        return null;
    }

    private static function str(mixed $v): ?string
    {
        if ($v === null) {
            return null;
        }
        $s = trim((string) $v);
        return $s === '' ? null : $s;
    }
}
