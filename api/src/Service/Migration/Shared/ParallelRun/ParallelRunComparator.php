<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Shared\ParallelRun;

use MyInvoice\Service\Migration\Shared\ReconciliationTolerance;
use MyInvoice\Service\Migration\Shared\TrialBalanceReconciliation;

/**
 * Porovnání MyÚčta se starým programem po kritériích K1 a K5–K13. Čisté funkce nad
 * daty: co je „MyÚčto", sestaví {@see ParallelRunReconciliation} ze sestav aplikace,
 * co je „zdroj", přečte adaptér programu ({@see SourceSnapshot}).
 *
 * Výsledek kritéria:
 *   key, status (ok | differences | error), tolerance, summary, differences, difference_count
 * Rozdíl: id (stálé v rámci měsíce — na něj se váže zařazení účetní), subject, label,
 * values (pole, MyÚčto, zdroj), note (strojový důvod), link (doklad v MyÚčtu).
 */
final class ParallelRunComparator
{
    /** Kolik rozdílů jednoho kritéria se uloží; zbytek jen spočítá. */
    public const MAX_DIFFERENCES = 500;

    public const TOLERANCE_CENT = 'cent';
    public const TOLERANCE_CROWN = 'crown';
    public const TOLERANCE_COUNT = 'count';

    /** Tolerance cizoměnového zůstatku: 0,01 v měně účtu. */
    public const FOREIGN_TOLERANCE = 0.01;

    /**
     * K1 — obratová předvaha k poslednímu dni měsíce po syntetických účtech (PS, obrat, KS).
     *
     * @param array<string,array{0:float,1:float,2:float}> $mine
     * @param array<string,array{0:float,1:float,2:float}> $theirs
     */
    public static function trialBalance(array $mine, array $theirs): array
    {
        $diffs = [];
        foreach (TrialBalanceReconciliation::compare($mine, $theirs) as $d) {
            $diffs[] = self::diff('K1:' . $d['account'], $d['account'], null, [
                ['field' => 'opening', 'mine' => $d['myucto'][0], 'theirs' => $d['money'][0]],
                ['field' => 'turnover', 'mine' => $d['myucto'][1], 'theirs' => $d['money'][1]],
                ['field' => 'closing', 'mine' => $d['myucto'][2], 'theirs' => $d['money'][2]],
            ], isset($mine[$d['account']]) ? (isset($theirs[$d['account']]) ? null : 'missing_in_source') : 'missing_in_myucto');
        }
        return self::result('K1', self::TOLERANCE_CENT, $diffs, [
            'accounts_myucto' => count($mine),
            'accounts_source' => count($theirs),
        ]);
    }

    /**
     * K5 — počty dokladů měsíce po knihách. Porovnávají se jen knihy, které zdroj uvádí.
     *
     * @param array<string,int> $mine
     * @param array<string,int> $theirs
     */
    public static function documentCounts(array $mine, array $theirs): array
    {
        $diffs = [];
        $rows = [];
        foreach (ParallelRunInput::BOOKS as $book) {
            if (!array_key_exists($book, $theirs)) {
                continue;
            }
            $m = (int) ($mine[$book] ?? 0);
            $t = (int) $theirs[$book];
            $rows[$book] = ['myucto' => $m, 'source' => $t];
            if ($m !== $t) {
                $diffs[] = self::diff('K5:' . $book, $book, null, [['field' => 'count', 'mine' => (float) $m, 'theirs' => (float) $t]]);
            }
        }
        return self::result('K5', self::TOLERANCE_COUNT, $diffs, ['books' => $rows]);
    }

    /**
     * K6 (saldokonto po účtech a partnerech) a K7 (úhrady po dokladech).
     *
     * @param list<array{account:string,doc_no:string,alt_doc_no?:?string,doc_type:string,doc_id:int,partner:string,remaining:float}> $mine otevřené položky MyÚčta
     * @param list<array{account:?string,document:string,partner:string,ico:string,amount:float}> $theirs otevřené položky zdroje
     * @return array{0:array<string,mixed>,1:array<string,mixed>} [K6, K7]
     */
    public static function saldo(array $mine, array $theirs): array
    {
        $theirAccounts = [];
        $withPartner = false;
        foreach ($theirs as $t) {
            if ($t['account'] !== null) {
                $theirAccounts[$t['account']] = true;
            }
            $withPartner = $withPartner || trim($t['partner']) !== '';
        }
        // Zdroj bez sloupce účtu porovnává všechny saldokontní účty najednou.
        $accountOf = static fn (?string $a): string => $theirAccounts === [] ? '*' : (string) $a;

        $k6 = [];
        $totals = [];
        $partners = [];
        foreach ($mine as $m) {
            $acc = $accountOf($m['account']);
            if ($theirAccounts !== [] && !isset($theirAccounts[$m['account']])) {
                continue;
            }
            $totals[$acc][0] = ($totals[$acc][0] ?? 0.0) + $m['remaining'];
            if ($withPartner) {
                $p = $acc . '|' . self::partnerKey($m['partner']);
                $partners[$p] ??= ['label' => $m['partner'], 'account' => $acc, 0 => 0.0, 1 => 0.0];
                $partners[$p][0] += $m['remaining'];
            }
        }
        foreach ($theirs as $t) {
            $acc = $accountOf($t['account']);
            $totals[$acc][1] = ($totals[$acc][1] ?? 0.0) + $t['amount'];
            if ($withPartner) {
                $p = $acc . '|' . self::partnerKey($t['partner']);
                $partners[$p] ??= ['label' => $t['partner'], 'account' => $acc, 0 => 0.0, 1 => 0.0];
                $partners[$p][1] += $t['amount'];
            }
        }
        ksort($totals, SORT_STRING);
        foreach ($totals as $acc => $v) {
            $m = round($v[0] ?? 0.0, 2);
            $t = round($v[1] ?? 0.0, 2);
            if (!ReconciliationTolerance::sameCent($m, $t)) {
                $k6[] = self::diff('K6:account:' . $acc, $acc === '*' ? '' : (string) $acc, null, [['field' => 'open_total', 'mine' => $m, 'theirs' => $t]], 'account_total');
            }
        }
        ksort($partners, SORT_STRING);
        foreach ($partners as $key => $p) {
            $m = round($p[0], 2);
            $t = round($p[1], 2);
            if (!ReconciliationTolerance::sameCent($m, $t)) {
                $k6[] = self::diff('K6:partner:' . $key, $p['account'] === '*' ? '' : (string) $p['account'], $p['label'], [['field' => 'open_total', 'mine' => $m, 'theirs' => $t]], 'partner_total');
            }
        }

        // K7: doklad po dokladu. Otevřený jen v MyÚčtu = ve zdroji uhrazený (nebo tam chybí),
        // otevřený jen ve zdroji = v MyÚčtu uhrazený nebo nepřevedený.
        $theirKeys = [];
        foreach ($theirs as $t) {
            $theirKeys[$accountOf($t['account']) . '|' . EpoVatFilingReader::docKey($t['document'])] = true;
        }
        $byDoc = [];
        foreach ($mine as $m) {
            if ($theirAccounts !== [] && !isset($theirAccounts[$m['account']])) {
                continue;
            }
            // Přijatý doklad má dvě čísla (dodavatele a vlastní); zdroj uvádí jedno z nich.
            $key = $accountOf($m['account']) . '|' . EpoVatFilingReader::docKey($m['doc_no']);
            $alt = trim((string) ($m['alt_doc_no'] ?? ''));
            if (!isset($theirKeys[$key]) && $alt !== '' && isset($theirKeys[$accountOf($m['account']) . '|' . EpoVatFilingReader::docKey($alt)])) {
                $key = $accountOf($m['account']) . '|' . EpoVatFilingReader::docKey($alt);
            }
            $byDoc[$key]['mine'] = ($byDoc[$key]['mine'] ?? 0.0) + $m['remaining'];
            $byDoc[$key]['doc'] ??= $m['doc_no'];
            $byDoc[$key]['partner'] ??= $m['partner'];
            $byDoc[$key]['link'] ??= ['type' => $m['doc_type'], 'id' => $m['doc_id']];
            $byDoc[$key]['account'] = $m['account'];
        }
        foreach ($theirs as $t) {
            $key = $accountOf($t['account']) . '|' . EpoVatFilingReader::docKey($t['document']);
            $byDoc[$key]['theirs'] = ($byDoc[$key]['theirs'] ?? 0.0) + $t['amount'];
            $byDoc[$key]['doc'] ??= $t['document'];
            $byDoc[$key]['partner'] ??= $t['partner'];
            $byDoc[$key]['account'] ??= $t['account'];
        }
        ksort($byDoc, SORT_STRING);
        $k7 = [];
        foreach ($byDoc as $key => $d) {
            $m = isset($d['mine']) ? round($d['mine'], 2) : null;
            $t = isset($d['theirs']) ? round($d['theirs'], 2) : null;
            if ($m !== null && $t !== null && ReconciliationTolerance::sameCent($m, $t)) {
                continue;
            }
            $note = match (true) {
                $t === null => 'open_only_in_myucto',
                $m === null => 'open_only_in_source',
                default => 'amount_differs',
            };
            $k7[] = self::diff('K7:' . $key, (string) $d['doc'], (string) ($d['partner'] ?? ''), [['field' => 'remaining', 'mine' => $m, 'theirs' => $t]], $note, $d['link'] ?? null);
        }

        $summary = [
            'items_myucto' => count($mine),
            'items_source' => count($theirs),
            'accounts' => array_map(static fn (array $v): array => ['myucto' => round($v[0] ?? 0.0, 2), 'source' => round($v[1] ?? 0.0, 2)], $totals),
        ];
        return [
            self::result('K6', self::TOLERANCE_CENT, $k6, $summary),
            self::result('K7', self::TOLERANCE_CENT, $k7, ['documents' => count($byDoc)]),
        ];
    }

    /**
     * K8 (korunové účty) a K10 (účty v cizí měně).
     *
     * @param list<array{key:string,numbers:list<string>,label:string,currency:string,ledger_code:?string,ledger_balance:?float,statement_balance:?float,statement_date:?string}> $mine
     * @param list<array{account:string,currency:string,balance:float,balance_czk:?float}> $theirs
     * @return array{0:array<string,mixed>,1:array<string,mixed>} [K8, K10]
     */
    public static function bank(array $mine, array $theirs): array
    {
        $k8 = [];
        $k10 = [];
        $matched = [];
        foreach ($theirs as $i => $t) {
            $account = null;
            foreach ($mine as $m) {
                if (self::bankAccountMatches($t['account'], $m)) {
                    $account = $m;
                    break;
                }
            }
            $foreign = $t['currency'] !== 'CZK';
            $id = ($foreign ? 'K10:' : 'K8:') . self::normalizeAccount($t['account']);
            if ($account === null) {
                $diff = self::diff($id, $t['account'], $t['currency'], [['field' => 'balance', 'mine' => null, 'theirs' => $t['balance']]], 'account_not_found');
                if ($foreign) {
                    $k10[] = $diff;
                } else {
                    $k8[] = $diff;
                }
                continue;
            }
            $matched[$account['key']] = true;
            $values = [];
            if ($foreign) {
                $values[] = ['field' => 'balance_currency', 'mine' => $account['statement_balance'], 'theirs' => $t['balance']];
                if ($t['balance_czk'] !== null) {
                    $values[] = ['field' => 'balance_czk', 'mine' => $account['ledger_balance'], 'theirs' => $t['balance_czk']];
                }
                $bad = $account['statement_balance'] === null
                    || abs($account['statement_balance'] - $t['balance']) > self::FOREIGN_TOLERANCE + ReconciliationTolerance::CENT / 10
                    || ($t['balance_czk'] !== null && ($account['ledger_balance'] === null || !ReconciliationTolerance::sameCent($account['ledger_balance'], $t['balance_czk'])));
                if ($bad) {
                    $k10[] = self::diff($id, $account['label'], $account['currency'], $values);
                }
                continue;
            }
            $values[] = ['field' => 'ledger', 'mine' => $account['ledger_balance'], 'theirs' => $t['balance']];
            $values[] = ['field' => 'statement', 'mine' => $account['statement_balance'], 'theirs' => $t['balance']];
            $bad = $account['ledger_balance'] === null || !ReconciliationTolerance::sameCent($account['ledger_balance'], $t['balance'])
                || ($account['statement_balance'] !== null && !ReconciliationTolerance::sameCent($account['statement_balance'], $account['ledger_balance']));
            if ($bad) {
                $k8[] = self::diff($id, $account['label'], $account['ledger_code'], $values);
            }
        }
        // Vlastní korunový účet, který zdroj neuvádí: kritérium K8 platí i uvnitř MyÚčta
        // (výpis = 221), takže se kontroluje aspoň to.
        foreach ($mine as $m) {
            if (isset($matched[$m['key']]) || $m['currency'] !== 'CZK' || $m['statement_balance'] === null || $m['ledger_balance'] === null) {
                continue;
            }
            if (!ReconciliationTolerance::sameCent($m['statement_balance'], $m['ledger_balance'])) {
                $k8[] = self::diff('K8:' . $m['key'], $m['label'], $m['ledger_code'], [
                    ['field' => 'ledger', 'mine' => $m['ledger_balance'], 'theirs' => null],
                    ['field' => 'statement', 'mine' => $m['statement_balance'], 'theirs' => null],
                ], 'statement_vs_ledger');
            }
        }
        $summary = ['accounts_myucto' => count($mine), 'accounts_source' => count($theirs)];
        return [
            self::result('K8', self::TOLERANCE_CENT, $k8, $summary),
            self::result('K10', 'foreign', $k10, $summary),
        ];
    }

    /**
     * K9 — přiznání k DPH po atributech řádků. Tolerance koruna: podání je v celých Kč.
     *
     * @param array<string,float> $mine
     * @param array<string,float> $theirs
     */
    public static function vatReturn(array $mine, array $theirs): array
    {
        $diffs = [];
        $keys = array_unique(array_merge(array_keys($mine), array_keys($theirs)));
        sort($keys, SORT_STRING);
        foreach ($keys as $key) {
            $m = (float) ($mine[$key] ?? 0.0);
            $t = (float) ($theirs[$key] ?? 0.0);
            if (abs($m - $t) >= ReconciliationTolerance::FILING_ROUNDING) {
                $diffs[] = self::diff('K9:dphdp3:' . $key, $key, EpoVatFilingReader::dphLine($key), [['field' => 'amount', 'mine' => $m, 'theirs' => $t]], 'vat_return');
            }
        }
        return self::result('K9', self::TOLERANCE_CROWN, $diffs, ['lines_myucto' => count($mine), 'lines_source' => count($theirs)]);
    }

    /**
     * K9 — kontrolní hlášení: souhrn VetaC po atributech a řádky oddílů po dokladech.
     *
     * @param array{values:array<string,float>,rows:array<string,array{section:string,partner:string,document:string,amount:float}>} $mine
     * @param array{values:array<string,float>,rows:array<string,array{section:string,partner:string,document:string,amount:float}>} $theirs
     * @param (callable(string,string):?array{type:string,id:int})|null $resolve oddíl + číslo dokladu → doklad v MyÚčtu
     * @return list<array<string,mixed>>
     */
    public static function controlStatement(array $mine, array $theirs, ?callable $resolve = null): array
    {
        $diffs = [];
        $keys = array_unique(array_merge(array_keys($mine['values']), array_keys($theirs['values'])));
        sort($keys, SORT_STRING);
        foreach ($keys as $key) {
            $m = (float) ($mine['values'][$key] ?? 0.0);
            $t = (float) ($theirs['values'][$key] ?? 0.0);
            if (abs($m - $t) >= ReconciliationTolerance::FILING_ROUNDING) {
                $diffs[] = self::diff('K9:dphkh1:' . $key, $key, null, [['field' => 'amount', 'mine' => $m, 'theirs' => $t]], 'control_statement_summary');
            }
        }
        $rowKeys = array_unique(array_merge(array_keys($mine['rows']), array_keys($theirs['rows'])));
        sort($rowKeys, SORT_STRING);
        foreach ($rowKeys as $key) {
            $m = $mine['rows'][$key] ?? null;
            $t = $theirs['rows'][$key] ?? null;
            if ($m !== null && $t !== null && abs($m['amount'] - $t['amount']) < ReconciliationTolerance::FILING_ROUNDING) {
                continue;
            }
            $row = $m ?? $t;
            $note = $m === null ? 'missing_in_myucto' : ($t === null ? 'missing_in_source' : 'amount_differs');
            $link = $resolve !== null && $row['document'] !== '' ? $resolve($row['section'], $row['document']) : null;
            $diffs[] = self::diff('K9:dphkh1:' . $key, $row['section'] . ($row['document'] !== '' ? ' ' . $row['document'] : ''), $row['partner'] !== '' ? $row['partner'] : null,
                [['field' => 'amount', 'mine' => $m['amount'] ?? null, 'theirs' => $t['amount'] ?? null]], $note, $link);
        }
        return $diffs;
    }

    /**
     * K11 — majetek po kartách (vstupní cena, oprávky, zůstatková cena).
     *
     * @param list<array{inventory_number:?string,name:string,input_price:float,acc_amount:float,net_book_value:float}> $mine
     * @param list<array{inventory_number:string,name:string,input_price:?float,acc_amount:?float,net_book_value:?float}> $theirs
     */
    public static function assets(array $mine, array $theirs): array
    {
        $ours = [];
        foreach ($mine as $m) {
            $ours[self::inventoryKey((string) $m['inventory_number'])] = $m;
        }
        $seen = [];
        $diffs = [];
        foreach ($theirs as $t) {
            $key = self::inventoryKey($t['inventory_number']);
            $seen[$key] = true;
            $m = $ours[$key] ?? null;
            $values = [];
            $bad = $m === null;
            foreach (['input_price', 'acc_amount', 'net_book_value'] as $field) {
                if ($t[$field] === null) {
                    continue;
                }
                $mv = $m === null ? null : (float) $m[$field];
                $values[] = ['field' => $field, 'mine' => $mv, 'theirs' => $t[$field]];
                $bad = $bad || $mv === null || !ReconciliationTolerance::sameCent($mv, $t[$field]);
            }
            if ($bad) {
                $diffs[] = self::diff('K11:' . $key, $t['inventory_number'], $m['name'] ?? $t['name'], $values, $m === null ? 'missing_in_myucto' : null);
            }
        }
        foreach ($ours as $key => $m) {
            if (isset($seen[$key])) {
                continue;
            }
            $diffs[] = self::diff('K11:' . $key, (string) $m['inventory_number'], $m['name'], [
                ['field' => 'input_price', 'mine' => $m['input_price'], 'theirs' => null],
                ['field' => 'net_book_value', 'mine' => $m['net_book_value'], 'theirs' => null],
            ], 'missing_in_source');
        }
        return self::result('K11', self::TOLERANCE_CENT, $diffs, ['cards_myucto' => count($mine), 'cards_source' => count($theirs)]);
    }

    /**
     * K12 — obraty tříd 5 a 6 po střediscích.
     *
     * @param array<string,array{name:string,revenue:float,cost:float}> $mine kód střediska ('' = bez střediska)
     * @param array<string,array{revenue:float,cost:float}> $theirs
     */
    public static function costCenters(array $mine, array $theirs): array
    {
        $norm = static fn (string $c): string => strtoupper(trim($c));
        $ours = [];
        foreach ($mine as $code => $v) {
            $ours[$norm((string) $code)] = $v;
        }
        $theirsN = [];
        foreach ($theirs as $code => $v) {
            $theirsN[$norm((string) $code)] = $v;
        }
        $keys = array_unique(array_merge(array_keys($ours), array_keys($theirsN)));
        sort($keys, SORT_STRING);
        $diffs = [];
        foreach ($keys as $key) {
            // Obraty bez střediska se porovnají, jen když je sestava zdroje uvádí řádkem.
            if ($key === '' && !isset($theirsN[''])) {
                continue;
            }
            $m = $ours[$key] ?? ['name' => '', 'revenue' => 0.0, 'cost' => 0.0];
            $t = $theirsN[$key] ?? ['revenue' => 0.0, 'cost' => 0.0];
            if (ReconciliationTolerance::sameCent($m['revenue'], $t['revenue']) && ReconciliationTolerance::sameCent($m['cost'], $t['cost'])) {
                continue;
            }
            $diffs[] = self::diff('K12:' . $key, $key, $m['name'] !== '' ? $m['name'] : null, [
                ['field' => 'revenue', 'mine' => round($m['revenue'], 2), 'theirs' => round($t['revenue'], 2)],
                ['field' => 'cost', 'mine' => round($m['cost'], 2), 'theirs' => round($t['cost'], 2)],
            ], isset($ours[$key]) ? (isset($theirsN[$key]) ? null : 'missing_in_source') : 'missing_in_myucto');
        }
        return self::result('K12', self::TOLERANCE_CENT, $diffs, ['centers_myucto' => count($ours), 'centers_source' => count($theirsN)]);
    }

    /**
     * K13 — řádky výkazu. Porovnávají se řádky, které zdroj uvádí. Tolerance: koruna u výkazu
     * v Kč (výkaz se sestavuje v celých korunách), jednotka u výkazu v tisících.
     *
     * @param array<string,array{label:string,amount:float}> $mine
     * @param array<string,float> $theirs
     * @return list<array<string,mixed>>
     */
    public static function statement(string $prefix, array $mine, array $theirs, float $unit): array
    {
        $tolerance = max(ReconciliationTolerance::FILING_ROUNDING, $unit);
        $diffs = [];
        ksort($theirs, SORT_STRING);
        foreach ($theirs as $code => $amount) {
            $m = $mine[$code] ?? null;
            if ($m !== null && abs($m['amount'] - $amount) < $tolerance) {
                continue;
            }
            $diffs[] = self::diff('K13:' . $prefix . ':' . $code, (string) $code, $m['label'] ?? null,
                [['field' => 'amount', 'mine' => $m['amount'] ?? null, 'theirs' => $amount]], $m === null ? 'row_unknown' : null);
        }
        return $diffs;
    }

    /**
     * @param list<array<string,mixed>> $differences
     * @param array<string,mixed> $summary
     * @return array<string,mixed>
     */
    public static function result(string $key, string $tolerance, array $differences, array $summary = []): array
    {
        return [
            'key' => $key,
            'status' => $differences === [] ? 'ok' : 'differences',
            'tolerance' => $tolerance,
            'summary' => $summary,
            'difference_count' => count($differences),
            'differences' => array_slice($differences, 0, self::MAX_DIFFERENCES),
        ];
    }

    /** @return array<string,mixed> */
    public static function error(string $key, string $message): array
    {
        return ['key' => $key, 'status' => 'error', 'tolerance' => null, 'summary' => [], 'difference_count' => 0, 'differences' => [], 'message' => $message];
    }

    /**
     * @param list<array{field:string,mine:?float,theirs:?float}> $values
     * @param array{type:string,id:int}|null $link
     * @return array<string,mixed>
     */
    public static function diff(string $id, string $subject, ?string $label, array $values, ?string $note = null, ?array $link = null): array
    {
        return ['id' => $id, 'subject' => $subject, 'label' => $label, 'values' => $values, 'note' => $note, 'link' => $link];
    }

    /** Číslo účtu pro párování: bez mezer, pomlček a úvodních nul, velkými písmeny. */
    public static function normalizeAccount(string $account): string
    {
        $a = strtoupper(preg_replace('/[\s.]+/', '', $account) ?? $account);
        if (str_contains($a, '/')) {
            [$number, $bank] = explode('/', $a, 2);
            $parts = array_map(static fn (string $p): string => ltrim($p, '0'), explode('-', $number));
            return implode('-', array_filter($parts, static fn (string $p): bool => $p !== '')) . '/' . $bank;
        }
        return $a;
    }

    /**
     * @param array{numbers:list<string>,ledger_code:?string} $mine
     */
    private static function bankAccountMatches(string $theirs, array $mine): bool
    {
        $t = self::normalizeAccount($theirs);
        if ($t === '') {
            return false;
        }
        foreach ($mine['numbers'] as $number) {
            if ($number !== '' && self::normalizeAccount($number) === $t) {
                return true;
            }
        }
        $ledger = $mine['ledger_code'] === null ? '' : self::normalizeAccount($mine['ledger_code']);
        return $ledger !== '' && $ledger === $t;
    }

    private static function partnerKey(string $name): string
    {
        return DelimitedExport::fold($name);
    }

    private static function inventoryKey(string $number): string
    {
        return strtoupper(preg_replace('/\s+/', '', $number) ?? $number);
    }
}
