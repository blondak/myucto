<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/**
 * Úplná company projekce účinného přiřazení mzdové dimenze vztahu.
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
final class CompanyBackupPayrollEmploymentDimensionsProjection
{
    /** @return list<string> */
    public static function dataColumns(): array
    {
        return [
            'id',
            'supplier_id',
            'employment_id',
            'dimension_id',
            'valid_from',
            'valid_to',
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
            self::tenant('dimension_id', 'payroll_dimensions'),
            self::tenant('employment_id', 'payroll_employments'),
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
    private static function tenant(string $column, string $target): array
    {
        return [
            'columns' => ['supplier_id', $column],
            'target' => 'table:' . $target,
            'target_columns' => ['supplier_id', 'id'],
            'mapping' => CompanyBackupReferenceMapping::TenantId->value,
            'constraint' => CompanyBackupReferenceConstraint::Required->value,
            'nullable_columns' => [],
            'fallbacks' => [],
        ];
    }
}
