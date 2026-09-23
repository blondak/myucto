<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\MoneyS3;

/**
 * Měsíční kontrolní úhrny mezd celé firmy z mzdových dokladů Money S3.
 *
 * Za měsíce, kdy Money vedlo mzdy osob v šifrované databázi agendy, je tohle jediný
 * čitelný souhrn zpracovaných mezd: doklady mzdového modulu nesou období mezd
 * a jejich řádky v deníku částky. Zálohová daň se porovná s měsíčním vyúčtováním
 * daně z příjmů ze závislé činnosti, které Money vede zvlášť (`VYUCDPFO`): mzdový
 * doklad daně účtuje sražené zálohy (`DPFO`) snížené o přeplatky z ročního zúčtování
 * (`Preplatek`). Vyjde-li to záporně, doklad je záporný, kdežto odvod (`Odvod`)
 * zůstane nula - proto se porovnává s rozdílem, ne s odvodem. Srážková daň se
 * vyúčtovává zvlášť a v `VYUCDPFO` není.
 *
 * ── Proč úhrny nejdou do převzatých mezd ────────────────────────────────────
 * `payroll_migration_reference_totals` je evidence po vztazích (osoba × měsíc):
 * z ní vzniká převzatý mzdový běh, ELDP za rok přechodu a srovnávací sestava po
 * zaměstnancích. Firemní součet by tam stál jako jeden zaměstnanec a vyrobil by
 * běh i evidenční list za osobu, která neexistuje. Úhrny proto jdou jen do protokolu
 * převodu jako kontrola.
 *
 * Částky mají znaménko: storno v Money (záporná částka nebo prohozené strany) úhrn
 * měsíce snižuje. Daň, u které název účtu ani text neříká, zda je zálohová, nebo
 * srážková, se počítá jako záloha.
 */
final class MoneyS3PayrollTotals
{
    public const METRICS = [
        'gross', 'employee_social', 'employee_health', 'employer_social', 'employer_health',
        'advance_tax', 'withholding_tax', 'deductions', 'net_payable',
    ];

    /** Rozdíl zálohové daně proti vyúčtování ve `VYUCDPFO`, který se ještě bere jako zaokrouhlení. */
    private const TAX_TOLERANCE = 1.0;

    private const CONCEPT_METRIC = [
        'employment_gross' => 'gross',
        'partner_gross' => 'gross',
        'statutory_gross' => 'gross',
        'employee_social' => 'employee_social',
        'partner_employee_social' => 'employee_social',
        'employee_health' => 'employee_health',
        'partner_employee_health' => 'employee_health',
        'employer_social' => 'employer_social',
        'employer_health' => 'employer_health',
        'advance_tax' => 'advance_tax',
        'partner_advance_tax' => 'advance_tax',
        'withholding_tax' => 'withholding_tax',
        'partner_withholding_tax' => 'withholding_tax',
        'other_deductions' => 'deductions',
        'partner_other_deductions' => 'deductions',
        'enforcement_deductions' => 'deductions',
    ];

    /**
     * Úhrny po měsících mezd, vzestupně.
     *
     * @return list<array<string,mixed>> `period`, metriky {@see self::METRICS} v Kč,
     *         z `VYUCDPFO` `dpfo` (sražené zálohy), `dpfo_refunds` (přeplatky z ročního
     *         zúčtování), `dpfo_remitted` (odvod) a `dpfo_net` (zálohy po přeplatcích),
     *         nebo `null`, a `tax_ok` = zálohová daň z dokladů sedí na `dpfo_net`
     *         (`null`, když vyúčtování v Money chybí)
     */
    public static function fromLedger(MoneyS3PayrollLedger $ledger): array
    {
        $insurance = MoneyS3PayrollPostingMap::insuranceAccounts($ledger);
        $months = [];
        foreach ($ledger->lines as $line) {
            $period = $line['period'];
            if (!$line['flagged'] || $period === null) {
                continue;
            }
            $months[$period] ??= array_fill_keys(self::METRICS, 0.0);
            $employees = self::employee($line['debit']) && self::employee($line['credit']);
            if ($employees) {
                // Závazek čisté mzdy: přeúčtování na analytiku zaměstnance.
                if ($line['code'] === MoneyS3PayrollPostingMap::KIND_NET_WAGE) {
                    $months[$period]['net_payable'] += $line['signed'];
                }
                continue;
            }
            $result = MoneyS3PayrollPostingMap::classify($line, $insurance, $ledger->accountNames);
            if ($result === null) {
                continue;
            }
            $metric = self::CONCEPT_METRIC[$result['concept'] ?? ''] ?? null;
            if ($metric === null && $result['concept'] === null && self::employee($result['debit']) && str_starts_with($result['credit'], '342')) {
                $metric = 'advance_tax';
            }
            if ($metric !== null) {
                $months[$period][$metric] += $result['sign'] * $line['amount'];
            }
        }
        ksort($months);

        $out = [];
        foreach ($months as $period => $values) {
            $row = ['period' => (string) $period];
            foreach ($values as $metric => $value) {
                $row[$metric] = round($value, 2);
            }
            $tax = $ledger->taxTotals[$period] ?? null;
            $row['dpfo'] = $tax['dpfo'] ?? null;
            $row['dpfo_refunds'] = $tax['refunds'] ?? null;
            $row['dpfo_remitted'] = $tax['remitted'] ?? null;
            $row['dpfo_net'] = $tax === null ? null : round($tax['dpfo'] - $tax['refunds'], 2);
            $row['tax_ok'] = $tax === null ? null
                : abs($row['advance_tax'] - $row['dpfo_net']) <= self::TAX_TOLERANCE;
            $out[] = $row;
        }
        return $out;
    }

    private static function employee(string $account): bool
    {
        return str_starts_with($account, '331') || str_starts_with($account, '333') || str_starts_with($account, '366');
    }
}
