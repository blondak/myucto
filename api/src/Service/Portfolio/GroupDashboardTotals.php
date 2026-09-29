<?php

declare(strict_types=1);

namespace MyInvoice\Service\Portfolio;

final class GroupDashboardTotals
{
    public static function aggregate(array $companies): array
    {
        $out = [];
        $specs = [
            'financial' => [['currency'], ['revenue', 'costs', 'profit', 'previous_revenue', 'previous_costs', 'previous_profit']],
            'accounting' => [['currency', 'from', 'to'], ['revenue', 'costs', 'profit']],
            'monthly' => [['currency', 'period'], ['revenue', 'costs', 'profit']],
            'bank' => [['currency'], ['balance']], 'cash' => [['currency'], ['balance']],
            'receivables' => [['currency', 'bucket'], ['total', 'count']],
            'payables' => [['currency', 'bucket'], ['total', 'count']],
            'forecast' => [['currency', 'year'], ['revenue_model', 'costs_model', 'revenue_current_year', 'costs_current_year',
                'other_revenue', 'other_costs', 'other_posted', 'other_draft', 'revenue', 'costs', 'profit', 'revenue_low', 'revenue_high', 'profit_low', 'profit_high']],
        ];
        foreach ($specs as $key => [$dimensions, $numbers]) {
            $groups = [];
            foreach ($companies as $company) {
                $rows = $company[$key] ?? [];
                if ($key === 'accounting' && $rows !== []) $rows = [$rows];
                foreach ($rows as $row) self::add($groups, $row, $dimensions, $numbers, $company['id']);
            }
            $out[$key] = self::finish($groups);
        }
        $groups = [];
        foreach ($companies as $company) {
            foreach ($company['cashflow'] ?? [] as $forecast) {
                foreach ($forecast['weeks'] as $week) self::add($groups,
                    ['currency' => $forecast['currency'], ...$week],
                    ['currency', 'week_start', 'week_end'], ['in', 'out', 'net', 'running'], $company['id']);
            }
        }
        $out['cashflow'] = self::finish($groups);
        return $out;
    }

    private static function add(array &$groups, array $row, array $dimensions, array $numbers, int $company): void
    {
        $identity = [];
        foreach ($dimensions as $dimension) {
            if (!array_key_exists($dimension, $row)) return;
            $identity[$dimension] = $row[$dimension];
        }
        $key = json_encode(array_values($identity), JSON_THROW_ON_ERROR);
        $groups[$key] ??= [...$identity, ...array_fill_keys($numbers, null), '_companies' => [], 'missing_values' => 0];
        $unknownNumbers = count(array_filter($numbers, static fn (string $field): bool => !isset($row[$field]) || !is_numeric($row[$field])));
        $groups[$key]['missing_values'] += max((int) ($row['missing_values'] ?? 0), $unknownNumbers);
        foreach ($numbers as $field) {
            if (!isset($row[$field]) || !is_numeric($row[$field])) {
                continue;
            }
            $groups[$key][$field] = ($groups[$key][$field] ?? 0.0) + (float) $row[$field];
        }
        $groups[$key]['_companies'][$company] = true;
    }

    private static function finish(array $groups): array
    {
        ksort($groups);
        return array_values(array_map(static function (array $row): array {
            $row['companies'] = count($row['_companies']);
            unset($row['_companies']);
            foreach ($row as $key => $value) if (is_float($value)) $row[$key] = round($value, 2);
            return $row;
        }, $groups));
    }
}
