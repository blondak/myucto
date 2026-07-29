<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Accounting\Assets;

use MyInvoice\Service\Accounting\Assets\DepreciationContext;
use MyInvoice\Service\Accounting\Assets\Strategy\TaxAcceleratedStrategy;
use PHPUnit\Framework\TestCase;

/**
 * Unit testy zrychlených daňových odpisů §32 ZDP (Epic F3, spec §6.1 U12–U16).
 * Očekávané hodnoty jsou ZÁVAZNÁ ručně spočtená čísla ze specu.
 */
final class TaxAcceleratedStrategyTest extends TestCase
{
    private TaxAcceleratedStrategy $strategy;

    protected function setUp(): void
    {
        $this->strategy = new TaxAcceleratedStrategy();
    }

    /**
     * @param array<string,mixed> $overrides
     */
    private function ctx(array $overrides = []): DepreciationContext
    {
        $args = array_merge([
            'inputPrice' => 500000.0,
            'taxGroup' => 2,
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

    public function testU12Group2BasicCourse(): void
    {
        $rows = $this->strategy->plan($this->ctx());

        self::assertSame(
            [100000.0, 160000.0, 120000.0, 80000.0, 40000.0],
            $this->fullAmounts($rows),
            'U12: 100 000; 160 000; 120 000; 80 000; 40 000.',
        );
        self::assertSame(500000.0, round(array_sum($this->fullAmounts($rows)), 2));
    }

    public function testU13Group1CeilRounding(): void
    {
        $rows = $this->strategy->plan($this->ctx(['taxGroup' => 1, 'inputPrice' => 300000.0]));

        self::assertSame(
            [100000.0, 133334.0, 66666.0],
            $this->fullAmounts($rows),
            'U13: 100 000; ceil(2×200 000/3)=133 334; 66 666 (cap).',
        );
        self::assertSame(300000.0, round(array_sum($this->fullAmounts($rows)), 2), 'Σ = 300 000.');
    }

    public function testU14ImprovementInThirdYearRestartsFromIncreasedResidual(): void
    {
        $rows = $this->strategy->plan($this->ctx([
            'putIntoUseDate' => '2098-05-15',
            'improvements' => [['completed_on' => '2100-06-30', 'amount' => 100000.0]],
        ]));

        self::assertSame(
            [100000.0, 160000.0, 136000.0, 102000.0, 68000.0, 34000.0],
            $this->fullAmounts($rows),
            'U14: rok TZ 2×340 000/5=136 000; dále 2×ZC/(k₃−n′).',
        );
        self::assertSame(600000.0, round(array_sum($this->fullAmounts($rows)), 2), 'Σ = 600 000.');
    }

    public function testU15HalfDepreciationOnDisposalInSecondYear(): void
    {
        $rows = $this->strategy->plan($this->ctx([
            'putIntoUseDate' => '2098-05-15',
            'disposalDate' => '2099-08-31',
        ]));

        self::assertCount(2, $rows);
        $last = $rows[1];
        self::assertSame(2099, $last['fiscal_year']);
        self::assertSame(80000.0, (float) $last['full_amount'], 'U15: ½ z 160 000 = 80 000.');
        self::assertTrue($last['is_half'], 'U15: is_half=1.');
    }

    public function testU16ImprovementOnFullyDepreciatedAsset(): void
    {
        $rows = $this->strategy->plan($this->ctx([
            'putIntoUseDate' => '2098-05-15',
            'improvements' => [['completed_on' => '2104-06-30', 'amount' => 100000.0]],
            'confirmedEntries' => [
                ['fiscal_year' => 2098, 'kind' => 'tax', 'amount' => 100000.0, 'full_amount' => 100000.0, 'is_paused' => false, 'is_half' => false],
                ['fiscal_year' => 2099, 'kind' => 'tax', 'amount' => 160000.0, 'full_amount' => 160000.0, 'is_paused' => false, 'is_half' => false],
                ['fiscal_year' => 2100, 'kind' => 'tax', 'amount' => 120000.0, 'full_amount' => 120000.0, 'is_paused' => false, 'is_half' => false],
                ['fiscal_year' => 2101, 'kind' => 'tax', 'amount' => 80000.0, 'full_amount' => 80000.0, 'is_paused' => false, 'is_half' => false],
                ['fiscal_year' => 2102, 'kind' => 'tax', 'amount' => 40000.0, 'full_amount' => 40000.0, 'is_paused' => false, 'is_half' => false],
            ],
        ]));

        $computed = array_values(array_filter($rows, static fn (array $r): bool => $r['source'] === 'computed'));
        self::assertSame(
            [40000.0, 30000.0, 20000.0, 10000.0],
            $this->fullAmounts($computed),
            'U16: TZ na plně odepsaném — 2×100 000/5=40 000; 30 000; 20 000; 10 000.',
        );
        self::assertSame([2104, 2105, 2106, 2107], array_map(static fn (array $r): int => (int) $r['fiscal_year'], $computed));
        self::assertSame(100000.0, round(array_sum($this->fullAmounts($computed)), 2), 'Σ nových odpisů = TZ 100 000.');
    }

    public function testYearRowMatchesPlanRow(): void
    {
        $row = $this->strategy->yearRow($this->ctx(), 2100);

        self::assertNotNull($row);
        self::assertSame(2100, $row['fiscal_year']);
        self::assertSame(120000.0, (float) $row['full_amount']);
    }
}
