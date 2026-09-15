<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup\Company;

use MyInvoice\Service\Backup\Company\CompanyBackupReference;
use MyInvoice\Service\Backup\Company\CompanyBackupReferenceConstraint;
use MyInvoice\Service\Backup\Company\CompanyBackupReferenceMapping;
use MyInvoice\Service\Backup\Company\CompanyBackupReferenceSet;
use MyInvoice\Service\Backup\Company\CompanyBackupWorkReportItemsProjection;
use MyInvoice\Service\Backup\Company\CompanyBackupWorkReportMaterialsProjection;
use MyInvoice\Service\Backup\Company\CompanyBackupWorkReportsProjection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CompanyBackupWorkReportProjectionsTest extends TestCase
{
    /** @return array<string,array{class-string,array<string,int|string|null>,list<string>}> */
    public static function tables(): array
    {
        return [
            'work_reports' => [CompanyBackupWorkReportsProjection::class, [
                'id' => 7, 'invoice_id' => 11, 'project_id' => 13,
                'title' => 'Syntetický výkaz', 'total_hours' => '3.50',
                'total_amount' => '431.97', 'vat_rate_id' => 17,
                'material_title' => null, 'material_total' => '102.01',
                'material_vat_rate_id' => 19,
                'created_at' => '2025-12-31 23:59:00', 'updated_at' => '2026-01-01 00:01:00',
            ], [
                'invoice_id->invoices:id', 'material_vat_rate_id->vat_rates:id',
                'project_id->projects:id', 'vat_rate_id->vat_rates:id',
            ]],
            'work_report_items' => [CompanyBackupWorkReportItemsProjection::class, [
                'id' => 23, 'work_report_id' => 7, 'description' => 'Syntetická práce',
                'work_date' => '2025-12-31', 'hours' => '3.50', 'rate' => '123.42',
                'total_amount' => '431.97', 'order_index' => 2,
            ], ['work_report_id->work_reports:id']],
            'work_report_materials' => [CompanyBackupWorkReportMaterialsProjection::class, [
                'id' => 29, 'work_report_id' => 7, 'description' => 'Syntetický materiál',
                'quantity' => '1.125', 'unit' => 'm', 'unit_price' => '90.67',
                'total_amount' => '102.01', 'order_index' => 3,
            ], ['work_report_id->work_reports:id']],
        ];
    }

    /**
     * @param class-string $projectionClass
     * @param array<string,int|string|null> $row
     * @param list<string> $signatures
     */
    #[DataProvider('tables')]
    public function testRemapsReferencesAndPreservesEverySavedValue(
        string $projectionClass,
        array $row,
        array $signatures,
    ): void {
        self::assertSame(array_keys($row), $projectionClass::dataColumns());
        $refs = CompanyBackupReferenceSet::fromArray($projectionClass::references(), 'table:synthetic_work_report');
        self::assertSame($signatures, array_map(
            static fn (CompanyBackupReference $ref): string => $ref->signature(), $refs->references,
        ));
        $expected = $row;
        foreach ($refs->references as $ref) {
            $column = $ref->firstColumn();
            self::assertSame(CompanyBackupReferenceConstraint::Required, $ref->constraint);
            self::assertSame([], $ref->fallbacks);
            self::assertSame(
                in_array($column, ['project_id', 'vat_rate_id', 'material_vat_rate_id'], true) ? [$column] : [],
                $ref->nullableColumns,
            );
            self::assertSame(
                in_array($column, ['vat_rate_id', 'material_vat_rate_id'], true)
                    ? CompanyBackupReferenceMapping::GlobalNaturalKey : CompanyBackupReferenceMapping::TenantId,
                $ref->mapping,
            );
            self::assertIsInt($expected[$column]);
            $expected[$column] += $ref->mapping === CompanyBackupReferenceMapping::GlobalNaturalKey ? 1000 : 100;
        }
        $original = $row;
        $mapped = $refs->remap($row, static function (CompanyBackupReference $ref, array $key): array {
            self::assertIsInt($key[0]);
            return [$key[0] + ($ref->mapping === CompanyBackupReferenceMapping::GlobalNaturalKey ? 1000 : 100)];
        });
        self::assertSame($expected, $mapped);
        self::assertSame($original, $row);
    }

    public function testNullProjectAndRatesDoNotTriggerLookupOrDefaultRate(): void
    {
        $row = self::tables()['work_reports'][1];
        foreach (['project_id', 'vat_rate_id', 'material_vat_rate_id'] as $column) {
            $row[$column] = null;
        }
        $refs = CompanyBackupReferenceSet::fromArray(CompanyBackupWorkReportsProjection::references(), 'table:work_reports');
        $lookups = [];
        $mapped = $refs->remap($row, static function (CompanyBackupReference $ref, array $key) use (&$lookups): array {
            $lookups[] = $ref->firstColumn();
            return [111];
        });
        self::assertSame(['invoice_id'], $lookups);
        $row['invoice_id'] = 111;
        self::assertSame($row, $mapped);
    }

    public function testUndatedWorkRemainsUndated(): void
    {
        $row = self::tables()['work_report_items'][1];
        $row['work_date'] = null;
        $refs = CompanyBackupReferenceSet::fromArray(CompanyBackupWorkReportItemsProjection::references(), 'table:work_report_items');
        $mapped = $refs->remap($row, static fn (CompanyBackupReference $ref, array $key): array => [107]);
        $row['work_report_id'] = 107;
        self::assertSame($row, $mapped);
    }
}
