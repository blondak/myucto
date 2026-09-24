<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Tax\Return;

use MyInvoice\Service\Tax\Return\DppoEpoXmlParser;
use MyInvoice\Service\Tax\Return\DppoReconciliationDiffBuilder;
use MyInvoice\Service\Tax\Return\DppoReturnCalculator;
use MyInvoice\Service\Tax\Return\DppoXmlBuilder;
use MyInvoice\Service\Tax\TaxConstants;
use PHPUnit\Framework\TestCase;

/**
 * Ruční položka § 23 se zařazením na konkrétní řádek (`line`) se vykáže na tom řádku,
 * ne na obecném ř. 62/162; základ daně se tím nemění.
 */
final class DppoManualItemLineTest extends TestCase
{
    private const DATA = ['vh' => 1_000_000, 'non_deductible_costs' => 10_000];

    private const INPUTS = [
        'manual_increase_items' => [
            ['text' => 'Nepeněžní plnění', 'amount' => 120_000, 'line' => 30],
            ['text' => 'Nedaňové výdaje nad účty', 'amount' => 5_000, 'line' => 40],
            ['text' => 'Pokuta', 'amount' => 1_000],
        ],
        'manual_decrease_items' => [
            ['text' => 'Dividendy od dceřiné společnosti', 'amount' => 300_000, 'line' => 110],
            ['text' => 'Rozpuštění nedaňové opravné položky', 'amount' => 20_000, 'line' => 112],
            ['text' => 'Ostatní', 'amount' => 700, 'line' => 162],
            ['text' => 'Neplatný řádek', 'amount' => 50, 'line' => 150],
        ],
    ];

    private function lines(array $inputs): array
    {
        $calc = (new DppoReturnCalculator())->compute(self::DATA, $inputs, TaxConstants::forYear(2025));
        return array_column($calc['lines'], 'value', 'line');
    }

    public function testItemsAreReportedOnTheirLinesWithUnchangedBase(): void
    {
        $withLines = $this->lines(self::INPUTS);
        $strip = static fn (array $items): array => array_map(static fn (array $i): array => array_diff_key($i, ['line' => true]), $items);
        $withoutLines = $this->lines(['manual_increase_items' => $strip(self::INPUTS['manual_increase_items']), 'manual_decrease_items' => $strip(self::INPUTS['manual_decrease_items'])]);

        self::assertSame(120000.0, $withLines[30]);
        self::assertSame(15000.0, $withLines[40]);
        self::assertSame(1000.0, $withLines[62]);
        self::assertSame(136000.0, $withLines[70]);
        self::assertSame(300000.0, $withLines[110]);
        self::assertSame(20000.0, $withLines[112]);
        self::assertSame(750.0, $withLines[162], 'ř. 150 se ruční položce nepřiřazuje, zůstane na ř. 162');
        self::assertSame(320750.0, $withLines[170]);
        self::assertArrayNotHasKey(20, $withLines, 'prázdný řádek ruční položky se nevypisuje');

        self::assertSame($withoutLines[200], $withLines[200]);
        self::assertSame($withoutLines[70], $withLines[70]);
        self::assertSame($withoutLines[170], $withLines[170]);
        self::assertArrayNotHasKey(30, $withoutLines);
        self::assertSame(126000.0, $withoutLines[62]);
    }

    public function testXmlCarriesTheLinesAndAnAppendixAndRoundTripsWithoutDifferences(): void
    {
        $calc = (new DppoReturnCalculator())->compute(self::DATA, self::INPUTS, TaxConstants::forYear(2025));
        $xml = (new DppoXmlBuilder())->build([
            'company_name' => 'Ukázková firma s.r.o.', 'street' => 'Zkušební 1', 'city' => 'Vzorov', 'zip' => '10000',
            'country_iso2' => 'CZ', 'ic' => '12345678', 'dic' => 'CZ12345678', 'taxpayer_type' => 'po', 'financial_office_code' => '451',
        ], 2025, $calc)['xml'];

        self::assertStringContainsString('kc_ii40_30="120000"', $xml);
        self::assertStringContainsString('kc_ii120_110="300000"', $xml);
        self::assertMatchesRegularExpression('/<VetaR[^>]*c_radku="30"[^>]*t_prilohy="Nepeněžní plnění \(120 000 Kč\)"/u', $xml);
        self::assertMatchesRegularExpression('/<VetaR[^>]*c_radku="110"/', $xml);

        $parsed = (new DppoEpoXmlParser())->parse($xml);
        $diff = (new DppoReconciliationDiffBuilder())->build($calc['lines'], $parsed['lines']);
        self::assertSame(0, $diff['mismatched']);
    }

    public function testFiledLineMissingInOurReturnIsComparedAgainstZero(): void
    {
        $calc = (new DppoReturnCalculator())->compute(self::DATA, [], TaxConstants::forYear(2025));
        $diff = (new DppoReconciliationDiffBuilder())->build($calc['lines'], [10 => 1_000_000.0, 30 => 120_000.0]);

        $rows = array_column($diff['rows'], null, 'line');
        self::assertArrayHasKey(30, $rows, 'řádek jen v podání se nesmí ztratit');
        self::assertSame(0.0, $rows[30]['our_value']);
        self::assertSame(-120000.0, $rows[30]['diff']);
        self::assertFalse($rows[30]['match']);
        self::assertSame([10, 30, 40], array_slice(array_column($diff['rows'], 'line'), 0, 3));
    }
}
