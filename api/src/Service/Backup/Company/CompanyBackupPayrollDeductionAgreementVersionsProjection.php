<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/**
 * Úplná company projekce neměnných verzí mzdových dohod o srážkách.
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
final class CompanyBackupPayrollDeductionAgreementVersionsProjection
{
    /** @return list<string> */
    public static function dataColumns(): array
    {
        return [
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
    }

    /** @return list<TableReference> */
    public static function references(): array
    {
        return [
            [
                'columns' => ['actor_user_id'],
                'target' => 'table:users',
                'target_columns' => ['id'],
                'mapping' => CompanyBackupReferenceMapping::Actor->value,
                'constraint' => CompanyBackupReferenceConstraint::Optional->value,
                'nullable_columns' => ['actor_user_id'],
                'fallbacks' => ['null', 'restore_actor'],
            ],
            [
                'columns' => ['supplier_id', 'agreement_id', 'employee_id'],
                'target' => 'table:payroll_deduction_agreements',
                'target_columns' => ['supplier_id', 'id', 'employee_id'],
                'mapping' => CompanyBackupReferenceMapping::TenantReferenceKey->value,
                'constraint' => CompanyBackupReferenceConstraint::Required->value,
                'nullable_columns' => [],
                'fallbacks' => [],
            ],
        ];
    }
}
