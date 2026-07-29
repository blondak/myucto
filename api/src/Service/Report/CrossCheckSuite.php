<?php

declare(strict_types=1);

namespace MyInvoice\Service\Report;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Service\Accounting\Reports\FinancialStatementService;
use MyInvoice\Service\Accounting\Reports\TrialBalanceService;

/**
 * Křížové kontroly (vrstva L4 auditního plánu) — totéž číslo spočítané DVĚMA
 * NEZÁVISLÝMI cestami a porovnané.
 *
 * Proč zrovna takhle: chyba, která je v obou cestách stejná, se křížovou kontrolou
 * neodhalí — ale chyba v jedné cestě ano, a to bez znalosti toho, kde přesně je.
 * `VatCrossCheckService` ten princip už měl pro DPH ↔ KH ↔ SH ↔ 343; tahle třída
 * ho zobecňuje na celý uzavřený rok a doplňuje účetní dvojice:
 *
 *   1. DPH: přiznání ↔ KH ↔ SH ↔ obrat 343    (delegace na VatCrossCheckService,
 *      za VŠECHNA zdaňovací období roku, ne za jedno)
 *   2. Rozvaha: aktiva = pasiva                (výkaz proti sobě)
 *   3. VH v rozvaze = VH ve VZZ                (dva různé výkazy z téhož deníku)
 *   4. VZZ = obratová předvaha                 (výkaz proti hlavní knize)
 *   5. Obratová předvaha: Σ MD = Σ D           (kniha proti sobě)
 *
 * Kontroly 2–5 se pouštějí jen nad UZAVŘENÝM obdobím: před uzávěrkou nejsou
 * výsledkové účty vynulované a rozdíly by byly legitimní.
 *
 * Služba NEDUPLIKUJE logiku výkazů — volá jejich reálné buildery, aby se kontrola
 * nikdy nerozešla s tím, co se skutečně podává a tiskne. Read-only.
 */
final class CrossCheckSuite
{
    /**
     * Tolerance shody v Kč. Výkazy zaokrouhlují po řádcích, deník je v haléřích —
     * pár korun je zaokrouhlení, ne chyba; chybějící doklad se projeví řádově výš.
     */
    private const EPS = 1.0;

    public function __construct(
        private readonly VatCrossCheckService $vat,
        private readonly FinancialStatementService $statements,
        private readonly TrialBalanceService $trialBalance,
        private readonly AccountingPeriodRepository $periods,
        private readonly Connection $db,
    ) {}

    /**
     * Spustí všechny křížové kontroly za jeden účetní rok.
     *
     * @return list<array{check:string, label:string, ok:bool, a_label:string, a:?float,
     *                    b_label:string, b:?float, difference:?float, note:?string, skipped:bool}>
     */
    public function run(int $supplierId, int $year): array
    {
        $period = $this->periods->findByYear($supplierId, $year);
        if ($period === null) {
            return [$this->skip('period', 'Účetní období ' . $year, 'období v systému neexistuje')];
        }

        $results = $this->vatChecksForYear($supplierId, $year);

        $status = (string) ($period['status'] ?? '');
        if (!in_array($status, ['closed', 'approved'], true)) {
            $results[] = $this->skip(
                'accounting',
                'Účetní křížové kontroly ' . $year,
                'období je ve stavu "' . $status . '" — před uzávěrkou nejsou výsledkové účty vynulované',
            );
            return $results;
        }

        $periodId = (int) $period['id'];
        $scope = 'full';

        $balance = $this->statements->balanceSheet($supplierId, $periodId, null, $scope);
        $income  = $this->statements->incomeStatement($supplierId, $periodId, null, $scope);
        $tb      = $this->trialBalance->build($supplierId, $periodId, null, null);

        $results[] = $this->compare(
            'balance_sheet_sides',
            'Rozvaha: aktiva = pasiva (§ 18–20 ZoÚ)',
            'aktiva netto',
            $this->totalOf($balance, 'assets_net'),
            'pasiva netto',
            $this->totalOf($balance, 'liabilities_total'),
        );

        $results[] = $this->compare(
            'profit_balance_vs_income',
            'VH v rozvaze = VH ve výsledovce',
            'VH v rozvaze',
            $this->profitFromBalanceSheet($balance),
            'VH ve VZZ',
            $this->profitFromIncomeStatement($income),
        );

        $results[] = $this->compare(
            'income_statement_vs_ledger',
            'VZZ = obratová předvaha (výnosy − náklady)',
            'VH ve VZZ',
            $this->profitFromIncomeStatement($income),
            'z hlavní knihy',
            $this->profitFromLedger($supplierId, (string) $period['starts_on'], (string) $period['ends_on']),
        );

        $results[] = $this->compare(
            'trial_balance_sides',
            'Obratová předvaha: Σ MD = Σ D (ČÚS 001)',
            'Σ MD obratů',
            $this->trialBalanceTotal($tb, 'turnover_md'),
            'Σ D obratů',
            $this->trialBalanceTotal($tb, 'turnover_d'),
        );

        return $results;
    }

    /** Roky, které mají uzavřené účetní období — vhodné jako golden korpus. */
    public function closedYears(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT fiscal_year FROM accounting_periods
              WHERE supplier_id = ? AND status IN ('closed', 'approved')
           ORDER BY fiscal_year"
        );
        $stmt->execute([$supplierId]);

        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * DPH smír za VŠECHNA zdaňovací období roku. Původní služba počítá jedno období;
     * kontrola nad jedním měsícem přitom nic neříká o roce — a rok je to, co se
     * rekonciliuje proti podaným přiznáním.
     *
     * @return list<array<string,mixed>>
     */
    private function vatChecksForYear(int $supplierId, int $year): array
    {
        $vatPeriod = (string) ($this->db->pdo()->query(
            'SELECT vat_period FROM supplier WHERE id = ' . (int) $supplierId
        )->fetchColumn() ?: 'monthly');

        $months = $vatPeriod === 'quarterly' ? [3, 6, 9, 12] : range(1, 12);
        $out = [];

        foreach ($months as $month) {
            $findings = $this->vat->check($supplierId, $year, $month, $vatPeriod);
            $blocking = array_values(array_filter($findings, static fn (array $f): bool => (bool) ($f['blocking'] ?? false)));

            $label = sprintf('DPH smír %d/%d (%s)', $month, $year, $vatPeriod === 'quarterly' ? 'čtvrtletně' : 'měsíčně');
            if ($blocking === []) {
                $out[] = [
                    'check' => 'vat_reconciliation',
                    'label' => $label,
                    'ok' => true,
                    'a_label' => 'přiznání',
                    'a' => null,
                    'b_label' => 'KH / SH / 343',
                    'b' => null,
                    'difference' => null,
                    'note' => $findings === [] ? null : sprintf('%d informativních poznámek', count($findings)),
                    'skipped' => false,
                ];
                continue;
            }

            foreach ($blocking as $f) {
                $out[] = [
                    'check' => 'vat_reconciliation',
                    'label' => $label . ' — ' . (string) ($f['label'] ?? $f['check'] ?? ''),
                    'ok' => false,
                    'a_label' => 'přiznání',
                    'a' => isset($f['declared']) ? (float) $f['declared'] : null,
                    'b_label' => 'protistrana',
                    'b' => isset($f['counter']) ? (float) $f['counter'] : null,
                    'difference' => isset($f['difference']) ? (float) $f['difference'] : null,
                    'note' => $f['note'] ?? null,
                    'skipped' => false,
                ];
            }
        }

        return $out;
    }

    /**
     * @return array{check:string, label:string, ok:bool, a_label:string, a:?float,
     *               b_label:string, b:?float, difference:?float, note:?string, skipped:bool}
     */
    private function compare(string $check, string $label, string $aLabel, float $a, string $bLabel, float $b): array
    {
        $diff = round($a - $b, 2);

        return [
            'check' => $check,
            'label' => $label,
            'ok' => abs($diff) <= self::EPS,
            'a_label' => $aLabel,
            'a' => round($a, 2),
            'b_label' => $bLabel,
            'b' => round($b, 2),
            'difference' => $diff,
            'note' => null,
            'skipped' => false,
        ];
    }

    /**
     * @return array{check:string, label:string, ok:bool, a_label:string, a:?float,
     *               b_label:string, b:?float, difference:?float, note:?string, skipped:bool}
     */
    private function skip(string $check, string $label, string $reason): array
    {
        return [
            'check' => $check, 'label' => $label, 'ok' => true,
            'a_label' => '', 'a' => null, 'b_label' => '', 'b' => null,
            'difference' => null, 'note' => $reason, 'skipped' => true,
        ];
    }

    /**
     * Součet ze sestavy — a když klíč NEEXISTUJE, výjimka místo nuly.
     *
     * Tichá nula tu byla nejhorší možná varianta: `assets_total` ani `liabilities_total`
     * na nejvyšší úrovni rozvahy nikdy nebyly (leží v `checks` jako `assets_net`
     * a `liabilities_total`), takže kontrola „aktiva = pasiva" porovnávala 0,00 s 0,00
     * a hlásila OK i nad rozvahou v řádu milionů — kontrola by neselhala, ani kdyby
     * se rozvaha rozešla o desítky milionů.
     *
     * Stejnou pojistku má {@see profitFromIncomeStatement()}; právě tady chyběla.
     *
     * @param array<string,mixed> $statement
     */
    private function totalOf(array $statement, string $key): float
    {
        $value = $statement[$key] ?? $statement['checks'][$key] ?? null;
        if ($value === null) {
            throw new \RuntimeException(sprintf(
                'Rozvaha neobsahuje „%s" — křížová kontrola by jinak porovnávala nulu.',
                $key,
            ));
        }
        if (is_array($value)) {
            return (float) ($value['net'] ?? $value['amount'] ?? 0.0);
        }

        return (float) $value;
    }

    /**
     * VH z rozvahy (P.A.V. Výsledek hospodaření běžného účetního období).
     *
     * @param array<string,mixed> $balance
     */
    private function profitFromBalanceSheet(array $balance): float
    {
        foreach ((array) ($balance['liabilities'] ?? []) as $row) {
            if (str_starts_with((string) ($row['row_code'] ?? ''), 'P.A.V.')) {
                return (float) ($row['net'] ?? $row['amount'] ?? 0.0);
            }
        }
        throw new \RuntimeException(
            'Rozvaha nemá řádek P.A.V. (VH běžného období) — křížová kontrola by jinak '
                . 'porovnávala nulu a tvářila se, že hlídá.',
        );
    }

    /**
     * VH z výsledovky — řádek `VH` (computed, „Výsledek hospodaření za účetní období").
     *
     * Chybějící řádek je tvrdá chyba, ne nula: první verze téhle metody hledala kódy
     * `***`/`**` (konvence jiných systémů), nenašla nic a vracela 0,00. Kontrola pak
     * hlásila rozdíl v plné výši VH a vypadala jako účetní nález, přestože šlo o vadu
     * kontroly. Tichá nula je u křížové kontroly nejhorší možná odpověď.
     *
     * @param array<string,mixed> $income
     */
    private function profitFromIncomeStatement(array $income): float
    {
        foreach ((array) ($income['rows'] ?? []) as $row) {
            if ((string) ($row['row_code'] ?? '') === 'VH') {
                return (float) ($row['amount'] ?? 0.0);
            }
        }
        throw new \RuntimeException(
            'Výsledovka nemá řádek VH — křížová kontrola by jinak porovnávala nulu.',
        );
    }

    /**
     * VH nezávisle na výkazech: Σ výnosů (tř. 6) − Σ nákladů (tř. 5) přímo z deníku,
     * BEZ uzávěrkových zápisů (ty výsledkovky vynulují převodem na 710).
     */
    private function profitFromLedger(int $supplierId, string $from, string $to): float
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT ROUND(
                      SUM(CASE WHEN a.account_code LIKE '6%'
                               THEN (CASE WHEN l.side = 'credit' THEN l.amount ELSE -l.amount END)
                               ELSE 0 END)
                    - SUM(CASE WHEN a.account_code LIKE '5%'
                               THEN (CASE WHEN l.side = 'debit' THEN l.amount ELSE -l.amount END)
                               ELSE 0 END), 2)
               FROM journal_entries e
               JOIN journal_entry_lines l ON l.entry_id = e.id
               JOIN chart_of_accounts a ON a.id = l.account_id
              WHERE e.supplier_id = ?
                AND e.entry_date BETWEEN ? AND ?
                AND e.posted_at IS NOT NULL
                AND e.source_type <> 'closing'"
        );
        $stmt->execute([$supplierId, $from, $to]);

        return (float) ($stmt->fetchColumn() ?: 0.0);
    }

    /** @param array<string,mixed> $trialBalance */
    private function trialBalanceTotal(array $trialBalance, string $key): float
    {
        $totals = (array) ($trialBalance['totals'] ?? []);
        return (float) ($totals[$key] ?? 0.0);
    }
}
