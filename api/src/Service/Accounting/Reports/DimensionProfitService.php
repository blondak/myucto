<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Reports;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\DimensionRepository;
use MyInvoice\Service\Accounting\Closing\ClosingSourceId;
use MyInvoice\Service\Accounting\Dimension\DimensionSplitAllocation;
use MyInvoice\Service\Tax\Return\JournalTaxOrigin;
use PDO;

/**
 * Výsledovka po dimenzi: řádky = hodnoty jednoho typu (strom), sloupce = výnosy
 * (třída 6), náklady (třída 5) a výsledek. Počítá se z deníku, ze zaúčtovaných
 * zápisů bez uzávěrkového převodu výsledkových účtů (jako výkazy).
 *
 * Hodnota ve stromu má vlastní částky (řádky přímo s ní) a souhrn za celou větev.
 * Řádky bez hodnoty daného typu tvoří řádek „bez hodnoty", takže součet sestavy
 * sedí na výsledek hospodaření firmy za období. Řádek s rozpadem mezi víc hodnot
 * se rozdělí po haléřích ({@see DimensionSplitAllocation}), stejně jako ve výkazech
 * filtrovaných na hodnotu.
 *
 * Výběr kořenů sestavy:
 *   • bez omezení = hodnoty nejvyšší úrovně + „bez hodnoty" (součet = VH firmy),
 *   • `value_id` = jen větev jedné hodnoty (účelová výsledovka projektu a jeho etap),
 *   • `responsible_user_id` = hodnoty s touto odpovědnou osobou včetně jejich větví;
 *     hodnota ležící ve větvi jiné vybrané hodnoty se nesčítá podruhé.
 * S omezením se řádek „bez hodnoty" nevykazuje a součet je součtem kořenů.
 *
 * `accounts` přidá rozpad po syntetických účtech: řádky = účty, sloupce = kořeny
 * (a „bez hodnoty"), buňka = částka větve kořene na účtu.
 *
 * Globální typ lze sečíst za víc firem skupiny najednou (projekt vedený přes
 * mateřskou firmu i SPV); které firmy smí do součtu, určuje volající.
 */
final class DimensionProfitService
{
    public function __construct(
        private readonly Connection $db,
        private readonly DimensionRepository $dimensions,
    ) {}

    /**
     * @param list<int> $supplierIds firmy do součtu (první je aktuální firma)
     * @param array{value_id?:?int, responsible_user_id?:?int, accounts?:bool} $options
     * @return array<string,mixed>
     */
    public function build(int $supplierId, int $typeId, string $from, string $to, array $supplierIds, array $options = []): array
    {
        $type = $this->dimensions->findType($supplierId, $typeId);
        if ($type === null) {
            throw new ReportException('not_found', 'Typ dimenze nenalezen.', 404);
        }
        $values = $this->dimensions->listValues($supplierId, $typeId);
        $rootValueId = (int) ($options['value_id'] ?? 0);
        $responsible = (int) ($options['responsible_user_id'] ?? 0);
        $withAccounts = (bool) ($options['accounts'] ?? false);

        // hodnota ('' = bez hodnoty) => syntetický účet => ['revenue' => haléře, 'cost' => haléře]
        $direct = [];
        $accounts = [];
        foreach ($supplierIds as $sid) {
            foreach ($this->sums($sid, $typeId, $from, $to) as $row) {
                $key = $row['value_key'];
                $code = $row['code'];
                $direct[$key][$code]['revenue'] = ($direct[$key][$code]['revenue'] ?? 0) + $row['revenue'];
                $direct[$key][$code]['cost'] = ($direct[$key][$code]['cost'] ?? 0) + $row['cost'];
                $accounts[$code] ??= ['code' => $code, 'name' => $row['name'], 'account_type' => $row['account_type']];
            }
        }

        $known = [];
        foreach ($values as $v) {
            $known[$v['id']] = $v;
        }
        // Řádky s hodnotou, kterou firma nevidí (firma mezitím skupinu opustila),
        // se nesmí ztratit — patří k řádku „bez hodnoty".
        foreach (array_keys($direct) as $key) {
            if ($key !== '' && !isset($known[(int) $key])) {
                foreach ($direct[$key] as $code => $sum) {
                    $direct[''][$code]['revenue'] = ($direct[''][$code]['revenue'] ?? 0) + $sum['revenue'];
                    $direct[''][$code]['cost'] = ($direct[''][$code]['cost'] ?? 0) + $sum['cost'];
                }
                unset($direct[$key]);
            }
        }

        $byParent = [];
        foreach ($values as $v) {
            $parent = $v['parent_id'] !== null && isset($known[$v['parent_id']]) ? $v['parent_id'] : 0;
            $byParent[$parent][] = $v;
        }

        if ($rootValueId > 0) {
            if (!isset($known[$rootValueId])) {
                throw new ReportException('not_found', 'Hodnota dimenze nenalezena.', 404);
            }
            $roots = [$known[$rootValueId]];
        } elseif ($responsible > 0) {
            $roots = self::responsibleRoots($values, $known, $responsible);
        } else {
            $roots = $byParent[0] ?? [];
        }
        $restricted = $rootValueId > 0 || $responsible > 0;

        $rows = [];
        $rootTotals = [];
        $walk = function (array $value, int $depth) use (&$walk, &$rows, $byParent, $direct): array {
            $index = count($rows);
            $rows[] = null;
            $own = $direct[(string) $value['id']] ?? [];
            $total = $own;
            foreach ($byParent[$value['id']] ?? [] as $child) {
                $total = self::addAccounts($total, $walk($child, $depth + 1));
            }
            $rows[$index] = [
                'value_id' => $value['id'],
                'parent_id' => $value['parent_id'],
                'code' => $value['code'],
                'name' => $value['name'],
                'is_active' => $value['is_active'],
                'responsible_user_id' => $value['responsible_user_id'] ?? null,
                'responsible_user_name' => $value['responsible_user_name'] ?? null,
                'depth' => $depth,
                'has_children' => isset($byParent[$value['id']]),
                'own' => self::money(self::collapse($own)),
                'total' => self::money(self::collapse($total)),
            ];
            return $total;
        };
        foreach ($roots as $root) {
            $rootTotals[$root['id']] = $walk($root, 0);
        }

        $unassigned = $restricted ? [] : ($direct[''] ?? []);
        $all = $unassigned;
        foreach ($rootTotals as $t) {
            $all = self::addAccounts($all, $t);
        }

        $out = [
            'type' => $type,
            'from' => $from,
            'to' => $to,
            'supplier_ids' => $supplierIds,
            'value_id' => $rootValueId > 0 ? $rootValueId : null,
            'responsible_user_id' => $responsible > 0 ? $responsible : null,
            'restricted' => $restricted,
            'rows' => $rows,
            'unassigned' => self::money(self::collapse($unassigned)),
            'totals' => self::money(self::collapse($all)),
        ];
        if ($withAccounts) {
            $out['matrix'] = self::matrix($accounts, $roots, $rootTotals, $restricted ? null : $unassigned, $all);
        }
        return $out;
    }

    /**
     * Hodnoty s odpovědnou osobou, které neleží ve větvi jiné takové hodnoty.
     *
     * @param list<array<string,mixed>> $values
     * @param array<int,array<string,mixed>> $known
     * @return list<array<string,mixed>>
     */
    private static function responsibleRoots(array $values, array $known, int $userId): array
    {
        $matched = [];
        foreach ($values as $v) {
            if ((int) ($v['responsible_user_id'] ?? 0) === $userId) {
                $matched[$v['id']] = true;
            }
        }
        $roots = [];
        foreach ($values as $v) {
            if (!isset($matched[$v['id']])) {
                continue;
            }
            $parent = $v['parent_id'];
            $seen = [];
            while ($parent !== null && isset($known[$parent]) && !isset($seen[$parent])) {
                if (isset($matched[$parent])) {
                    continue 2;
                }
                $seen[$parent] = true;
                $parent = $known[$parent]['parent_id'];
            }
            $roots[] = $v;
        }
        return $roots;
    }

    /**
     * @param array<string,array{code:string,name:string,account_type:string}> $accounts
     * @param list<array<string,mixed>> $roots
     * @param array<int,array<string,array{revenue:int,cost:int}>> $rootTotals
     * @param array<string,array{revenue:int,cost:int}>|null $unassigned
     * @param array<string,array{revenue:int,cost:int}> $all
     * @return array<string,mixed>
     */
    private static function matrix(array $accounts, array $roots, array $rootTotals, ?array $unassigned, array $all): array
    {
        $columns = [];
        foreach ($roots as $root) {
            $columns[] = ['key' => (string) $root['id'], 'value_id' => $root['id'], 'code' => $root['code'], 'name' => $root['name']];
        }
        if ($unassigned !== null) {
            $columns[] = ['key' => '', 'value_id' => null, 'code' => '', 'name' => null];
        }
        uasort($accounts, static function (array $a, array $b): int {
            // Výnosy nahoře, pak náklady; uvnitř podle čísla účtu.
            $order = ($a['account_type'] === 'revenue' ? 0 : 1) <=> ($b['account_type'] === 'revenue' ? 0 : 1);
            return $order !== 0 ? $order : strcmp($a['code'], $b['code']);
        });
        $amount = static function (?array $sum, string $type): float {
            if ($sum === null) {
                return 0.0;
            }
            return ($type === 'revenue' ? $sum['revenue'] : $sum['cost']) / 100;
        };
        $rows = [];
        foreach ($accounts as $code => $acc) {
            $code = (string) $code;
            $cells = [];
            $nonZero = false;
            foreach ($roots as $root) {
                $cells[] = $v = $amount($rootTotals[$root['id']][$code] ?? null, $acc['account_type']);
                $nonZero = $nonZero || $v !== 0.0;
            }
            if ($unassigned !== null) {
                $cells[] = $v = $amount($unassigned[$code] ?? null, $acc['account_type']);
                $nonZero = $nonZero || $v !== 0.0;
            }
            if (!$nonZero) {
                continue;
            }
            $rows[] = [
                'code' => $code,
                'name' => $acc['name'],
                'account_type' => $acc['account_type'],
                'cells' => $cells,
                'total' => $amount($all[$code] ?? null, $acc['account_type']),
            ];
        }
        $results = [];
        foreach ($roots as $root) {
            $results[] = self::money(self::collapse($rootTotals[$root['id']]))['result'];
        }
        if ($unassigned !== null) {
            $results[] = self::money(self::collapse($unassigned))['result'];
        }
        return [
            'columns' => $columns,
            'rows' => $rows,
            'results' => $results,
            'total_result' => self::money(self::collapse($all))['result'],
        ];
    }

    /**
     * Součty v haléřích podle hodnoty ('' = řádek bez hodnoty) a syntetického účtu.
     *
     * @return list<array{value_key:string, code:string, name:string, account_type:string, revenue:int, cost:int}>
     */
    private function sums(int $supplierId, int $typeId, string $from, string $to): array
    {
        $stmt = $this->db->pdo()->prepare(
            'WITH RECURSIVE ' . JournalTaxOrigin::cte($supplierId) . "
            SELECT COALESCE(jd.dimension_value_id, ccv.id) AS value_id,
                   COALESCE(p.account_code, a.account_code) AS code,
                   COALESCE(p.name, a.name) AS name,
                   a.account_type,
                   SUM(CASE WHEN a.account_type = 'revenue'
                            THEN CASE WHEN l.side = 'credit' THEN l.amount ELSE -l.amount END ELSE 0 END) AS revenue,
                   SUM(CASE WHEN a.account_type = 'expense'
                            THEN CASE WHEN l.side = 'debit' THEN l.amount ELSE -l.amount END ELSE 0 END) AS cost
              FROM journal_entry_lines l
              JOIN journal_entries e ON e.id = l.entry_id
              " . JournalTaxOrigin::join() . "
              JOIN chart_of_accounts a ON a.id = l.account_id
         LEFT JOIN chart_of_accounts p ON p.id = a.parent_id
         LEFT JOIN journal_entry_line_dimensions jd
                ON jd.line_id = l.id AND jd.dimension_type_id = ?
         LEFT JOIN cost_centers cc
                ON jd.line_id IS NULL AND l.cost_center IS NOT NULL
               AND cc.supplier_id = l.supplier_id AND cc.code = l.cost_center
         LEFT JOIN dimension_values ccv ON ccv.cost_center_id = cc.id AND ccv.type_id = ?
             WHERE l.supplier_id = ? AND e.posted_at IS NOT NULL
               AND e.entry_date BETWEEN ? AND ?
               AND a.account_type IN ('revenue', 'expense')
               AND NOT EXISTS (SELECT 1 FROM journal_entry_line_dimension_splits s
                                WHERE s.line_id = l.id AND s.dimension_type_id = ?)
               AND " . JournalTaxOrigin::includedSql() . '
             GROUP BY COALESCE(jd.dimension_value_id, ccv.id), COALESCE(p.account_code, a.account_code),
                      COALESCE(p.name, a.name), a.account_type'
        );
        $stmt->execute([$typeId, $typeId, $supplierId, $from, $to, $typeId, ClosingSourceId::STOCK_SLOT_BASE]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[] = self::sumRow($r);
        }
        return [...$out, ...$this->splitSums($supplierId, $typeId, $from, $to)];
    }

    /**
     * Řádky s rozpadem mezi víc hodnot typu: díl každé hodnoty z téhož pravidla jako
     * filtr výkazů ({@see DimensionSplitAllocation}), takže součet sestavy dál sedí
     * na výsledek firmy a řádek hodnoty na výkaz filtrovaný na tutéž hodnotu.
     *
     * @return list<array{value_key:string, code:string, name:string, account_type:string, revenue:int, cost:int}>
     */
    private function splitSums(int $supplierId, int $typeId, string $from, string $to): array
    {
        [$partsSql, $partsParams] = DimensionSplitAllocation::partsSql($typeId, null, $supplierId);
        $stmt = $this->db->pdo()->prepare(
            'WITH RECURSIVE ' . JournalTaxOrigin::cte($supplierId) . "
            SELECT sp.value_id,
                   COALESCE(p.account_code, a.account_code) AS code,
                   COALESCE(p.name, a.name) AS name,
                   a.account_type,
                   SUM(CASE WHEN a.account_type = 'revenue'
                            THEN CASE WHEN l.side = 'credit' THEN sp.amount ELSE -sp.amount END ELSE 0 END) AS revenue,
                   SUM(CASE WHEN a.account_type = 'expense'
                            THEN CASE WHEN l.side = 'debit' THEN sp.amount ELSE -sp.amount END ELSE 0 END) AS cost
              FROM ({$partsSql}) sp
              JOIN journal_entry_lines l ON l.id = sp.line_id
              JOIN journal_entries e ON e.id = l.entry_id
              " . JournalTaxOrigin::join() . "
              JOIN chart_of_accounts a ON a.id = l.account_id
         LEFT JOIN chart_of_accounts p ON p.id = a.parent_id
             WHERE l.supplier_id = ? AND e.posted_at IS NOT NULL
               AND e.entry_date BETWEEN ? AND ?
               AND a.account_type IN ('revenue', 'expense')
               AND " . JournalTaxOrigin::includedSql() . '
             GROUP BY sp.value_id, COALESCE(p.account_code, a.account_code), COALESCE(p.name, a.name), a.account_type'
        );
        $stmt->execute([...$partsParams, $supplierId, $from, $to, ClosingSourceId::STOCK_SLOT_BASE]);
        return array_map([self::class, 'sumRow'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * @param array<string,mixed> $r
     * @return array{value_key:string, code:string, name:string, account_type:string, revenue:int, cost:int}
     */
    private static function sumRow(array $r): array
    {
        return [
            'value_key' => $r['value_id'] === null ? '' : (string) (int) $r['value_id'],
            'code' => (string) $r['code'],
            'name' => (string) $r['name'],
            'account_type' => (string) $r['account_type'],
            'revenue' => (int) round(((float) $r['revenue']) * 100),
            'cost' => (int) round(((float) $r['cost']) * 100),
        ];
    }

    /**
     * @param array<string,array{revenue:int,cost:int}> $a
     * @param array<string,array{revenue:int,cost:int}> $b
     * @return array<string,array{revenue:int,cost:int}>
     */
    private static function addAccounts(array $a, array $b): array
    {
        foreach ($b as $code => $sum) {
            $a[$code]['revenue'] = ($a[$code]['revenue'] ?? 0) + $sum['revenue'];
            $a[$code]['cost'] = ($a[$code]['cost'] ?? 0) + $sum['cost'];
        }
        return $a;
    }

    /**
     * @param array<string,array{revenue:int,cost:int}> $byAccount
     * @return array{revenue:int,cost:int}
     */
    private static function collapse(array $byAccount): array
    {
        $sum = ['revenue' => 0, 'cost' => 0];
        foreach ($byAccount as $s) {
            $sum['revenue'] += $s['revenue'];
            $sum['cost'] += $s['cost'];
        }
        return $sum;
    }

    /**
     * @param array{revenue:int,cost:int} $cents
     * @return array{revenue:float,cost:float,result:float}
     */
    private static function money(array $cents): array
    {
        return [
            'revenue' => $cents['revenue'] / 100,
            'cost' => $cents['cost'] / 100,
            'result' => ($cents['revenue'] - $cents['cost']) / 100,
        ];
    }
}
