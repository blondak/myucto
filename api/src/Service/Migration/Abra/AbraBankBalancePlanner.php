<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Abra;

final class AbraBankBalancePlanner
{
    /** @return array<string,int> */
    public static function verifiedOpenings(array $accounts, array $states, array $bankRows): array
    {
        $byId = [];
        $owners = [];
        foreach ($accounts as $account) {
            if (!is_array($account)) continue;
            $id = AbraSource::reference($account['id'] ?? null);
            $accountCode = AbraSource::reference($account['primUcet'] ?? null);
            $currency = AbraSource::currency($account['mena'] ?? null);
            if ($id === '' || $accountCode === '' || $currency === '') continue;
            $byId[$id] = ['account' => $accountCode, 'currency' => $currency];
            $owners[$accountCode] = ($owners[$accountCode] ?? 0) + 1;
        }
        $balances = [];
        foreach ($states as $state) {
            if (!is_array($state)) continue;
            $accountCode = AbraSource::reference($state['ucet'] ?? null);
            $currency = AbraSource::currency($state['mena'] ?? null);
            $md = AbraSource::number($state['pocatekMD'] ?? null);
            $dal = AbraSource::number($state['pocatekDal'] ?? null);
            $endMd = AbraSource::number($state['zustatekMD'] ?? null);
            $endDal = AbraSource::number($state['zustatekDal'] ?? null);
            if ($accountCode === '' || $currency === '' || $md === null || $dal === null
                || $endMd === null || $endDal === null) continue;
            $key = $accountCode . '|' . $currency;
            $balances[$key]['opening'] = ($balances[$key]['opening'] ?? 0)
                + (int) round(($md - $dal) * 100);
            $balances[$key]['closing'] = ($balances[$key]['closing'] ?? 0)
                + (int) round(($endMd - $endDal) * 100);
        }

        $postedSums = [];
        $invalid = [];
        $mapper = new AbraPaymentMapper();
        foreach ($bankRows as $row) {
            if (!is_array($row)) continue;
            $relation = AbraSource::relation($row['banka@ref'] ?? $row['banka'] ?? null);
            $id = $relation['key'] ?? '';
            if (!isset($byId[$id])) continue;
            $plan = $mapper->mapMovement($row, 'banka');
            $key = $id . '|' . $plan['currency'];
            if ($plan['posted'] === null || $plan['blockers'] !== []) {
                $invalid[$key] = true;
                continue;
            }
            $postedSums[$key] = ($postedSums[$key] ?? 0)
                + ($plan['posted'] ? (int) round($plan['amount'] * 100) : 0);
        }

        $openings = [];
        foreach ($postedSums as $key => $sum) {
            [$id, $currency] = explode('|', $key, 2);
            $account = $byId[$id];
            $stateKey = $account['account'] . '|' . $currency;
            if (isset($invalid[$key]) || ($owners[$account['account']] ?? 0) !== 1
                || !isset($balances[$stateKey])) continue;
            $opening = $balances[$stateKey]['opening'];
            if (abs($opening + $sum - $balances[$stateKey]['closing']) <= 1) {
                $openings[$key] = $opening;
            }
        }
        return $openings;
    }

    /** @param list<array{credit:int,debit:int}> $months @return list<array{prev:int,curr:int,credit:int,debit:int}> */
    public static function monthly(int $opening, array $months): array
    {
        $out = [];
        foreach ($months as $month) {
            $credit = (int) $month['credit'];
            $debit = (int) $month['debit'];
            if ($credit < 0 || $debit < 0) throw new \InvalidArgumentException('Invalid bank turnover.');
            $current = $opening + $credit - $debit;
            $out[] = ['prev' => $opening, 'curr' => $current, 'credit' => $credit, 'debit' => $debit];
            $opening = $current;
        }
        return $out;
    }
}
