<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Accounting\Dimension;

use MyInvoice\Service\Accounting\Reports\DimensionAnalyticsTables;
use MyInvoice\Service\Accounting\Reports\ReportXlsxExporter;
use MyInvoice\Service\Pdf\DimensionPdfRenderer;
use PHPUnit\Framework\TestCase;

final class DimensionExportTest extends TestCase
{
    public function testTablesFollowSelectedValueAndAddTotalWithoutZeroRows(): void
    {
        $report = [
            'type' => ['name' => 'Projekt'], 'year' => 2094, 'supplier_ids' => [1, 2],
            'totals' => self::amounts(100, 50),
            'rows' => [
                ['value_id' => 10, 'code' => 'P', 'name' => 'Projekt', 'depth' => 0],
                ['value_id' => 11, 'code' => 'Z', 'name' => 'Prázdný', 'depth' => 0],
            ],
            'value_totals' => (object) ['10' => self::amounts(100, 40), '11' => self::amounts(0, 0), '' => self::amounts(0, 10)],
            'companies' => [['id' => 1, 'name' => 'Firma A'] + self::amounts(100, 0), ['id' => 2, 'name' => 'Firma B'] + self::amounts(0, 50)],
            'company_value_totals' => (object) ['1' => (object) ['10' => self::amounts(100, 0)], '2' => (object) ['10' => self::amounts(0, 40)]],
            'monthly' => [['month' => '2094-01'] + self::amounts(0, 0), ['month' => '2094-02'] + self::amounts(100, 50)],
            'value_monthly' => (object) ['10' => [['month' => '2094-01'] + self::amounts(0, 0), ['month' => '2094-02'] + self::amounts(100, 40)]],
        ];

        $comparison = DimensionAnalyticsTables::build($report, 'comparison');
        self::assertCount(2, $comparison['rows']);
        self::assertSame('Celkem', $comparison['totals'][0]);
        self::assertEqualsWithDelta(100, $comparison['totals'][1], 0.001);
        self::assertEqualsWithDelta(50, $comparison['totals'][2], 0.001);

        $companies = DimensionAnalyticsTables::build($report, 'companies', '10');
        self::assertCount(2, $companies['rows']);
        self::assertEqualsWithDelta(40, $companies['totals'][2], 0.001);

        $monthly = DimensionAnalyticsTables::build($report, 'monthly', '10');
        self::assertCount(1, $monthly['rows']);
        self::assertSame('2094-02', $monthly['rows'][0][0]);
        self::assertEqualsWithDelta(60, $monthly['totals'][3], 0.001);

        self::assertStringStartsWith('PK', (new ReportXlsxExporter())->dimensionAnalyticsTable($companies)['bytes']);
        $pdf = new DimensionPdfRenderer();
        self::assertStringStartsWith('%PDF', $pdf->renderTable($comparison));
        self::assertStringStartsWith('%PDF', $pdf->render([
            'type' => ['name' => 'Projekt'], 'from' => '2094-01-01', 'to' => '2094-12-31',
            'entity' => ['name' => 'Testovací firma'], 'supplier_ids' => [1], 'restricted' => false,
            'rows' => [['code' => 'P', 'name' => 'Projekt', 'depth' => 0, 'responsible_user_name' => null, 'total' => self::amounts(100, 40)]],
            'unassigned' => self::amounts(0, 10), 'totals' => self::amounts(100, 50),
            'matrix' => ['columns' => [['value_id' => 10, 'code' => 'P', 'name' => 'Projekt']], 'rows' => [['code' => '602', 'name' => 'Výnosy', 'cells' => [100]]]],
        ]));
    }

    private static function amounts(float $revenue, float $cost): array
    {
        return [
            'revenue' => $revenue, 'cost' => $cost, 'result' => $revenue - $cost,
            'tax_deductible_cost' => $cost, 'non_deductible_cost' => 0, 'income_tax_cost' => 0,
        ];
    }
}
