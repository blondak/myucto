<?php

declare(strict_types=1);

namespace MyInvoice\Service\Portfolio;

use MyInvoice\Infrastructure\Database\Connection;

final class GroupDashboardCurrencyView
{
    public function __construct(private readonly Connection $db) {}

    public function convert(array $companies, string $asOf): array
    {
        $rates = [];
        $missing = [];
        $converted = [];
        foreach ($companies as $company) {
            $copy = $company;
            foreach (['financial', 'monthly', 'bank', 'cash', 'receivables', 'payables', 'cashflow', 'forecast', 'risks'] as $key) {
                if (!isset($company[$key])) continue;
                $rows = [];
                $unknown = 0;
                foreach ($company[$key] as $row) {
                    $currency = $row['currency'];
                    $convertedRow = $row;
                    $convertedRow['currency'] = 'CZK';
                    $fields = match ($key) {
                        'financial' => ['revenue', 'costs', 'profit', 'previous_revenue', 'previous_costs', 'previous_profit'],
                        'monthly' => ['revenue', 'costs', 'profit'],
                        'bank', 'cash' => ['balance'],
                        'receivables', 'payables' => ['total'],
                        'forecast' => array_values(array_diff(array_keys($row), ['year', 'currency'])),
                        'risks' => isset($row['amount']) ? ['amount'] : [],
                        default => [],
                    };
                    foreach ($fields as $field) {
                        if (in_array($key, ['financial', 'monthly'], true) || ($key === 'risks' && $row['kind'] === 'document_loss') || $key === 'cash') {
                            $convertedRow[$field] = $currency === 'CZK' ? $row[$field] : ($row[$field . '_czk'] ?? null);
                            if ($convertedRow[$field] === null) $unknown++;
                            else $convertedRow[$field] = (float) $convertedRow[$field];
                        } else {
                            $convertedRow[$field] = $this->amount($row[$field] ?? null, $currency, $asOf, $company['id'], $rates, $missing, $unknown);
                        }
                    }
                    $convertedRow['missing_values'] = (int) ($row['conversion_missing'] ?? 0);
                    $unknown += $convertedRow['missing_values'];
                    if ($convertedRow['missing_values'] > 0 && $currency !== 'CZK') $missing[$currency] = true;
                    foreach (array_keys($convertedRow) as $field) {
                        if (str_ends_with($field, '_czk') || $field === 'conversion_missing') unset($convertedRow[$field]);
                    }
                    if ($key === 'cashflow') {
                        foreach ($convertedRow['weeks'] as &$week) foreach (['in', 'out', 'net', 'running'] as $field) {
                            $week[$field] = $this->amount($week[$field], $currency, $asOf, $company['id'], $rates, $missing, $unknown);
                        }
                        unset($week);
                    }
                    if ($key === 'risks') $convertedRow['source_currency'] = $currency;
                    if (in_array($key, ['bank', 'cash'], true)) $convertedRow['source_currency'] = $currency;
                    $rows[] = $convertedRow;
                }
                if (in_array($key, ['risks', 'bank', 'cash'], true)) {
                    $copy[$key] = $rows;
                } elseif ($key === 'cashflow') {
                    $weeks = GroupDashboardTotals::aggregate([['id' => $company['id'], 'cashflow' => $rows]])['cashflow'];
                    $copy[$key] = $rows === [] ? [] : [[
                        'currency' => 'CZK', 'weeks' => $weeks,
                        'total_in' => self::sum($weeks, 'in'), 'total_out' => self::sum($weeks, 'out'), 'total_net' => self::sum($weeks, 'net'),
                    ]];
                } else {
                    $copy[$key] = GroupDashboardTotals::aggregate([['id' => $company['id'], $key => $rows]])[$key];
                }
                if ($unknown > 0) $copy['issues'][] = 'conversion.' . $key;
            }
            $copy['issues'] = array_values(array_unique($copy['issues']));
            $converted[] = $copy;
        }
        return ['companies' => $converted, 'totals' => GroupDashboardTotals::aggregate($converted),
            'as_of' => $asOf, 'rates' => array_values($rates), 'missing_currencies' => array_keys($missing),
            'bases' => ['financial' => 'document_recorded', 'monthly' => 'document_recorded', 'cash' => 'balance_recorded',
                'bank' => 'cached_rate', 'cashflow' => 'cached_rate', 'receivables' => 'cached_rate', 'payables' => 'cached_rate', 'forecast' => 'cached_rate']];
    }

    private function amount(mixed $amount, string $currency, string $asOf, int $supplier, array &$rates, array &$missing, int &$unknown): ?float
    {
        if ($amount === null || !is_numeric($amount)) {
            $unknown++;
            return null;
        }
        if ((float) $amount === 0.0 || $currency === 'CZK') return (float) $amount;
        $key = $supplier . ':' . $currency;
        if (!array_key_exists($key, $rates)) {
            $find = $this->db->pdo()->prepare('SELECT rate, rate_date FROM exchange_rates
                WHERE currency_code = ? AND rate_date <= ? AND rate > 0 ORDER BY rate_date DESC LIMIT 1');
            $find->execute([$currency, $asOf]);
            $row = $find->fetch(\PDO::FETCH_ASSOC);
            $rates[$key] = ['supplier_id' => $supplier, 'currency' => $currency, 'rate' => $row === false ? null : (float) $row['rate'],
                'date' => $row === false ? null : $row['rate_date'], 'basis' => 'cached_rate'];
        }
        if ($rates[$key]['rate'] === null) {
            $missing[$currency] = true;
            $unknown++;
            return null;
        }
        return round((float) $amount * $rates[$key]['rate'], 2);
    }

    private static function sum(array $rows, string $field): ?float
    {
        $known = array_values(array_filter(array_column($rows, $field), static fn ($value): bool => $value !== null));
        return $known === [] ? null : round(array_sum($known), 2);
    }
}
