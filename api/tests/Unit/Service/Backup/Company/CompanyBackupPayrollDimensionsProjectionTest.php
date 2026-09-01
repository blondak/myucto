<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup\Company;

use MyInvoice\Service\Backup\Company\CompanyBackupForeignKey;
use MyInvoice\Service\Backup\Company\CompanyBackupReferenceConstraint;
use MyInvoice\Service\Backup\Company\CompanyBackupReferenceMapping;
use MyInvoice\Service\Backup\Company\CompanyBackupTableProjection;
use MyInvoice\Service\Backup\Company\CompanyBackupTableReferenceSchema;
use MyInvoice\Service\Backup\Registry\TenantDataRegistryFactory;
use PHPUnit\Framework\TestCase;

final class CompanyBackupPayrollDimensionsProjectionTest extends TestCase
{
    /** @var list<string> */
    private const COLUMNS = [
        'id',
        'supplier_id',
        'dimension_type',
        'code',
        'name',
        'valid_from',
        'valid_to',
        'is_active',
        'default_account_code',
        'created_by',
        'updated_by',
        'row_version',
        'created_at',
        'updated_at',
    ];

    public function testDeclaresExactPayrollDimensionProjection(): void
    {
        $registry = TenantDataRegistryFactory::draftV1();
        $definition = $registry->definition('table:payroll_dimensions');
        self::assertNotNull($definition);
        $projection = CompanyBackupTableProjection::fromDefinition($definition);

        $projection->assertRuntimeSchema(self::COLUMNS, [], ['id']);
        $projection->references->assertRegistryTargets($registry);
        $projection->references->assertRuntimeSchema(
            new CompanyBackupTableReferenceSchema(
                [
                    'valid_to',
                    'default_account_code',
                    'created_by',
                    'updated_by',
                ],
                [new CompanyBackupForeignKey(
                    ['supplier_id'],
                    'supplier',
                    ['id'],
                )],
            ),
        );

        self::assertSame(self::COLUMNS, $projection->dataColumns);
        self::assertArrayNotHasKey('natural_key', $definition->details);
        self::assertSame(
            [
                'created_by->users:id',
                'supplier_id,default_account_code'
                    . '->chart_of_accounts:supplier_id,account_code',
                'supplier_id->supplier:id',
                'updated_by->users:id',
            ],
            array_map(
                static fn ($reference): string => $reference->signature(),
                $projection->references->references,
            ),
        );
        $createdBy = $projection->references->references[0];
        self::assertSame(CompanyBackupReferenceMapping::Actor, $createdBy->mapping);
        self::assertSame(
            CompanyBackupReferenceConstraint::Optional,
            $createdBy->constraint,
        );
        self::assertSame(['created_by'], $createdBy->nullableColumns);
        self::assertSame(['null', 'restore_actor'], $createdBy->fallbacks);
        $account = $projection->references->references[1];
        self::assertSame(
            CompanyBackupReferenceMapping::TenantNaturalKey,
            $account->mapping,
        );
        self::assertSame(
            CompanyBackupReferenceConstraint::Optional,
            $account->constraint,
        );
        self::assertSame(
            ['default_account_code'],
            $account->nullableColumns,
        );
        $updatedBy = $projection->references->references[3];
        self::assertSame(CompanyBackupReferenceMapping::Actor, $updatedBy->mapping);
        self::assertSame(['updated_by'], $updatedBy->nullableColumns);
        self::assertSame(['null', 'restore_actor'], $updatedBy->fallbacks);
        self::assertSame([], $projection->restoreOverrides->overrides);
    }
}
