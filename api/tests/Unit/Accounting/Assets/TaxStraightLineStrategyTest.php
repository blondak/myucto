<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Accounting\Assets;

use MyInvoice\Service\Accounting\Assets\DepreciationContext;
use MyInvoice\Service\Accounting\Assets\Strategy\TaxStraightLineStrategy;
use MyInvoice\Service\Accounting\FiscalCalendar;
use PHPUnit\Framework\TestCase;

/**
 * Unit testy rovnoměrných daňových odpisů §31 ZDP (Epic F3, spec §6.1 U1–U10).
 * Očekávané hodnoty jsou ZÁVAZNÁ ručně spočtená čísla ze specu.
 */
final class TaxStraightLineStrategyTest extends TestCase
{
    private TaxStraightLineStrategy $strategy;

    protected function setUp(): void
    {
        $this->strategy = new TaxStraightLineStrategy();
    }

    /**
     * @param array<string,mixed> $overrides
     */
    private function ctx(array $overrides = []): DepreciationContext
    {
        $args = array_merge([
            'inputPrice' => 100000.0,
            'taxGroup' => 1,
            'firstYearIncrease' => 'none',
            'isFirstOwner' => false,
            'isM1Vehicle' => false,
            'm1LimitException' => false,
            'putIntoUseDate' => '2098-05-15',
            'disposalDate' => null,
            'accUsefulLifeMonths' => null,
            'accResidualValue' => 0.0,
            'openingTaxYears' => 0,
            'openingTaxAmount' => 0.0,
            'openingAccMonths' => 0,
            'openingAccAmount' => 0.0,
            'improvements' => [],
            'confirmedEntries' => [],
        ], $overrides);

        return new DepreciationContext(...$args);
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return list<float>
     */
    private function fullAmounts(array $rows): array
    {
        return array_map(static fn (array $r): float => (float) $r['full_amount'], $rows);
    }

    /**
     * @param list<array<string,mixed>> $rows
     */
    private function sumFull(array $rows): float
    {
        return round(array_sum($this->fullAmounts($rows)), 2);
    }

    public function testU1Group1BasicCourse(): void
    {
        $rows = $this->strategy->plan($this->ctx(['taxGroup' => 1, 'inputPrice' => 100000.0]));

        self::assertSame([20000.0, 40000.0, 40000.0], $this->fullAmounts($rows), 'U1: 20 000; 40 000; 40 000.');
        self::assertSame(100000.0, $this->sumFull($rows), 'Σ odpisů = VC.');
    }

    public function testU2Group2BasicCourse(): void
    {
        $rows = $this->strategy->plan($this->ctx(['taxGroup' => 2, 'inputPrice' => 500000.0]));

        self::assertSame([55000.0, 111250.0, 111250.0, 111250.0, 111250.0], $this->fullAmounts($rows), 'U2: 55 000; 4× 111 250.');
        self::assertSame(500000.0, $this->sumFull($rows));
    }

    public function testU3AllSixGroupsFirstNextAndSumEqualsInputPrice(): void
    {
        // [skupina => [1. rok, další rok, počet let §30/1]]
        $expected = [
            1 => [200000.0, 400000.0, 3],
            2 => [110000.0, 222500.0, 5],
            3 => [55000.0, 105000.0, 10],
            4 => [21500.0, 51500.0, 20],
            5 => [14000.0, 34000.0, 30],
            6 => [10200.0, 20200.0, 50],
        ];
        foreach ($expected as $group => [$first, $next, $years]) {
            $rows = $this->strategy->plan($this->ctx(['taxGroup' => $group, 'inputPrice' => 1000000.0]));

            self::assertCount($years, $rows, "U3 sk. {$group}: doba odpisování {$years} let.");
            self::assertSame($first, (float) $rows[0]['full_amount'], "U3 sk. {$group}: 1. rok.");
            self::assertSame($next, (float) $rows[1]['full_amount'], "U3 sk. {$group}: další rok.");
            self::assertSame(1000000.0, $this->sumFull($rows), "U3 sk. {$group}: Σ = VC.");
        }
    }

    public function testU4CeilAndResidualCap(): void
    {
        $rows = $this->strategy->plan($this->ctx(['taxGroup' => 1, 'inputPrice' => 99999.0]));

        self::assertSame([20000.0, 40000.0, 39999.0], $this->fullAmounts($rows), 'U4: ceil(19 999,80)=20 000; 40 000; cap na ZC 39 999.');
        self::assertSame(99999.0, $this->sumFull($rows));
    }

    public function testU5FirstYearIncreaseP10Group2(): void
    {
        $rows = $this->strategy->plan($this->ctx([
            'taxGroup' => 2,
            'inputPrice' => 300000.0,
            'firstYearIncrease' => 'p10',
            'isFirstOwner' => true,
        ]));

        self::assertSame([63000.0, 59250.0, 59250.0, 59250.0, 59250.0], $this->fullAmounts($rows), 'U5: 63 000; 4× 59 250.');
        self::assertSame(300000.0, $this->sumFull($rows));
    }

    public function testU6ImprovementInThirdYearSwitchesToIncreasedRate(): void
    {
        $rows = $this->strategy->plan($this->ctx([
            'taxGroup' => 2,
            'inputPrice' => 500000.0,
            'putIntoUseDate' => '2098-05-15',
            'improvements' => [['completed_on' => '2100-06-30', 'amount' => 100000.0]],
        ]));

        self::assertSame(
            [55000.0, 111250.0, 120000.0, 120000.0, 120000.0, 73750.0],
            $this->fullAmounts($rows),
            'U6: 55 000; 111 250; 3× 120 000 (20 % ze 600 000); 73 750 cap.',
        );
        self::assertSame(600000.0, $this->sumFull($rows), 'Σ = zvýšená VC 600 000.');
    }

    public function testU7HalfDepreciationOnDisposalInThirdYear(): void
    {
        $rows = $this->strategy->plan($this->ctx([
            'taxGroup' => 2,
            'inputPrice' => 500000.0,
            'putIntoUseDate' => '2098-05-15',
            'disposalDate' => '2100-06-30',
        ]));

        self::assertCount(3, $rows, 'Plán končí rokem vyřazení.');
        $last = $rows[2];
        self::assertSame(2100, $last['fiscal_year']);
        self::assertSame(55625.0, (float) $last['full_amount'], 'U7: ½ z 111 250 = 55 625.');
        self::assertTrue($last['is_half'], 'U7: is_half=1.');
    }

    public function testU8M1VehicleOverLimitClaimsProRatedAmounts(): void
    {
        $rows = $this->strategy->plan($this->ctx([
            'taxGroup' => 2,
            'inputPrice' => 3000000.0,
            'isM1Vehicle' => true,
        ]));

        self::assertSame([330000.0, 667500.0, 667500.0, 667500.0, 667500.0], $this->fullAmounts($rows), 'U8: full jede z nekrácené VC (R8).');
        $amounts = array_map(static fn (array $r): float => (float) $r['amount'], $rows);
        self::assertSame([220000.0, 445000.0, 445000.0, 445000.0, 445000.0], $amounts, 'U8: amount = full × 2/3 (§30e).');
        self::assertSame(2000000.0, round(array_sum($amounts), 2), 'U8: Σ amount = 2 000 000.');
        self::assertSame(3000000.0, $this->sumFull($rows), 'ZC jede z full_amount.');
    }

    public function testU9PausedYearShiftsPlanWithoutResidualChange(): void
    {
        $rows = $this->strategy->plan($this->ctx([
            'taxGroup' => 1,
            'inputPrice' => 90000.0,
            'putIntoUseDate' => '2098-03-01',
            'confirmedEntries' => [
                ['fiscal_year' => 2099, 'kind' => 'tax', 'amount' => 0.0, 'full_amount' => 0.0, 'is_paused' => true, 'is_half' => false],
            ],
        ]));

        self::assertSame([18000.0, 0.0, 36000.0, 36000.0], $this->fullAmounts($rows), 'U9: 18 000; pauza 0; 36 000; 36 000.');
        self::assertTrue($rows[1]['is_paused'], 'Rok 2 je přerušený (§26/8).');
        self::assertSame((float) $rows[1]['residual_start'], (float) $rows[1]['residual_end'], 'Pauza nemění ZC.');
        self::assertSame(90000.0, $this->sumFull($rows));
    }

    public function testU10AcquisitionAndDisposalSameYearYieldsZero(): void
    {
        $rows = $this->strategy->plan($this->ctx([
            'taxGroup' => 2,
            'inputPrice' => 500000.0,
            'putIntoUseDate' => '2098-02-10',
            'disposalDate' => '2098-11-20',
        ]));

        self::assertCount(1, $rows);
        self::assertSame(0.0, (float) $rows[0]['full_amount'], 'U10: zařazení i vyřazení v témže roce → odpis 0 (R6).');
        self::assertSame(0.0, (float) $rows[0]['amount']);
    }

    public function testFiscalYearShiftsLabelsButNotAmounts(): void
    {
        // Hospodářský rok 1. 7.–30. 6.; zařazení 15. 3. 2098 spadá do období 2097
        // (2097-07-01..2098-06-30). Částky §31 se nemění, jen labely období se posunou.
        $rows = $this->strategy->plan($this->ctx([
            'taxGroup' => 1,
            'inputPrice' => 100000.0,
            'putIntoUseDate' => '2098-03-15',
            'calendar' => FiscalCalendar::fromPeriodStart('2097-07-01'),
        ]));

        self::assertSame([20000.0, 40000.0, 40000.0], $this->fullAmounts($rows), 'Částky shodné s kalendářním.');
        self::assertSame([2097, 2098, 2099], array_map(static fn (array $r): int => (int) $r['fiscal_year'], $rows), 'Labely = období hospodářského roku.');
        self::assertSame(100000.0, $this->sumFull($rows));
    }

    public function testFiscalYearHalfDepreciationOnDisposal(): void
    {
        // Zařazení 15. 8. 2098 → období 2098 (2098-07-01..2099-06-30). Vyřazení
        // 10. 8. 2100 spadá do období 2100 (2100-07-01..2101-06-30) → 3. rok, půlodpis.
        $rows = $this->strategy->plan($this->ctx([
            'taxGroup' => 2,
            'inputPrice' => 500000.0,
            'putIntoUseDate' => '2098-08-15',
            'disposalDate' => '2100-08-10',
            'calendar' => FiscalCalendar::fromPeriodStart('2098-07-01'),
        ]));

        self::assertCount(3, $rows, 'Plán končí obdobím vyřazení (3. rok).');
        self::assertSame([2098, 2099, 2100], array_map(static fn (array $r): int => (int) $r['fiscal_year'], $rows));
        $last = $rows[2];
        self::assertSame(55625.0, (float) $last['full_amount'], '½ z 111 250 = 55 625.');
        self::assertTrue($last['is_half']);
    }

    public function testYearRowMatchesPlanRow(): void
    {
        $ctx = $this->ctx(['taxGroup' => 2, 'inputPrice' => 500000.0]);
        $row = $this->strategy->yearRow($ctx, 2099);

        self::assertNotNull($row);
        self::assertSame(2099, $row['fiscal_year']);
        self::assertSame(111250.0, (float) $row['full_amount']);
    }
}
