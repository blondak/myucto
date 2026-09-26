<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Migration;

use MyInvoice\Service\Payroll\Migration\PayrollTakeoverLayerCheck;
use MyInvoice\Service\Payroll\Migration\PayrollTakeoverMonth;
use PHPUnit\Framework\TestCase;

/**
 * Počáteční stavy (A) a převzaté mzdy (B) za týž převzatý měsíc musí tvrdit
 * totéž — jinak roční zúčtování počítá s jiným základem, než jaký šel do ELDP.
 */
final class PayrollTakeoverLayerCheckTest extends TestCase
{
    public function testMetricNamesMatchTheComparedMetrics(): void
    {
        self::assertSame(
            array_keys(PayrollTakeoverLayerCheck::METRICS),
            PayrollTakeoverLayerCheck::METRIC_NAMES,
        );
    }

    public function testMatchingLayersProduceNoFindings(): void
    {
        $result = PayrollTakeoverLayerCheck::compare(
            [7 => [1 => self::opening()]],
            [7 => [1 => [self::wage('other')]]],
            2026,
        );

        self::assertSame([], $result['differences']);
        self::assertSame([], $result['opening_only']);
        self::assertSame([], $result['takeover_only']);
    }

    public function testDifferencesAreReportedPerMetricAndMissingSideSeparately(): void
    {
        $opening = self::opening();
        $opening['advance_tax_minor_units'] = 3_500_00;
        $opening['social_assessment_base_minor_units'] = 41_000_00;

        $result = PayrollTakeoverLayerCheck::compare(
            [7 => [1 => $opening, 2 => self::opening()]],
            [7 => [1 => [self::wage('other')], 3 => [self::wage('other', '2026-03')]]],
            2026,
        );

        self::assertSame(
            [['advance_tax', 7_000], ['social_base', 100_000]],
            array_map(
                static fn (array $row): array => [$row['metric'], $row['difference_minor']],
                $result['differences'],
            ),
        );
        self::assertSame(['2026-02'], $result['opening_only'][0]['periods']);
        self::assertSame(['2026-03'], $result['takeover_only'][0]['periods']);
    }

    /** Hlášení JMHZ zdravotní základ nenese; jeho nula není rozdíl. */
    public function testHealthBaseIsNotComparedAgainstJmhzSource(): void
    {
        $opening = self::opening();
        $opening['health_assessment_base_minor_units'] = 40_000_00;

        $jmhz = PayrollTakeoverLayerCheck::compare(
            [7 => [1 => $opening]],
            [7 => [1 => [self::wage('jmhz', health: 0)]]],
            2026,
        );
        self::assertSame([], $jmhz['differences']);

        $table = PayrollTakeoverLayerCheck::compare(
            [7 => [1 => $opening]],
            [7 => [1 => [self::wage('other', health: 0)]]],
            2026,
        );
        self::assertSame('health_base', $table['differences'][0]['metric']);
    }

    /** @return array<string,int> */
    private static function opening(): array
    {
        return [
            'month' => 1,
            'social_assessment_base_minor_units' => 40_000_00,
            'advance_base_minor_units' => 40_000_00,
            'advance_tax_minor_units' => 3_430_00,
            'withholding_base_minor_units' => 0,
            'withholding_tax_minor_units' => 0,
            'tax_bonus_minor_units' => 0,
        ];
    }

    private static function wage(string $source, string $period = '2026-01', int $health = 40_000_00): PayrollTakeoverMonth
    {
        return PayrollTakeoverMonth::fromRow([
            'period_start' => $period . '-01',
            'source' => $source,
            'external_person_ref' => 'employee:7',
            'external_relationship_ref' => 'employment:9',
            'employee_id' => 7,
            'employment_id' => 9,
            'gross_minor' => 40_000_00,
            'social_base_minor' => 40_000_00,
            'health_base_minor' => $health,
            'advance_tax_minor' => 3_430_00,
            'withholding_tax_minor' => 0,
            'tax_bonus_minor' => 0,
        ]);
    }
}
