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

final class CompanyBackupPayrollEmployeesProjectionTest extends TestCase
{
    /** @var list<string> */
    private const COLUMNS = [
        'id',
        'supplier_id',
        'full_name',
        'birth_date',
        'birth_number',
        'address',
        'taxpayer_type',
        'employment_type',
        'tax_declaration_signed',
        'tax_credit_taxpayer',
        'child_count',
        'net_settlement_account_code',
        'monthly_gross',
        'auto_post',
        'is_active',
        'created_at',
        'updated_at',
    ];

    public function testDeclaresExactColumnsAccountReferenceAndSafeAutomation(): void
    {
        $registry = TenantDataRegistryFactory::draftV1();
        $definition = $registry->definition('table:payroll_employees');
        self::assertNotNull($definition);
        $projection = CompanyBackupTableProjection::fromDefinition($definition);

        $projection->assertRuntimeSchema(self::COLUMNS, [], ['id']);
        $projection->references->assertRegistryTargets($registry);
        $projection->references->assertRuntimeSchema(
            new CompanyBackupTableReferenceSchema(
                [
                    'birth_date',
                    'birth_number',
                    'address',
                    'net_settlement_account_code',
                    'monthly_gross',
                ],
                [
                    new CompanyBackupForeignKey(
                        ['supplier_id'],
                        'supplier',
                        ['id'],
                    ),
                ],
            ),
        );

        self::assertSame(self::COLUMNS, $projection->dataColumns);
        self::assertSame(
            [
                'supplier_id,net_settlement_account_code'
                    . '->chart_of_accounts:supplier_id,account_code',
                'supplier_id->supplier:id',
            ],
            array_map(
                static fn ($reference): string => $reference->signature(),
                $projection->references->references,
            ),
        );
        $account = $projection->references->references[0];
        self::assertSame(
            CompanyBackupReferenceMapping::TenantNaturalKey,
            $account->mapping,
        );
        self::assertSame(CompanyBackupReferenceConstraint::Optional, $account->constraint);
        self::assertSame(
            ['net_settlement_account_code'],
            $account->nullableColumns,
        );

        $restored = $projection->restoreOverrides->apply([
            'auto_post' => 1,
            'is_active' => 1,
            'monthly_gross' => 75_000,
        ]);
        self::assertSame(0, $restored['auto_post']);
        self::assertSame(1, $restored['is_active']);
        self::assertSame(75_000, $restored['monthly_gross']);
    }
}
