<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/**
 * Úplná company projekce časově účinných podmínek pracovního vztahu.
 *
 * @phpstan-type TableReference array{
 *   columns:list<string>,
 *   target:string,
 *   target_columns:list<string>,
 *   mapping:string,
 *   constraint:string,
 *   nullable_columns:list<string>,
 *   fallbacks:list<string>
 * }
 */
final class CompanyBackupPayrollEmploymentTermsProjection
{
    /** @return list<string> */
    public static function dataColumns(): array
    {
        return [
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
    }

    /** @return list<TableReference> */
    public static function references(): array
    {
        return [
            [
                'columns' => ['created_by'],
                'target' => 'table:users',
                'target_columns' => ['id'],
                'mapping' => CompanyBackupReferenceMapping::Actor->value,
                'constraint' => CompanyBackupReferenceConstraint::Optional->value,
                'nullable_columns' => ['created_by'],
                'fallbacks' => ['null', 'restore_actor'],
            ],
            [
                'columns' => ['supplier_id', 'employment_id'],
                'target' => 'table:payroll_employments',
                'target_columns' => ['supplier_id', 'id'],
                'mapping' => CompanyBackupReferenceMapping::TenantId->value,
                'constraint' => CompanyBackupReferenceConstraint::Required->value,
                'nullable_columns' => [],
                'fallbacks' => [],
            ],
            [
                'columns' => ['supplier_id', 'office_id'],
                'target' => 'table:payroll_offices',
                'target_columns' => ['supplier_id', 'id'],
                'mapping' => CompanyBackupReferenceMapping::TenantId->value,
                'constraint' => CompanyBackupReferenceConstraint::Required->value,
                'nullable_columns' => ['office_id'],
                'fallbacks' => [],
            ],
        ];
    }
}
