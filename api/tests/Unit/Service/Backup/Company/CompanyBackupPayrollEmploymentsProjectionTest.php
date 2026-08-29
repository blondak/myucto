<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup\Company;

use MyInvoice\Service\Backup\Company\CompanyBackupForeignKey;
use MyInvoice\Service\Backup\Company\CompanyBackupTableProjection;
use MyInvoice\Service\Backup\Company\CompanyBackupTableReferenceSchema;
use MyInvoice\Service\Backup\Registry\TenantDataRegistryFactory;
use PHPUnit\Framework\TestCase;

final class CompanyBackupPayrollEmploymentsProjectionTest extends TestCase
{
    /** @var list<string> */
    private const COLUMNS = [
        'id',
        'supplier_id',
        'employee_id',
        'office_id',
        'code',
        'relation_type',
        'status',
        'is_primary',
        'start_date',
        'actual_start_date',
        'end_date',
        'archived_at',
        'monthly_gross_minor',
        'is_legacy_projection',
        'legacy_projection_key',
        'row_version',
        'created_at',
        'updated_at',
        'primary_employee_key',
    ];

    /** @var list<string> */
    private const DATA_COLUMNS = [
        'id',
        'supplier_id',
        'employee_id',
        'office_id',
        'code',
        'relation_type',
        'status',
        'is_primary',
        'start_date',
        'actual_start_date',
        'end_date',
        'archived_at',
        'monthly_gross_minor',
        'is_legacy_projection',
        'row_version',
        'created_at',
        'updated_at',
    ];

    public function testDeclaresExactColumnsReferencesAndGeneratedOwnerKeys(): void
    {
        $registry = TenantDataRegistryFactory::draftV1();
        $definition = $registry->definition('table:payroll_employments');
        self::assertNotNull($definition);
        $projection = CompanyBackupTableProjection::fromDefinition($definition);

        $projection->assertRuntimeSchema(
            self::COLUMNS,
            ['legacy_projection_key', 'primary_employee_key'],
            ['id'],
        );
        $projection->references->assertRegistryTargets($registry);
        $projection->references->assertRuntimeSchema(
            new CompanyBackupTableReferenceSchema(
                [
                    'office_id',
                    'start_date',
                    'actual_start_date',
                    'end_date',
                    'archived_at',
                    'monthly_gross_minor',
                    'legacy_projection_key',
                    'primary_employee_key',
                ],
                [
                    new CompanyBackupForeignKey(
                        ['supplier_id', 'employee_id'],
                        'payroll_employees',
                        ['supplier_id', 'id'],
                    ),
                    new CompanyBackupForeignKey(
                        ['supplier_id', 'office_id'],
                        'payroll_offices',
                        ['supplier_id', 'id'],
                    ),
                ],
            ),
        );

        self::assertSame(self::DATA_COLUMNS, $projection->dataColumns);
        self::assertSame(
            ['legacy_projection_key', 'primary_employee_key'],
            $projection->generatedColumns,
        );
        self::assertSame(
            [
                'supplier_id,employee_id->payroll_employees:supplier_id,id',
                'supplier_id,office_id->payroll_offices:supplier_id,id',
            ],
            array_map(
                static fn ($reference): string => $reference->signature(),
                $projection->references->references,
            ),
        );
        self::assertSame(
            ['office_id'],
            $projection->references->references[1]->nullableColumns,
        );
        self::assertSame(
            [['supplier_id', 'id', 'employee_id']],
            $definition->details['reference_keys'] ?? null,
        );
    }
}
