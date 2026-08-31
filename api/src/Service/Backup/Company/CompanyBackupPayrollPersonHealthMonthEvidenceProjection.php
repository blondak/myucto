<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/**
 * Úplná company projekce měsíční evidence doplatku zdravotního minima.
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
final class CompanyBackupPayrollPersonHealthMonthEvidenceProjection
{
    /** @return list<string> */
    public static function dataColumns(): array
    {
        return [
            'id',
            'supplier_id',
            'employee_id',
            'period_start',
            'top_up_responsibility',
            'top_up_responsibility_evidence_reference',
            'selected_top_up_employer_reference',
            'selected_top_up_employer_evidence_reference',
            'evidence_note',
            'created_by',
            'updated_by',
            'row_version',
            'created_at',
            'updated_at',
        ];
    }

    /** @return list<TableReference> */
    public static function references(): array
    {
        return [
            self::actor('created_by'),
            [
                'columns' => [
                    'supplier_id',
                    'employee_id',
                    'period_start',
                    'selected_top_up_employer_reference',
                ],
                'target' => 'table:payroll_person_health_other_employer_bases',
                'target_columns' => [
                    'supplier_id',
                    'employee_id',
                    'period_start',
                    'employer_reference',
                ],
                'mapping' => CompanyBackupReferenceMapping::TenantNaturalKey->value,
                'constraint' => CompanyBackupReferenceConstraint::Optional->value,
                'nullable_columns' => ['selected_top_up_employer_reference'],
                'fallbacks' => [],
            ],
            [
                'columns' => ['supplier_id', 'employee_id'],
                'target' => 'table:payroll_employees',
                'target_columns' => ['supplier_id', 'id'],
                'mapping' => CompanyBackupReferenceMapping::TenantId->value,
                'constraint' => CompanyBackupReferenceConstraint::Required->value,
                'nullable_columns' => [],
                'fallbacks' => [],
            ],
            self::actor('updated_by'),
        ];
    }

    /** @return TableReference */
    private static function actor(string $column): array
    {
        return [
            'columns' => [$column],
            'target' => 'table:users',
            'target_columns' => ['id'],
            'mapping' => CompanyBackupReferenceMapping::Actor->value,
            'constraint' => CompanyBackupReferenceConstraint::Optional->value,
            'nullable_columns' => [$column],
            'fallbacks' => ['null', 'restore_actor'],
        ];
    }
}
