<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/**
 * Úplná company projekce append-only ledgeru mzdových srážek.
 *
 * event_key_hash je historický idempotency identifikátor, ne integritní pečeť.
 * Jeho libovolný vstupní klíč se neukládá, proto se při obnově bezeztrátově
 * překóduje a nepřepočítává z ostatních sloupců.
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
final class CompanyBackupPayrollDeductionLedgerProjection
{
    /** @return list<string> */
    public static function dataColumns(): array
    {
        return [
            'id',
            'supplier_id',
            'agreement_id',
            'revision_id',
            'employee_id',
            'event_kind',
            'amount_minor',
            'event_key_hash',
            'source_ledger_id',
            'metadata_json',
            'actor_user_id',
            'created_at',
        ];
    }

    /** @return array<string,string> */
    public static function columnCodecs(): array
    {
        return ['event_key_hash' => CompanyBackupColumnCodec::BinaryHex->value];
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
                'nullable_columns' => ['agreement_id'],
                'fallbacks' => [],
            ],
            self::tenant('employee_id', 'payroll_employees'),
            self::tenant('revision_id', 'payroll_run_revisions'),
            self::tenant(
                'source_ledger_id',
                'payroll_deduction_ledger',
                nullable: true,
            ),
        ];
    }

    /** @return TableReference */
    private static function tenant(
        string $column,
        string $target,
        bool $nullable = false,
    ): array
    {
        return [
            'columns' => ['supplier_id', $column],
            'target' => 'table:' . $target,
            'target_columns' => ['supplier_id', 'id'],
            'mapping' => CompanyBackupReferenceMapping::TenantId->value,
            'constraint' => CompanyBackupReferenceConstraint::Required->value,
            'nullable_columns' => $nullable ? [$column] : [],
            'fallbacks' => [],
        ];
    }
}
