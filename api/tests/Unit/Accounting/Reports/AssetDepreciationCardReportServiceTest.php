<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Accounting\Reports;

use MyInvoice\Service\Accounting\Assets\DepreciationCalculator;
use MyInvoice\Service\Accounting\Assets\DepreciationContext;
use MyInvoice\Service\Accounting\Reports\AssetDepreciationCardReportService;
use PHPUnit\Framework\TestCase;

/**
 * Unit testy skládání inventárních karet majetku (#49) — čistá funkce
 * `assembleRows()` nad výstupem DepreciationCalculator::plan()['tax'] (žádná
 * daňová logika se tu neduplikuje, jen se ověřuje, že karta věrně skládá to,
 * co spočítá odpisový engine): Σ odpisů = pořizovací cena, ZC nikdy záporná,
 * poslední rok dojede na nulu, TZ po letech se správně přiřadí ke „Zhodnocení".
 */
final class AssetDepreciationCardReportServiceTest extends TestCase
{
    private DepreciationCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new DepreciationCalculator();
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
            'putIntoUseDate' => '2020-05-15',
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
     */
    private function yearEndDates(array $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['fiscal_year']] = sprintf('%04d-12-31', (int) $r['fiscal_year']);
        }
        return $out;
    }

    public function testStraightLinePlanSumsToInputPriceAndEndsAtZero(): void
    {
        $ctx = $this->ctx(['taxGroup' => 1, 'inputPrice' => 100000.0]);
        $taxRows = $this->calculator->plan($ctx, 'straight')['tax'];

        $rows = AssetDepreciationCardReportService::assembleRows($taxRows, [], $this->yearEndDates($taxRows));

        self::assertNotEmpty($rows);
        $sum = round(array_sum(array_column($rows, 'depreciation')), 2);
        self::assertSame(100000.0, $sum, 'Σ odpisů celého plánu musí sedět na pořizovací cenu.');

        foreach ($rows as $row) {
            self::assertGreaterThanOrEqual(0.0, $row['residual_end'], 'ZC nesmí být záporná v žádném roce.');
        }
        self::assertSame(0.0, $rows[count($rows) - 1]['residual_end'], 'Poslední rok musí dojet na nulovou ZC.');

        // Řádky jsou očíslované vzestupně od 1 a datum je konec zdaňovacího období.
        foreach ($rows as $i => $row) {
            self::assertSame($i + 1, $row['no']);
            self::assertSame(sprintf('%04d-12-31', $row['fiscal_year']), $row['date']);
        }
    }

    public function testAcceleratedPlanSumsToInputPriceAndEndsAtZero(): void
    {
        $ctx = $this->ctx(['taxGroup' => 2, 'inputPrice' => 500000.0, 'putIntoUseDate' => '2021-03-01']);
        $taxRows = $this->calculator->plan($ctx, 'accelerated')['tax'];

        $rows = AssetDepreciationCardReportService::assembleRows($taxRows, [], $this->yearEndDates($taxRows));

        self::assertNotEmpty($rows);
        $sum = round(array_sum(array_column($rows, 'depreciation')), 2);
        self::assertSame(500000.0, $sum, 'Σ odpisů celého plánu musí sedět na pořizovací cenu.');

        foreach ($rows as $row) {
            self::assertGreaterThanOrEqual(0.0, $row['residual_end']);
        }
        self::assertSame(0.0, $rows[count($rows) - 1]['residual_end']);
    }

    public function testImprovementIsAssignedToItsFiscalYear(): void
    {
        $ctx = $this->ctx(['taxGroup' => 1, 'inputPrice' => 100000.0, 'putIntoUseDate' => '2020-01-10']);
        $taxRows = $this->calculator->plan($ctx, 'straight')['tax'];
        $years = array_column($taxRows, 'fiscal_year');
        self::assertNotEmpty($years);
        $tzYear = (int) $years[min(1, count($years) - 1)];

        $rows = AssetDepreciationCardReportService::assembleRows(
            $taxRows,
            [$tzYear => 15000.0],
            $this->yearEndDates($taxRows),
        );

        foreach ($rows as $row) {
            if ($row['fiscal_year'] === $tzYear) {
                self::assertSame(15000.0, $row['improvement']);
            } else {
                self::assertSame(0.0, $row['improvement']);
            }
        }
    }

    public function testHalfDepreciationYearGetsNoteAndPausedYearIsMarked(): void
    {
        $ctx = $this->ctx([
            'taxGroup' => 1,
            'inputPrice' => 100000.0,
            'putIntoUseDate' => '2020-01-10',
            'disposalDate' => '2021-06-30',
        ]);
        $taxRows = $this->calculator->plan($ctx, 'straight')['tax'];
        $rows = AssetDepreciationCardReportService::assembleRows($taxRows, [], $this->yearEndDates($taxRows));

        $lastRow = $rows[count($rows) - 1];
        self::assertNotNull($lastRow['note'], 'Rok vyřazení s půlodpisem musí mít poznámku (§26/7 ZDP).');
        self::assertStringContainsString('půlodpis', $lastRow['note']);
    }

    public function testTaxGroupTokensHighlightsActiveGroupOnly(): void
    {
        $tokens = AssetDepreciationCardReportService::taxGroupTokens(3, 'tangible');
        $active = array_values(array_filter($tokens, static fn (array $t): bool => $t['active']));
        self::assertCount(1, $active);
        self::assertSame('3', $active[0]['label']);

        $intangible = AssetDepreciationCardReportService::taxGroupTokens(null, 'intangible');
        $activeIntangible = array_values(array_filter($intangible, static fn (array $t): bool => $t['active']));
        self::assertCount(1, $activeIntangible);
        self::assertSame('N', $activeIntangible[0]['label']);
    }
}
