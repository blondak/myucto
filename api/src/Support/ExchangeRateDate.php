<?php

declare(strict_types=1);

namespace MyInvoice\Support;

/**
 * Rozhodný den pro kurz na dokladu — jediný zdroj pravdy.
 *
 * Kurz se váže ke dni vzniku daňové povinnosti / uskutečnění plnění (§ 4 odst. 5 a § 8
 * ZDPH), tedy k DUZP s fallbackem na datum vystavení, když DUZP na dokladu není:
 *
 *     COALESCE(tax_date, issue_date)
 *
 * Stejný výraz čte {@see \MyInvoice\Service\Report\VatLedgerService::fetchPurchases},
 * {@see \MyInvoice\Service\Currency\ExchangeRateApplier::applyToInvoice} i generovaný
 * sloupec `invoices.effective_tax_date` (migrace 1009).
 *
 * ── POZOR na `purchase_invoices.effective_cost_date` ────────────────────────────────
 * To je JINÝ koncept: `GREATEST(COALESCE(tax_date, issue_date), issue_date)` z migrace
 * 1010, tedy datum uznání NÁKLADU (cost/dashboard rodina). U dokladu s DUZP dřívějším
 * než vystavení dává jiný den než rozhodný den pro kurz — kdo ho použije jako kurzové
 * datum, ptá se ČNB na špatný den. Přesně tohle dělal
 * {@see \MyInvoice\Service\Currency\CnbRateDeviationChecker} a hlásil falešné odchylky.
 */
final class ExchangeRateDate
{
    /**
     * Rozhodný den přijaté faktury. NULL jen když doklad nemá ani jedno datum.
     *
     * @param array<string,mixed> $doc řádek `purchase_invoices` (nebo tělo requestu)
     */
    public static function forPurchase(array $doc): ?string
    {
        return self::coalesce($doc['tax_date'] ?? null, $doc['issue_date'] ?? null);
    }

    /**
     * Rozhodný den vystavené faktury — týž výraz jako generovaný sloupec
     * `invoices.effective_tax_date` (migrace 1009).
     *
     * @param array<string,mixed> $doc řádek `invoices` (nebo tělo requestu)
     */
    public static function forInvoice(array $doc): ?string
    {
        return self::coalesce($doc['tax_date'] ?? null, $doc['issue_date'] ?? null);
    }

    /** SQL výraz pro přijaté faktury; `$alias` = alias tabulky `purchase_invoices`. */
    public static function purchaseSql(string $alias = 'pi'): string
    {
        return "COALESCE({$alias}.tax_date, {$alias}.issue_date)";
    }

    /** SQL výraz pro vystavené faktury; `$alias` = alias tabulky `invoices`. */
    public static function invoiceSql(string $alias = 'i'): string
    {
        return "COALESCE({$alias}.tax_date, {$alias}.issue_date)";
    }

    private static function coalesce(mixed $taxDate, mixed $issueDate): ?string
    {
        foreach ([$taxDate, $issueDate] as $candidate) {
            if (!is_string($candidate)) {
                continue;
            }
            $value = trim($candidate);
            if ($value !== '' && $value !== '0000-00-00') {
                return substr($value, 0, 10);
            }
        }

        return null;
    }
}
