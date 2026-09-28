<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Abra;

/** Haléřová kontrola převzatého deníku proti zdrojové předvaze Flexi. */
final class AbraReconciler
{
    /**
     * @param list<array<string,mixed>> $journal
     * @param list<array<string,mixed>> $movements
     * @param list<array<string,mixed>> $balances
     * @return array{ok:bool,complete:bool,source:string,accounts:int,differences:list<array{account:string,side:string,source:float,journal:float,difference:float}>,warnings:list<string>}
     */
    public function reconcile(array $journal, array $movements, array $balances): array
    {
        $mine = [];
        foreach ($journal as $entry) {
            $amount = (float) ($entry['amount'] ?? 0.0) * (!empty($entry['is_red_storno']) ? -1 : 1);
            $this->add($mine, (string) ($entry['debit'] ?? ''), 'debit', $amount);
            $this->add($mine, (string) ($entry['credit'] ?? ''), 'credit', $amount);
        }

        $sourceName = '';
        $source = [];
        $warnings = [];
        $complete = true;
        if ($movements !== []) {
            $sourceName = 'pohyb-na-uctech';
            $journalByKey = [];
            foreach ($journal as $entry) {
                $journalByKey[(string) ($entry['source_key'] ?? '')] = $entry;
            }
            foreach ($movements as $row) {
                [$hasDebit, $debit] = $this->firstPresent($row, ['sumTuzMd', 'sumMd']);
                [$hasCredit, $credit] = $this->firstPresent($row, ['sumTuzDal', 'sumDal']);
                if (!$hasDebit && !$hasCredit) {
                    $complete = false;
                    continue;
                }
                $account = AbraSource::account($row['ucet'] ?? null);
                if ($account !== '') {
                    $this->add($source, $account, 'debit', $debit);
                    $this->add($source, $account, 'credit', $credit);
                    continue;
                }
                $debitAccount = AbraSource::account($row['mdUcet'] ?? null);
                $creditAccount = AbraSource::account($row['dalUcet'] ?? null);
                if ($debitAccount === '' || $creditAccount === '') {
                    $entryKey = AbraSource::reference($row['idUcetniDenik'] ?? null) ?: AbraSource::sourceKey($row);
                    $entry = $journalByKey[$entryKey] ?? null;
                    $debitAccount = (string) ($entry['debit'] ?? '');
                    $creditAccount = (string) ($entry['credit'] ?? '');
                }
                if ($debitAccount === '' || $creditAccount === '') {
                    $complete = false;
                    continue;
                }
                $this->add($source, $debitAccount, 'debit', $debit);
                $this->add($source, $creditAccount, 'credit', $credit);
            }
        } elseif ($balances !== []) {
            $sourceName = 'stav-uctu';
            $turnoversPresent = false;
            foreach ($balances as $row) {
                $account = AbraSource::account($row['ucet'] ?? null);
                if ($account === '') {
                    $complete = false;
                    continue;
                }
                [$hasDebitTurnover, $debitTurnover] = $this->monthlyTurnover($row, 'Md');
                [$hasCreditTurnover, $creditTurnover] = $this->monthlyTurnover($row, 'Dal');
                $turnoversPresent = $turnoversPresent || $hasDebitTurnover || $hasCreditTurnover;
                $this->add($source, $account, 'debit', $debitTurnover);
                $this->add($source, $account, 'credit', $creditTurnover);
            }
            if (!$turnoversPresent) {
                $complete = false;
                $warnings[] = 'source_trial_balance_fields_missing';
            }
        }
        if ($source === [] || !$complete) {
            if ($source === []) {
                $warnings[] = 'source_trial_balance_missing';
            } elseif ($sourceName === 'pohyb-na-uctech') {
                $warnings[] = 'source_movement_control_incomplete';
            }
            return [
                'ok' => false,
                'complete' => false,
                'source' => $sourceName,
                'accounts' => count($source),
                'differences' => [],
                'warnings' => array_values(array_unique($warnings)),
            ];
        }

        $differences = [];
        $accounts = array_values(array_unique([...array_keys($source), ...array_keys($mine)]));
        sort($accounts, SORT_STRING);
        foreach ($accounts as $account) {
            foreach (['debit', 'credit'] as $side) {
                $expected = round($source[$account][$side] ?? 0.0, 2);
                $actual = round($mine[$account][$side] ?? 0.0, 2);
                $difference = round($actual - $expected, 2);
                if (abs($difference) >= 0.005) {
                    $differences[] = [
                        'account' => $account,
                        'side' => $side,
                        'source' => $expected,
                        'journal' => $actual,
                        'difference' => $difference,
                    ];
                }
            }
        }
        return [
            'ok' => $differences === [],
            'complete' => true,
            'source' => $sourceName,
            'accounts' => count($source),
            'differences' => array_slice($differences, 0, 50),
            'warnings' => count($differences) > 50 ? ['trial_balance_differences_truncated'] : [],
        ];
    }

    /** @param array<string,array{debit?:float,credit?:float}> $totals */
    private function add(array &$totals, string $account, string $side, float $amount): void
    {
        if ($account === '') {
            return;
        }
        $totals[$account][$side] = ($totals[$account][$side] ?? 0.0) + $amount;
    }

    /** @param list<string> $keys @return array{0:bool,1:float} */
    private function firstPresent(array $row, array $keys): array
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $row)) {
                return [true, AbraSource::number($row[$key]) ?? 0.0];
            }
        }
        return [false, 0.0];
    }

    /** @return array{0:bool,1:float} */
    private function monthlyTurnover(array $row, string $side): array
    {
        $found = false;
        $total = 0.0;
        for ($slot = 1; $slot <= 23; $slot++) {
            $suffix = str_pad((string) $slot, 2, '0', STR_PAD_LEFT);
            $keys = $side === 'Md'
                ? ['obratMd' . $suffix, 'obratMD' . $suffix]
                : ['obratDal' . $suffix, 'obratDAL' . $suffix];
            [$present, $value] = $this->firstPresent($row, $keys);
            if ($present) {
                $found = true;
                $total += $value;
            }
        }
        return [$found, $total];
    }
}
