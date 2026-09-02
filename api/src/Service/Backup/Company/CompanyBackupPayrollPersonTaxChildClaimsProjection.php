<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/**
 * Úplná company projekce časové řady daňového zvýhodnění na děti.
 *
 * Nové řádky nesou stejnou identitu dítěte ve `dependant_id` a v textu
 * `dependant-{id}`. Korelovaná encoded reference oba tvary ověří a přemapuje
 * atomicky. Legacy řádky bez `dependant_id` si ponechají původní stabilní text.
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
final class CompanyBackupPayrollPersonTaxChildClaimsProjection
{
    /** @return list<string> */
    public static function dataColumns(): array
    {
        return [
            'id',
            'supplier_id',
            'employee_id',
            'dependant_id',
            'child_reference',
            'child_order',
            'claim_reason',
            'superseded_by_id',
            'ztp_p',
            'evidence_status',
            'shared_household_confirmed',
            'other_claimant_excluded',
            'effective_from',
            'effective_to',
            'evidence_reference',
            'evidence_note',
            'created_by',
            'updated_by',
            'row_version',
            'created_at',
            'updated_at',
        ];
    }

    /** @return list<array<string,mixed>> */
    public static function encodedReferences(): array
    {
        return [[
            'column' => 'child_reference',
            'condition' => null,
            'correlated_id_column' => 'dependant_id',
            'mapping' => CompanyBackupReferenceMapping::TenantId->value,
            'nullable' => false,
            'target' => 'table:payroll_dependants',
            'target_columns' => ['id'],
            'value_prefix' => 'dependant-',
            'value_suffix_separator' => null,
        ]];
    }

    /** @return list<TableReference> */
    public static function references(): array
    {
        return [
            self::actor('created_by'),
            self::tenant(
                'dependant_id',
                'payroll_dependants',
                nullable: true,
            ),
            self::tenant('employee_id', 'payroll_employees'),
            self::tenant(
                'superseded_by_id',
                'payroll_person_tax_child_claims',
                nullable: true,
                physical: false,
            ),
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

    /** @return TableReference */
    private static function tenant(
        string $column,
        string $table,
        bool $nullable = false,
        bool $physical = true,
    ): array {
        return [
            'columns' => ['supplier_id', $column],
            'target' => 'table:' . $table,
            'target_columns' => ['supplier_id', 'id'],
            'mapping' => CompanyBackupReferenceMapping::TenantId->value,
            'constraint' => ($physical
                ? CompanyBackupReferenceConstraint::Required
                : CompanyBackupReferenceConstraint::Optional)->value,
            'nullable_columns' => $nullable ? [$column] : [],
            'fallbacks' => [],
        ];
    }
}
