<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Reports;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\LedgerReportRepository;
use MyInvoice\Repository\StatementDefinitionRepository;
use PDO;

/**
 * Rozvaha + výkaz zisku a ztráty (Epic F2, vyhl. 500/2002 Sb.):
 * zůstatky syntetik k rozvahovému dni (offbalance + closing vyloučeny, R2)
 * → mapa výkazu (StatementMapper: nejdelší prefix, balance_condition R8,
 * korekce gross − correction) → strom řádků (subtotal = Σ dětí + vlastní
 * mapované, computed = calc_key §1.2.4 + vlastní mapované). Minulé období
 * (R13) se počítá stejným během k ends_on předchozího období se STEJNOU
 * verzí mapování. Rozsah dle R12 (filtr level), scope='auto' přes
 * EntityCategoryService. Kontroly rovnosti v haléřích.
 */
final class FinancialStatementService
{
    /** VZZ v účelovém členění (vyhl. 500/2002 Sb., příloha č. 2 část II, § 39b). */
    public const TYPE_PURPOSE = 'income_statement_purpose';

    /**
     * Kódy řádků, které se ve výkazu ZOBRAZUJÍ jinak, než jsou vedené v datech.
     *
     * Ve výkazu se písmenné a římské označení tluče: v druhovém členění je „I." zároveň
     * Tržby i Úpravy hodnot ve finanční oblasti, v účelovém Tržby i Ostatní finanční
     * náklady. Kód musí být jedinečný (je klíčem mapy i vzorců), zobrazit se ale musí
     * tak, jak ho má vyhláška.
     */
    private const DISPLAY_CODE_ALIAS = [
        'I.n' => 'I.',
        'I.f' => 'I.',
    ];

    public function __construct(
        private readonly Connection $db,
        private readonly LedgerReportRepository $ledger,
        private readonly StatementDefinitionRepository $definitions,
        private readonly AccountingPeriodRepository $periods,
        private readonly EntityCategoryService $categories,
        private readonly StatementMapper $mapper,
    ) {}

    /**
     * @param 'full'|'small'|'micro'|'auto' $scope
     * @return array<string,mixed> struktura dle spec §2.7
     */
    public function balanceSheet(int $supplierId, int $periodId, ?string $asOf, string $scope): array
    {
        $ctx = $this->buildStatement('balance_sheet', $supplierId, $periodId, $asOf, $scope);

        // D1 (audit 2026-07, H8): §3a odst. 2 písm. b) vyhl. 500/2002 Sb. — zkrácená
        // rozvaha malé ÚJ obsahuje položky písmen a římských číslic (level ≤ 2) A NAVÍC
        // položky C.II.1. a C.II.2. (level 3), které se uvádějí vždy zvlášť jako výslovná
        // výjimka z pravidla „jen do úrovně 2". Whitelist je proto vedle max_level filtru.
        $smallExtraRows = $ctx['scope'] === 'small'
            ? ['C.II.1.' => true, 'C.II.2.' => true]
            : [];

        $assets = [];
        $liabilities = [];
        foreach ($ctx['rows'] as $r) {
            if ($ctx['max_level'] !== null && (int) $r['level'] > $ctx['max_level']
                && !isset($smallExtraRows[(string) $r['row_code']])) {
                continue;
            }
            $code = (string) $r['row_code'];
            $v  = $ctx['values'][$code] ?? ['gross' => 0.0, 'correction' => 0.0];
            $pv = $ctx['values_prev'][$code] ?? ['gross' => 0.0, 'correction' => 0.0];
            $base = [
                'row_code' => $code,
                'label'    => (string) $r['label'],
                'level'    => (int) $r['level'],
                'row_type' => (string) $r['row_type'],
            ];
            if ((string) $r['section'] === 'assets') {
                $assets[] = $base + [
                    'display_code' => $code,
                    'gross'        => $v['gross'],
                    'correction'   => $v['correction'],
                    'net'          => round($v['gross'] - $v['correction'], 2),
                    'prev_net'     => round($pv['gross'] - $pv['correction'], 2),
                    'accounts'     => $ctx['mapped'][$code]['accounts'] ?? [],
                ];
            } else {
                $liabilities[] = $base + [
                    'display_code' => str_starts_with($code, 'P.') ? substr($code, 2) : $code,
                    'amount'       => round($v['gross'] - $v['correction'], 2),
                    'prev_amount'  => round($pv['gross'] - $pv['correction'], 2),
                    'accounts'     => $ctx['mapped'][$code]['accounts'] ?? [],
                ];
            }
        }

        $assetsNet = $this->netOf($ctx['values'], 'AKTIVA');
        $liabilitiesTotal = $this->netOf($ctx['values'], 'PASIVA');
        $profitFromBalances = $this->profitFromBalances($ctx['balances']);
        $mapped431 = $ctx['mapped']['P.A.V.']['gross'] ?? 0.0;
        $profitInBalanceSheet = round($this->netOf($ctx['values'], 'P.A.V.') - $mapped431, 2);

        return [
            'statement_type' => 'balance_sheet',
            'version_code'   => (string) $ctx['version']['version_code'],
            'as_of'          => $ctx['as_of'],
            'scope'          => $ctx['scope'],
            'entity'         => $this->loadEntity($supplierId),
            'period'         => $this->periodOut($ctx['period']),
            'prev_period'    => $ctx['prev_period'] === null ? null : $this->periodOut($ctx['prev_period']),
            'assets'         => $assets,
            'liabilities'    => $liabilities,
            'checks'         => [
                'assets_net'        => $assetsNet,
                'liabilities_total' => $liabilitiesTotal,
                'balanced'          => self::cents($assetsNet) === self::cents($liabilitiesTotal),
                'profit_current'    => $profitFromBalances,
                'profit_matches'    => self::cents($profitInBalanceSheet) === self::cents($profitFromBalances),
                'unmapped_accounts' => $ctx['unmapped'],
            ],
        ];
    }

    /**
     * VZZ v ÚČELOVÉM členění — vyhláška 500/2002 Sb., příloha č. 2 část II (§ 39b).
     *
     * Náklady se nečlení podle druhu, ale podle FUNKCE: náklady prodeje (A.), odbytové
     * náklady (B.), správní režie (C.). To z čísla účtu odvodit nejde, přiřazení proto
     * pochází z per-firma mapy {@see StatementDefinitionRepository::functionMap()}.
     *
     * Když je přiřazení NEÚPLNÉ, výkaz se NESESTAVÍ. Nepřiřazený náklad by totiž do
     * žádného řádku nespadl, tiše vypadl z výkazu a hrubý zisk i výsledek hospodaření
     * by vyšly vyšší, než jaké jsou — a to na výkazu, který se podává. Chybová hláška
     * s výčtem účtů je jediná poctivá odpověď.
     *
     * @param 'full'|'small'|'micro'|'auto' $scope
     * @return array<string,mixed>
     */
    public function incomeStatementByFunction(int $supplierId, int $periodId, ?string $asOf, string $scope): array
    {
        $out = $this->incomeStatementFor(self::TYPE_PURPOSE, $supplierId, $periodId, $asOf, $scope);

        $unmapped = $out['checks']['unmapped_accounts'];
        if ($unmapped !== []) {
            $codes = array_map(static fn (array $a): string => (string) ($a['account_code'] ?? '?'), $unmapped);
            throw new ReportException('function_map_incomplete', sprintf(
                'Účelové členění nelze sestavit: %d účtům s obratem chybí přiřazení funkci '
                . '(náklady prodeje / odbytové náklady / správní režie) — %s. '
                . 'Doplňte přiřazení v mapě funkcí, jinak by tyto částky ve výkazu chyběly.',
                count($codes),
                implode(', ', array_slice($codes, 0, 20)) . (count($codes) > 20 ? ' …' : ''),
            ));
        }

        return $out;
    }

    /**
     * @param 'full'|'small'|'micro'|'auto' $scope
     * @return array<string,mixed> struktura dle spec §2.7
     */
    public function incomeStatement(int $supplierId, int $periodId, ?string $asOf, string $scope): array
    {
        return $this->incomeStatementFor('income_statement', $supplierId, $periodId, $asOf, $scope);
    }

    /**
     * Společné jádro obou variant VZZ — liší se jen verzí mapování a vzorci computed
     * řádků, výstupní struktura je shodná.
     *
     * @param 'income_statement'|'income_statement_purpose' $type
     * @param 'full'|'small'|'micro'|'auto' $scope
     * @return array<string,mixed>
     */
    private function incomeStatementFor(string $type, int $supplierId, int $periodId, ?string $asOf, string $scope): array
    {
        $ctx = $this->buildStatement($type, $supplierId, $periodId, $asOf, $scope);

        $rows = [];
        foreach ($ctx['rows'] as $r) {
            if ($ctx['max_level'] !== null && (int) $r['level'] > $ctx['max_level']) {
                continue;
            }
            $code = (string) $r['row_code'];
            $v  = $ctx['values'][$code] ?? ['gross' => 0.0, 'correction' => 0.0];
            $pv = $ctx['values_prev'][$code] ?? ['gross' => 0.0, 'correction' => 0.0];
            $rows[] = [
                'row_code'     => $code,
                'display_code' => self::DISPLAY_CODE_ALIAS[$code] ?? $code,
                'label'        => (string) $r['label'],
                'level'        => (int) $r['level'],
                'row_type'     => (string) $r['row_type'],
                'amount'       => round($v['gross'] - $v['correction'], 2),
                'prev_amount'  => round($pv['gross'] - $pv['correction'], 2),
                'accounts'     => $ctx['mapped'][$code]['accounts'] ?? [],
            ];
        }

        $profitCurrent = $this->netOf($ctx['values'], 'VH');
        $profitFromBalances = $this->profitFromBalances($ctx['balances']);

        return [
            'statement_type' => $type,
            'version_code'   => (string) $ctx['version']['version_code'],
            'as_of'          => $ctx['as_of'],
            'scope'          => $ctx['scope'],
            'entity'         => $this->loadEntity($supplierId),
            'period'         => $this->periodOut($ctx['period']),
            'prev_period'    => $ctx['prev_period'] === null ? null : $this->periodOut($ctx['prev_period']),
            'rows'           => $rows,
            'checks'         => [
                'profit_current' => $profitCurrent,
                'net_turnover'   => $this->netOf($ctx['values'], 'OBRAT'),
                'profit_matches' => self::cents($profitCurrent) === self::cents($profitFromBalances),
                'unmapped_accounts' => $ctx['unmapped'],
            ],
        ];
    }

    // ── společné jádro ─────────────────────────────────────────────────────────

    /**
     * @param 'balance_sheet'|'income_statement'|'income_statement_purpose' $type
     * @return array<string,mixed>
     */
    private function buildStatement(string $type, int $supplierId, int $periodId, ?string $asOf, string $scope): array
    {
        $period = $this->periods->findById($supplierId, $periodId);
        if ($period === null) {
            throw new ReportException('period_not_found', 'Účetní období #' . $periodId . ' neexistuje.', 404);
        }
        if ($asOf === null || $asOf === '') {
            $asOf = min((string) $period['ends_on'], date('Y-m-d'));
        }

        $version = $this->definitions->findVersion($type, $asOf);
        if ($version === null) {
            throw new ReportException('statement_version_missing', 'Pro rozvahový den ' . $asOf . ' neexistuje verze mapování výkazu.');
        }
        $rows = $this->definitions->rows((int) $version['id']);
        $baseMap = $this->definitions->accountMap((int) $version['id']);
        if ($type === self::TYPE_PURPOSE) {
            // Řádky A./B./C. globální mapu nemají — funkce, které náklad slouží, není
            // vlastnost účtu. Doplní se z per-firma mapy a dál se s ní zachází stejně,
            // takže platí i pravidlo nejdelšího prefixu (analytiky 518.100 / 518.200).
            $baseMap = array_merge($baseMap, $this->definitions->functionMap($supplierId));
        }
        $map = $baseMap;
        if ($type === 'balance_sheet' && $asOf < (string) $period['ends_on']) {
            foreach ($map as &$mapping) {
                if ((string) $mapping['account_prefix'] === '431') {
                    $mapping['row_code'] = 'P.A.IV.1.';
                }
            }
            unset($mapping);
        }
        $splitCodes = $this->mapper->noCompensationPrefixes($map);
        $analyticPrefixes = $this->mapper->analyticPrefixes($map);

        $balances = $this->ledger->syntheticBalances(
            $supplierId,
            $asOf,
            (string) $period['starts_on'],
            $splitCodes,
            $analyticPrefixes,
        );
        $mapped   = $this->mapper->map($rows, $map, $balances);
        $unmapped = $this->mapper->unmappedBalances(
            $map,
            $balances,
            $type === 'balance_sheet' ? ['asset', 'liability', 'equity'] : ['revenue', 'expense'],
        );
        $values   = $this->computeValues($rows, $mapped, $balances, $type);

        // R13: minulé období stejným během k ends_on předchozího období, stejná verze.
        $prevPeriod = $this->ledger->previousPeriod($supplierId, (string) $period['starts_on']);
        $valuesPrev = [];
        if ($prevPeriod !== null) {
            $balancesPrev = $this->ledger->syntheticBalances(
                $supplierId,
                (string) $prevPeriod['ends_on'],
                (string) $prevPeriod['starts_on'],
                $splitCodes,
                $this->mapper->analyticPrefixes($baseMap),
            );
            $mappedPrev = $this->mapper->map($rows, $baseMap, $balancesPrev);
            $valuesPrev = $this->computeValues($rows, $mappedPrev, $balancesPrev, $type);
        }

        $resolvedScope = $scope === 'auto' ? $this->categories->statementScope($supplierId, $periodId) : $scope;
        // R12: rozvaha mikro = level ≤ 1, malá = level ≤ 2; VZZ zkrácená (mikro i malá) = level ≤ 1.
        $maxLevel = match (true) {
            $resolvedScope === 'micro'                              => 1,
            $resolvedScope === 'small' && $type === 'balance_sheet' => 2,
            $resolvedScope === 'small'                              => 1,
            default                                                 => null,
        };

        return [
            'period'      => $period,
            'prev_period' => $prevPeriod,
            'version'     => $version,
            'as_of'       => $asOf,
            'scope'       => $resolvedScope,
            'max_level'   => $maxLevel,
            'rows'        => $rows,
            'mapped'      => $mapped,
            'balances'    => $balances,
            'unmapped'    => $unmapped,
            'values'      => $values,
            'values_prev' => $valuesPrev,
        ];
    }

    /**
     * Hodnoty všech řádků výkazu: detail = vlastní mapované; subtotal = Σ dětí
     * + vlastní mapované (korekce 091→B.I. apod.); computed = calc_key (§1.2.4)
     * + vlastní mapované (P.A.V. = profit_current + saldo 431).
     *
     * @param list<array<string,mixed>> $rows
     * @param array<string, array{gross: float, correction: float, accounts: list<array<string,mixed>>}> $mapped
     * @param list<array<string,mixed>> $balances
     * @return array<string, array{gross: float, correction: float}>
     */
    private function computeValues(array $rows, array $mapped, array $balances, string $type): array
    {
        $byCode = [];
        $children = [];
        foreach ($rows as $r) {
            $byCode[(string) $r['row_code']] = $r;
        }
        foreach ($rows as $r) {
            $parent = $r['parent_row_code'];
            if ($parent !== null && $parent !== '') {
                $children[(string) $parent][] = (string) $r['row_code'];
            }
        }

        $memo = [];
        $resolve = null;
        $net = function (string $code) use (&$resolve): float {
            $v = $resolve($code);
            return round($v['gross'] - $v['correction'], 2);
        };
        $resolve = function (string $code) use (&$resolve, &$memo, $byCode, $children, $mapped, $balances, $type, $net): array {
            if (isset($memo[$code])) {
                return $memo[$code];
            }
            $row = $byCode[$code] ?? null;
            if ($row === null) {
                return ['gross' => 0.0, 'correction' => 0.0];
            }
            $gross = $mapped[$code]['gross'] ?? 0.0;
            $correction = $mapped[$code]['correction'] ?? 0.0;
            if ((string) $row['row_type'] === 'subtotal') {
                foreach ($children[$code] ?? [] as $child) {
                    $cv = $resolve($child);
                    $gross += $cv['gross'];
                    $correction += $cv['correction'];
                }
            } elseif ((string) $row['row_type'] === 'computed') {
                $gross += $this->calcValue((string) $row['calc_key'], $type, $net, $balances);
            }
            return $memo[$code] = ['gross' => round($gross, 2), 'correction' => round($correction, 2)];
        };

        foreach ($byCode as $code => $_) {
            $resolve((string) $code);
        }
        return $memo;
    }

    /**
     * Vzorce computed řádků (§1.2.4). V rozvaze je jediný calc_key profit_current
     * (P.A.V.) — VH za fiskální rok přímo z výsledkových účtů (každý účet 5xx/6xx
     * je v mapě VZZ právě jednou, hodnoty jsou ekvivalentní vzorci VZZ).
     *
     * @param callable(string): float $net netto hodnota řádku dle row_code
     * @param list<array<string,mixed>> $balances
     */
    private function calcValue(string $key, string $type, callable $net, array $balances): float
    {
        if ($type === 'balance_sheet') {
            return $key === 'profit_current' ? $this->profitFromBalances($balances) : 0.0;
        }
        // Účelové členění má vlastní kódy řádků (A. je náklad prodeje, ne výkonová
        // spotřeba), takže vzorce druhového by tu sečetly něco úplně jiného.
        if ($type === self::TYPE_PURPOSE) {
            return round(match ($key) {
                'gross_profit'      => $net('I.') - $net('A.'),
                'operating_profit'  => $this->calcValue('gross_profit', $type, $net, $balances)
                                     - $net('B.') - $net('C.') + $net('II.') - $net('D.'),
                'financial_profit'  => $net('III.') - $net('E.') + $net('IV.') - $net('F.')
                                     + $net('V.') - $net('G.') - $net('H.') + $net('VI.') - $net('I.f'),
                'profit_before_tax' => $this->calcValue('operating_profit', $type, $net, $balances)
                                     + $this->calcValue('financial_profit', $type, $net, $balances),
                'profit_after_tax'  => $this->calcValue('profit_before_tax', $type, $net, $balances) - $net('J.'),
                'profit_current'    => $this->calcValue('profit_after_tax', $type, $net, $balances) - $net('K.'),
                'net_turnover'      => $net('I.') + $net('II.') + $net('III.') + $net('IV.')
                                     + $net('V.') + $net('VI.'),
                default             => 0.0,
            }, 2);
        }

        return round(match ($key) {
            'operating_profit'  => $net('I.') + $net('II.') - $net('A.') - $net('B.') - $net('C.')
                                 - $net('D.') - $net('E.') + $net('III.') - $net('F.'),
            'financial_profit'  => $net('IV.') - $net('G.') + $net('V.') - $net('H.') + $net('VI.')
                                 - $net('I.n') - $net('J.') + $net('VII.') - $net('K.'),
            'profit_before_tax' => $this->calcValue('operating_profit', $type, $net, $balances)
                                 + $this->calcValue('financial_profit', $type, $net, $balances),
            'profit_after_tax'  => $this->calcValue('profit_before_tax', $type, $net, $balances) - $net('L.'),
            'profit_current'    => $this->calcValue('profit_after_tax', $type, $net, $balances) - $net('M.'),
            'net_turnover'      => $net('I.') + $net('II.') + $net('III.') + $net('IV.') + $net('V.')
                                 + $net('VI.') + $net('VII.'),
            default             => 0.0,
        }, 2);
    }

    /**
     * VH za období z výsledkových účtů: výnosy − náklady = Σ(D − MD) přes
     * revenue i expense (zůstatky už jsou omezené na fiskální rok přes plFrom).
     *
     * @param list<array<string,mixed>> $balances
     */
    private function profitFromBalances(array $balances): float
    {
        $profit = 0.0;
        foreach ($balances as $b) {
            $accountType = (string) $b['account_type'];
            if ($accountType === 'revenue' || $accountType === 'expense') {
                $profit += (float) $b['d'] - (float) $b['md'];
            }
        }
        return round($profit, 2);
    }

    /**
     * @param array<string, array{gross: float, correction: float}> $values
     */
    private function netOf(array $values, string $rowCode): float
    {
        $v = $values[$rowCode] ?? ['gross' => 0.0, 'correction' => 0.0];
        return round($v['gross'] - $v['correction'], 2);
    }

    /**
     * Hlavička účetní jednotky pro výkaz — veřejný přístup k {@see loadEntity()}.
     *
     * Potřebují ji i přehledy podle § 18/2, které staví jiné služby. Přikládají se
     * k závěrce, takže bez názvu a IČ nejsou k ničemu; druhá kopie téhle metody by
     * se s touhle dřív nebo později rozešla.
     *
     * @return array{name: string, ico: ?string, address: string, legal_form: null, prepared_at: string}
     */
    public function entityHeader(int $supplierId): array
    {
        return $this->loadEntity($supplierId);
    }

    /**
     * Náležitosti výkazu dle §18 ZoÚ — stejné supplier sloupce jako DphBookBuilder.
     *
     * @return array{name: string, ico: ?string, address: string, legal_form: null, prepared_at: string}
     */
    private function loadEntity(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT s.company_name, s.street, s.city, s.zip, s.ic
               FROM supplier s
              WHERE s.id = ?'
        );
        $stmt->execute([$supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new ReportException('supplier_not_found', 'Firma #' . $supplierId . ' neexistuje.', 404);
        }
        $address = array_filter([
            trim((string) ($row['street'] ?? '')),
            trim(trim((string) ($row['zip'] ?? '')) . ' ' . trim((string) ($row['city'] ?? ''))),
        ], static fn (string $part): bool => $part !== '');
        return [
            'name'        => (string) $row['company_name'],
            'ico'         => $row['ic'],
            'address'     => implode(', ', $address),
            'legal_form'  => null,
            'prepared_at' => date('Y-m-d H:i'),
        ];
    }

    /**
     * @param array<string,mixed> $period
     * @return array{id: int, fiscal_year: int, starts_on: string, ends_on: string}
     */
    private function periodOut(array $period): array
    {
        return [
            'id'          => (int) $period['id'],
            'fiscal_year' => (int) $period['fiscal_year'],
            'starts_on'   => (string) $period['starts_on'],
            'ends_on'     => (string) $period['ends_on'],
        ];
    }

    private static function cents(float|int|string|null $amount): int
    {
        return (int) round(((float) $amount) * 100.0);
    }
}
