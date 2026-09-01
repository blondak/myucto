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

final class CompanyBackupPayrollEmployeeProfilesProjectionTest extends TestCase
{
    /** @var list<string> */
    private const COLUMNS = [
        'supplier_id',
        'employee_id',
        'profile_status',
        'payout_method',
        'partner_settlement_account_code',
        'cash_allocation_basis_points',
        'payout_effective_on',
        'secure_delivery_channel',
        'row_version',
        'created_at',
        'updated_at',
    ];

    public function testDeclaresExactEmployeeProfileAndSettlementAccount(): void
    {
        $registry = TenantDataRegistryFactory::draftV1();
        $definition = $registry->definition('table:payroll_employee_profiles');
        self::assertNotNull($definition);
        $projection = CompanyBackupTableProjection::fromDefinition($definition);

        $projection->assertRuntimeSchema(
            self::COLUMNS,
            [],
            ['supplier_id', 'employee_id'],
        );
        $projection->references->assertRegistryTargets($registry);
        $projection->references->assertRuntimeSchema(
            new CompanyBackupTableReferenceSchema(
                [
                    'partner_settlement_account_code',
                    'payout_effective_on',
                ],
                [new CompanyBackupForeignKey(
                    ['supplier_id', 'employee_id'],
                    'payroll_employees',
                    ['supplier_id', 'id'],
                )],
            ),
        );

        self::assertSame(self::COLUMNS, $projection->dataColumns);
        self::assertSame(
            ['supplier_id', 'employee_id'],
            $definition->details['primary_key'] ?? null,
        );
        self::assertSame(
            [
                'supplier_id,employee_id->payroll_employees:supplier_id,id',
                'supplier_id,partner_settlement_account_code'
                    . '->chart_of_accounts:supplier_id,account_code',
            ],
            array_map(
                static fn ($reference): string => $reference->signature(),
                $projection->references->references,
            ),
        );
        $employee = $projection->references->references[0];
        self::assertSame(CompanyBackupReferenceMapping::TenantId, $employee->mapping);
        self::assertSame(
            CompanyBackupReferenceConstraint::Required,
            $employee->constraint,
        );
        $settlementAccount = $projection->references->references[1];
        self::assertSame(
            CompanyBackupReferenceMapping::TenantNaturalKey,
            $settlementAccount->mapping,
        );
        self::assertSame(
            CompanyBackupReferenceConstraint::Optional,
            $settlementAccount->constraint,
        );
        self::assertSame(
            ['partner_settlement_account_code'],
            $settlementAccount->nullableColumns,
        );
        self::assertSame([], $projection->restoreOverrides->overrides);
    }
}
