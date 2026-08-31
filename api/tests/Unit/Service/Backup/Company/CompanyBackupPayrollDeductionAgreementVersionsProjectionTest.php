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

final class CompanyBackupPayrollDeductionAgreementVersionsProjectionTest extends TestCase
{
    /** @var list<string> */
    private const COLUMNS = [
        'id',
        'supplier_id',
        'agreement_id',
        'employee_id',
        'version_no',
        'change_kind',
        'title',
        'deduction_kind',
        'status',
        'priority_no',
        'requested_minor',
        'basis_points',
        'basis_amount_minor',
        'total_limit_minor',
        'withheld_total_minor',
        'valid_from',
        'valid_to',
        'recipient_reference',
        'note',
        'effective_from',
        'reason',
        'actor_user_id',
        'created_at',
    ];

    public function testDeclaresExactImmutableAgreementVersionProjection(): void
    {
        $registry = TenantDataRegistryFactory::draftV1();
        $definition = $registry->definition(
            'table:payroll_deduction_agreement_versions',
        );
        self::assertNotNull($definition);
        $projection = CompanyBackupTableProjection::fromDefinition($definition);

        $projection->assertRuntimeSchema(self::COLUMNS, [], ['id']);
        $projection->references->assertRegistryTargets($registry);
        $projection->references->assertRuntimeSchema(
            new CompanyBackupTableReferenceSchema(
                [
                    'basis_points',
                    'basis_amount_minor',
                    'total_limit_minor',
                    'valid_to',
                    'recipient_reference',
                    'note',
                    'reason',
                    'actor_user_id',
                ],
                [
                    new CompanyBackupForeignKey(
                        ['actor_user_id'],
                        'users',
                        ['id'],
                    ),
                    new CompanyBackupForeignKey(
                        ['supplier_id', 'agreement_id', 'employee_id'],
                        'payroll_deduction_agreements',
                        ['supplier_id', 'id', 'employee_id'],
                    ),
                ],
            ),
        );

        self::assertSame(self::COLUMNS, $projection->dataColumns);
        self::assertSame(
            ['supplier_id', 'agreement_id', 'version_no'],
            $definition->details['natural_key'] ?? null,
        );
        self::assertSame(
            [
                'actor_user_id->users:id',
                'supplier_id,agreement_id,employee_id'
                    . '->payroll_deduction_agreements:supplier_id,id,employee_id',
            ],
            array_map(
                static fn ($reference): string => $reference->signature(),
                $projection->references->references,
            ),
        );
        $actor = $projection->references->references[0];
        self::assertSame(CompanyBackupReferenceMapping::Actor, $actor->mapping);
        self::assertSame(
            CompanyBackupReferenceConstraint::Optional,
            $actor->constraint,
        );
        self::assertSame(['null', 'restore_actor'], $actor->fallbacks);
        self::assertSame(
            CompanyBackupReferenceMapping::TenantReferenceKey,
            $projection->references->references[1]->mapping,
        );
        self::assertSame([], $projection->restoreOverrides->overrides);
    }
}
