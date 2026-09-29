<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Portfolio;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Portfolio\GroupDashboardCurrencyView;
use PHPUnit\Framework\TestCase;

final class GroupDashboardCurrencyViewTest extends TestCase
{
    private function view(): GroupDashboardCurrencyView
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->exec("CREATE TABLE exchange_rates (currency_code TEXT, rate_date TEXT, rate NUMERIC);
            INSERT INTO exchange_rates VALUES ('EUR', '2026-08-01', 25), ('EUR', '2026-09-01', 26),
                ('EUR', '2026-10-01', 99), ('ZZZ', '2026-10-01', 100)");
        $db = $this->createStub(Connection::class);
        $db->method('pdo')->willReturn($pdo);
        return new GroupDashboardCurrencyView($db);
    }

    private function company(array $fields): array
    {
        return ['id' => 7, 'name' => 'Synthetic currency group', 'issues' => [], ...$fields];
    }

    public function testRecordedDocumentRatesAreUsedAndCurrenciesMergeOncePerCompanyMonth(): void
    {
        $company = $this->company(['monthly' => [
            ['currency' => 'EUR', 'period' => '2026-08', 'revenue' => 100, 'costs' => 20, 'profit' => 80,
                'revenue_czk' => 2400, 'costs_czk' => 480, 'profit_czk' => 1920],
            ['currency' => 'CZK', 'period' => '2026-08', 'revenue' => 100, 'costs' => 20, 'profit' => 80],
        ]]);
        $out = $this->view()->convert([$company], '2026-09-29');
        self::assertCount(1, $out['companies'][0]['monthly']);
        self::assertSame(2500.0, $out['companies'][0]['monthly'][0]['revenue']);
        self::assertSame(2000.0, $out['companies'][0]['monthly'][0]['profit']);
        self::assertSame('CZK', $out['companies'][0]['monthly'][0]['currency']);
        self::assertSame([], $out['rates']);
        self::assertSame('EUR', $company['monthly'][0]['currency']);
    }

    public function testCachedRatesAreAsOfOnlyAndUnknownBalancesAreExplicit(): void
    {
        $out = $this->view()->convert([$this->company(['bank' => [
            ['id' => 20, 'name' => 'Synthetic EUR account', 'date' => '2026-08-31', 'currency' => 'EUR', 'balance' => 10],
            ['id' => 21, 'name' => 'Synthetic ZZZ account', 'date' => '2026-09-01', 'currency' => 'ZZZ', 'balance' => 20],
        ]])], '2026-09-29');
        self::assertCount(2, $out['companies'][0]['bank']);
        self::assertSame(260.0, $out['companies'][0]['bank'][0]['balance']);
        self::assertNull($out['companies'][0]['bank'][1]['balance']);
        self::assertSame(20, $out['companies'][0]['bank'][0]['id']);
        self::assertSame('Synthetic EUR account', $out['companies'][0]['bank'][0]['name']);
        self::assertSame('2026-08-31', $out['companies'][0]['bank'][0]['date']);
        self::assertSame('EUR', $out['companies'][0]['bank'][0]['source_currency']);
        self::assertSame(1, $out['totals']['bank'][0]['missing_values']);
        self::assertContains('conversion.bank', $out['companies'][0]['issues']);
        self::assertSame(['ZZZ'], $out['missing_currencies']);
        self::assertSame(26.0, $out['rates'][0]['rate']);
        self::assertSame('2026-09-01', $out['rates'][0]['date']);
        self::assertNull($out['rates'][1]['rate']);
        $unknown = $this->view()->convert([$this->company(['bank' => [['currency' => 'ZZZ', 'balance' => 20]]])], '2026-09-29');
        self::assertNull($unknown['companies'][0]['bank'][0]['balance']);
        self::assertNull($unknown['totals']['bank'][0]['balance']);
    }

    public function testKnownZeroAndRecordedCashAreNotInventedCurrencyConversions(): void
    {
        $out = $this->view()->convert([$this->company(['cash' => [
            ['currency' => 'EUR', 'balance' => 10, 'balance_czk' => 240],
            ['currency' => 'ZZZ', 'balance' => 0, 'balance_czk' => 0],
        ], 'payables' => [['currency' => 'ZZZ', 'bucket' => 'not_due', 'count' => 1, 'total' => 0]]])], '2026-09-29');
        self::assertSame(240.0, $out['companies'][0]['cash'][0]['balance']);
        self::assertSame(0.0, $out['companies'][0]['payables'][0]['total']);
        self::assertSame([], $out['companies'][0]['issues']);
        self::assertSame([], $out['rates']);
    }

    public function testCashflowAndRisksKeepKnownPartialValuesAndSourceCurrency(): void
    {
        $week = ['week_start' => '2026-09-29', 'week_end' => '2026-10-04', 'in' => 10, 'out' => 3, 'net' => 7, 'running' => 7];
        $out = $this->view()->convert([$this->company(['cashflow' => [
            ['currency' => 'EUR', 'weeks' => [$week]], ['currency' => 'ZZZ', 'weeks' => [$week]],
        ], 'risks' => [['kind' => 'overdue_receivables', 'currency' => 'EUR', 'amount' => 5, 'count' => 1],
            ['kind' => 'client_concentration', 'currency' => 'EUR', 'percentage' => 40, 'level' => 'medium']]])], '2026-09-29');
        self::assertCount(1, $out['companies'][0]['cashflow']);
        self::assertSame(260.0, $out['companies'][0]['cashflow'][0]['total_in']);
        self::assertSame(182.0, $out['companies'][0]['cashflow'][0]['weeks'][0]['running']);
        self::assertContains('conversion.cashflow', $out['companies'][0]['issues']);
        self::assertSame(130.0, $out['companies'][0]['risks'][0]['amount']);
        self::assertSame('EUR', $out['companies'][0]['risks'][0]['source_currency']);
        self::assertSame(40, $out['companies'][0]['risks'][1]['percentage']);
    }
}
