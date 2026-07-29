<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Accounting\Assets;

use MyInvoice\Service\Accounting\Assets\DepreciationContext;
use MyInvoice\Service\Accounting\Assets\Strategy\TaxExtraordinaryStrategy;
use PHPUnit\Framework\TestCase;

/**
 * Unit testy mimořádných odpisů §30a ZDP — bezemisní vozidla 2024–2028 (Epic F3,
 * spec §6.1 U17–U19). Roky jsou reálné (podmínka pořízení 1. 1. 2024 – 31. 12. 2028);
 * čisté unit testy bez DB, žádný úklid není potřeba.
 */
final class TaxExtraordinaryStrategyTest extends TestCase
{
    private TaxExtraordinaryStrategy $strategy;

    protected function setUp(): void
    {
        $this->strategy = new TaxExtraordinaryStrategy();
    }

    /**
     * @param array<string,mixed> $overrides
     */
    private function ctx(array $overrides = []): DepreciationContext
    {
        $args = array_merge([
            'inputPrice' => 1200000.0,
            'taxGroup' => null,
            'firstYearIncrease' => 'none',
            'isFirstOwner' => true,
            'isM1Vehicle' => false,
            'm1LimitException' => false,
            'putIntoUseDate' => '2026-03-15',
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
     * Všechny měsíční částky napříč roky v pořadí.
     *
     * @param list<array<string,mixed>> $rows
     * @return list<array{month:string, amount:float}>
     */
    private function allMonths(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            foreach ((array) $row['months'] as $m) {
                $out[] = ['month' => (string) $m['month'], 'amount' => (float) $m['amount']];
            }
        }
        return $out;
    }

    public function testU17TwentyFourMonthsFromMonthAfterPutIntoUse(): void
    {
        $rows = $this->strategy->plan($this->ctx());

        self::assertCount(3, $rows, 'U17: roky 2026, 2027, 2028.');
        self::assertSame([2026, 2027, 2028], array_map(static fn (array $r): int => (int) $r['fiscal_year'], $rows));
        self::assertSame([540000.0, 540000.0, 120000.0], array_map(static fn (array $r): float => (float) $r['amount'], $rows),
            'U17: 2026 = 9× 60 000; 2027 = 3× 60 000 + 9× 40 000; 2028 = 3× 40 000.');
        self::assertSame([9, 12, 3], array_map(static fn (array $r): int => (int) $r['months_count'], $rows));

        $months = $this->allMonths($rows);
        self::assertCount(24, $months, 'U17: detail 24 měsíců.');
        self::assertSame('2026-04', $months[0]['month'], 'Začíná měsícem následujícím po zařazení (4/2026).');
        self::assertSame('2028-03', $months[23]['month']);
        self::assertSame(1200000.0, round(array_sum(array_column($months, 'amount')), 2), 'Σ 24 měsíců = VC.');
    }

    public function testU18RoundingCompensationInLastMonthOfEachPhase(): void
    {
        $rows = $this->strategy->plan($this->ctx(['inputPrice' => 999999.0]));
        $months = $this->allMonths($rows);

        self::assertCount(24, $months);
        $phase1 = array_slice($months, 0, 12);
        $phase2 = array_slice($months, 12, 12);

        self::assertSame(array_fill(0, 11, 50000.0), array_column(array_slice($phase1, 0, 11), 'amount'), 'U18: fáze 1 = 11× 50 000.');
        self::assertSame(50000.0, $phase1[11]['amount'], 'U18: 12. měsíc dorovnává fázi 1 na 600 000.');
        self::assertSame(600000.0, round(array_sum(array_column($phase1, 'amount')), 2), 'U18: úhrn fáze 1 = ceil(0,60 × 999 999) = 600 000.');

        self::assertSame(array_fill(0, 11, 33334.0), array_column(array_slice($phase2, 0, 11), 'amount'), 'U18: fáze 2 = 11× 33 334.');
        self::assertSame(33325.0, $phase2[11]['amount'], 'U18: poslední měsíc = dorovnání 33 325.');
        self::assertSame(399999.0, round(array_sum(array_column($phase2, 'amount')), 2), 'U18: úhrn fáze 2 = 399 999.');

        self::assertSame(999999.0, round(array_sum(array_column($months, 'amount')), 2), 'U18: Σ = přesně VC (R7).');
    }

    public function testU19DisposalStopsMonthBeforeDisposalMonth(): void
    {
        $rows = $this->strategy->plan($this->ctx(['disposalDate' => '2027-08-10']));

        self::assertCount(2, $rows, 'U19: jen 2026 a 2027.');
        self::assertSame([2026, 2027], array_map(static fn (array $r): int => (int) $r['fiscal_year'], $rows));

        $y2027 = $rows[1];
        self::assertSame(340000.0, (float) $y2027['amount'], 'U19: 2027 = 3× 60 000 + 4× 40 000 = 340 000.');
        self::assertSame(7, (int) $y2027['months_count'], 'U19: odpisy naposledy za 7/2027.');
        self::assertFalse($y2027['is_half'], 'U19: půlodpis §26/7 se u §30a nepoužije.');

        $months = $this->allMonths([$y2027]);
        self::assertSame('2027-07', $months[count($months) - 1]['month'], 'Poslední měsíc = měsíc předcházející měsíci vyřazení.');
    }

    public function testYearRowMatchesPlanRow(): void
    {
        $row = $this->strategy->yearRow($this->ctx(), 2027);

        self::assertNotNull($row);
        self::assertSame(2027, $row['fiscal_year']);
        self::assertSame(540000.0, (float) $row['amount']);
    }
}
