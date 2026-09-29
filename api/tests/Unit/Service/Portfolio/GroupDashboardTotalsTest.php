<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Portfolio;

use MyInvoice\Service\Portfolio\GroupDashboardTotals;
use PHPUnit\Framework\TestCase;

final class GroupDashboardTotalsTest extends TestCase
{
    public function testCurrenciesAndAccountingPeriodsAreSeparateAndAccountCountIsNotCompanyCount(): void
    {
        $totals = GroupDashboardTotals::aggregate([
            ['id' => 1, 'accounting' => ['currency' => 'CZK', 'from' => '2026-01-01', 'to' => '2026-09-01', 'revenue' => 100, 'costs' => 30, 'profit' => 70],
                'bank' => [['currency' => 'CZK', 'balance' => 10], ['balance' => 20, 'currency' => 'CZK'], ['currency' => 'EUR', 'balance' => 5]]],
            ['id' => 2, 'accounting' => ['currency' => 'CZK', 'from' => '2026-04-01', 'to' => '2026-09-01', 'revenue' => 200, 'costs' => 50, 'profit' => 150],
                'bank' => [['currency' => 'CZK', 'balance' => 40], ['currency' => 'EUR', 'balance' => null]]],
            ['id' => 3, 'accounting' => null, 'bank' => null],
        ]);
        self::assertCount(2, $totals['accounting']);
        self::assertSame(100.0, $totals['accounting'][0]['revenue']);
        self::assertSame(200.0, $totals['accounting'][1]['revenue']);
        self::assertSame([
            ['currency' => 'CZK', 'balance' => 70.0, 'missing_values' => 0, 'companies' => 2],
            ['currency' => 'EUR', 'balance' => 5.0, 'missing_values' => 1, 'companies' => 2],
        ], $totals['bank']);
    }

    public function testForecastGroupsOnlyMatchingCurrencyAndWeekAndKeepsRunningProjection(): void
    {
        $week = ['week_start' => '2026-09-01', 'week_end' => '2026-09-07', 'in' => 100, 'out' => 20, 'net' => 80, 'running' => 80];
        $totals = GroupDashboardTotals::aggregate([
            ['id' => 1, 'cashflow' => [['currency' => 'CZK', 'weeks' => [$week]], ['currency' => 'EUR', 'weeks' => [$week]]]],
            ['id' => 2, 'cashflow' => [['currency' => 'CZK', 'weeks' => [$week, [...$week, 'week_start' => '2026-09-08', 'week_end' => '2026-09-14', 'running' => 160]]]]],
        ]);
        self::assertCount(3, $totals['cashflow']);
        self::assertSame(200.0, $totals['cashflow'][0]['in']);
        self::assertSame(160.0, $totals['cashflow'][0]['running']);
        self::assertSame(2, $totals['cashflow'][0]['companies']);
        self::assertSame(1, $totals['cashflow'][1]['companies']);
        self::assertSame('EUR', $totals['cashflow'][2]['currency']);
    }

    public function testEntirelyUnknownBalancesAreNullAndKnownZeroIsPreserved(): void
    {
        $totals = GroupDashboardTotals::aggregate([
            ['id' => 1, 'bank' => [['currency' => 'EUR', 'balance' => null], ['currency' => 'CZK', 'balance' => 0]]],
            ['id' => 2, 'bank' => [['currency' => 'EUR', 'balance' => null]]],
        ]);
        self::assertSame(0.0, $totals['bank'][0]['balance']);
        self::assertNull($totals['bank'][1]['balance']);
        self::assertSame(2, $totals['bank'][1]['missing_values']);
    }
}
