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

final class CompanyBackupPayrollComponentDefinitionsProjectionTest extends TestCase
{
    /** @var list<string> */
    private const COLUMNS = [
        'id',
        'supplier_id',
        'code',
        'name',
        'component_kind',
        'value_kind',
        'frequency_kind',
        'tax_treatment',
        'social_participation_treatment',
        'social_treatment',
        'health_participation_treatment',
        'health_treatment',
        'average_earning_treatment',
        'enforcement_treatment',
        'jmhz_treatment',
        'statistics_treatment',
        'accounting_debit_code',
        'accounting_credit_code',
        'annual_limit_minor',
        'exemption_basket',
        'exemption_basis',
        'valid_from',
        'valid_to',
        'is_active',
        'row_version',
        'created_at',
        'updated_at',
    ];

    public function testDeclaresExactColumnsAccountsAndEffectiveNaturalKey(): void
    {
        $registry = TenantDataRegistryFactory::draftV1();
        $definition = $registry->definition('table:payroll_component_definitions');
        self::assertNotNull($definition);
        $projection = CompanyBackupTableProjection::fromDefinition($definition);

        $projection->assertRuntimeSchema(self::COLUMNS, [], ['id']);
        $projection->references->assertRegistryTargets($registry);
        $projection->references->assertRuntimeSchema(
            new CompanyBackupTableReferenceSchema(
                [
                    'accounting_debit_code',
                    'accounting_credit_code',
                    'annual_limit_minor',
                    'exemption_basket',
                    'exemption_basis',
                    'valid_to',
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
            ['supplier_id', 'code', 'valid_from'],
            $definition->details['natural_key'] ?? null,
        );
        self::assertSame(
            [
                'supplier_id,accounting_credit_code'
                    . '->chart_of_accounts:supplier_id,account_code',
                'supplier_id,accounting_debit_code'
                    . '->chart_of_accounts:supplier_id,account_code',
                'supplier_id->supplier:id',
            ],
            array_map(
                static fn ($reference): string => $reference->signature(),
                $projection->references->references,
            ),
        );
        foreach (array_slice($projection->references->references, 0, 2) as $account) {
            self::assertSame(
                CompanyBackupReferenceMapping::TenantNaturalKey,
                $account->mapping,
            );
            self::assertSame(
                CompanyBackupReferenceConstraint::Optional,
                $account->constraint,
            );
        }
        self::assertSame(
            ['accounting_credit_code'],
            $projection->references->references[0]->nullableColumns,
        );
        self::assertSame(
            ['accounting_debit_code'],
            $projection->references->references[1]->nullableColumns,
        );
    }
}
