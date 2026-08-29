<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup\Company;

use MyInvoice\Service\Backup\Company\CompanyBackupForeignKey;
use MyInvoice\Service\Backup\Company\CompanyBackupTableProjection;
use MyInvoice\Service\Backup\Company\CompanyBackupTableReferenceSchema;
use MyInvoice\Service\Backup\Company\CompanyBackupTenantSqlSelector;
use MyInvoice\Service\Backup\Registry\TenantDataRegistryFactory;
use PHPUnit\Framework\TestCase;

final class CompanyBackupPayrollRunsProjectionTest extends TestCase
{
    /** @var list<string> */
    private const COLUMNS = [
        'id',
        'supplier_id',
        'office_id',
        'office_scope_id',
        'period_start',
        'payment_date',
        'status',
        'current_revision_no',
        'row_version',
        'created_by',
        'updated_by',
        'created_at',
        'updated_at',
    ];

    /** @var list<string> */
    private const DATA_COLUMNS = [
        'id',
        'supplier_id',
        'office_id',
        'period_start',
        'payment_date',
        'status',
        'current_revision_no',
        'row_version',
        'created_by',
        'updated_by',
        'created_at',
        'updated_at',
    ];

    public function testDeclaresExactColumnsGeneratedScopeAndReferences(): void
    {
        $registry = TenantDataRegistryFactory::draftV1();
        $definition = $registry->definition('table:payroll_runs');
        self::assertNotNull($definition);
        $projection = CompanyBackupTableProjection::fromDefinition($definition);

        $projection->assertRuntimeSchema(
            self::COLUMNS,
            ['office_scope_id'],
            ['id'],
        );
        $projection->references->assertRegistryTargets($registry);
        $projection->references->assertRuntimeSchema(
            new CompanyBackupTableReferenceSchema(
                ['created_by', 'office_id', 'updated_by'],
                [
                    new CompanyBackupForeignKey(
                        ['created_by'],
                        'users',
                        ['id'],
                    ),
                    new CompanyBackupForeignKey(
                        ['supplier_id', 'office_id'],
                        'payroll_offices',
                        ['supplier_id', 'id'],
                    ),
                    new CompanyBackupForeignKey(
                        ['supplier_id'],
                        'supplier',
                        ['id'],
                    ),
                    new CompanyBackupForeignKey(
                        ['updated_by'],
                        'users',
                        ['id'],
                    ),
                ],
            ),
        );

        self::assertSame(self::DATA_COLUMNS, $projection->dataColumns);
        self::assertSame(['office_scope_id'], $projection->generatedColumns);
        self::assertSame(
            [
                'created_by->users:id',
                'supplier_id,office_id->payroll_offices:supplier_id,id',
                'supplier_id->supplier:id',
                'updated_by->users:id',
            ],
            array_map(
                static fn ($reference): string => $reference->signature(),
                $projection->references->references,
            ),
        );
        self::assertSame(
            ['null', 'restore_actor'],
            $projection->references->references[0]->fallbacks,
        );
        self::assertSame(
            ['office_id'],
            $projection->references->references[1]->nullableColumns,
        );

        $selection = (new CompanyBackupTenantSqlSelector())->select(
            $projection,
            37,
        );
        self::assertSame([37], $selection->params);
        self::assertStringContainsString(
            '`_company_source`.`supplier_id` = ?',
            $selection->where,
        );
    }
}
