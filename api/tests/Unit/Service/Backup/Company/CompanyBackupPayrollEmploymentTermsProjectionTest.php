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

final class CompanyBackupPayrollEmploymentTermsProjectionTest extends TestCase
{
    /** @var list<string> */
    private const COLUMNS = [
        'id',
        'supplier_id',
        'employment_id',
        'office_id',
        'effective_from',
        'effective_to',
        'contract_signed_on',
        'planned_start_on',
        'actual_start_on',
        'fixed_term_end_on',
        'weekly_hours',
        'leave_entitlement_weeks_override',
        'workload_basis_points',
        'work_place',
        'regular_workplace',
        'jmhz_workplace_municipality_code',
        'jmhz_workplace_country_code',
        'jmhz_external_codebook_overlay_key',
        'jmhz_external_codebook_manifest_sha256',
        'jmhz_apz_contribution_status',
        'jmhz_apz_instrument_code',
        'jmhz_functional_benefits_status',
        'jmhz_temporary_assignment_status',
        'jmhz_orchard_discount_eligible',
        'jmhz_specific_legal_fact_applies',
        'jmhz_ozp_employment_support_applies',
        'jmhz_deep_mining_work_applies',
        'cz_isco_code',
        'activity_code',
        'jmhz_relationship_detail_code',
        'social_insurance_participation',
        'health_insurance_participation',
        'tax_regime',
        'other_withholding_eligibility',
        'foreign_legislation_country_code',
        'a1_certificate_until',
        'risky_work',
        'social_employer_rate_category',
        'social_employer_rate_category_evidence',
        'social_part_time_discount_reason',
        'social_part_time_discount_evidence',
        'social_part_time_discount_notified_on',
        'tax_declaration_signed',
        'is_primary',
        'change_reason',
        'created_by',
        'row_version',
        'created_at',
    ];

    public function testDeclaresExactTermsReferencesAndEffectiveNaturalKey(): void
    {
        $registry = TenantDataRegistryFactory::draftV1();
        $definition = $registry->definition('table:payroll_employment_terms');
        self::assertNotNull($definition);
        $projection = CompanyBackupTableProjection::fromDefinition($definition);

        $projection->assertRuntimeSchema(self::COLUMNS, [], ['id']);
        $projection->references->assertRegistryTargets($registry);
        $projection->references->assertRuntimeSchema(
            new CompanyBackupTableReferenceSchema(
                [
                    'office_id',
                    'effective_to',
                    'contract_signed_on',
                    'actual_start_on',
                    'fixed_term_end_on',
                    'weekly_hours',
                    'leave_entitlement_weeks_override',
                    'work_place',
                    'regular_workplace',
                    'jmhz_workplace_municipality_code',
                    'jmhz_workplace_country_code',
                    'jmhz_external_codebook_overlay_key',
                    'jmhz_external_codebook_manifest_sha256',
                    'jmhz_apz_instrument_code',
                    'cz_isco_code',
                    'activity_code',
                    'jmhz_relationship_detail_code',
                    'foreign_legislation_country_code',
                    'a1_certificate_until',
                    'social_employer_rate_category_evidence',
                    'social_part_time_discount_evidence',
                    'social_part_time_discount_notified_on',
                    'change_reason',
                    'created_by',
                ],
                [
                    new CompanyBackupForeignKey(
                        ['supplier_id', 'employment_id'],
                        'payroll_employments',
                        ['supplier_id', 'id'],
                    ),
                    new CompanyBackupForeignKey(
                        ['supplier_id', 'office_id'],
                        'payroll_offices',
                        ['supplier_id', 'id'],
                    ),
                    new CompanyBackupForeignKey(
                        ['created_by'],
                        'users',
                        ['id'],
                    ),
                ],
            ),
        );

        self::assertSame(self::COLUMNS, $projection->dataColumns);
        self::assertSame(
            ['supplier_id', 'employment_id', 'effective_from'],
            $definition->details['natural_key'] ?? null,
        );
        self::assertSame(
            [
                'created_by->users:id',
                'supplier_id,employment_id->payroll_employments:supplier_id,id',
                'supplier_id,office_id->payroll_offices:supplier_id,id',
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
        self::assertSame(['created_by'], $actor->nullableColumns);
        self::assertSame(['null', 'restore_actor'], $actor->fallbacks);
        self::assertSame(
            ['office_id'],
            $projection->references->references[2]->nullableColumns,
        );
    }
}
