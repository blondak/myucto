<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/**
 * Úplná company projekce append-only přírůstků zákonné mzdové kumulace.
 *
 * @phpstan-type DerivedHash array{
 *   algorithm:string,
 *   hash_column:string,
 *   nullable:bool,
 *   projection:list<array{
 *     column?:string,
 *     json_column?:string,
 *     key:string,
 *     literal?:string|int|bool|null
 *   }>
 * }
 * @phpstan-type HashReference array{
 *   column:string,
 *   nullable:bool,
 *   target:string,
 *   target_hash_column:string
 * }
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
final class CompanyBackupPayrollStatutoryAccumulatorEntriesProjection
{
    /** @return list<string> */
    public static function dataColumns(): array
    {
        return [
            'id',
            'supplier_id',
            'employee_id',
            'tax_year',
            'period_start',
            'revision_id',
            'calculation_kind',
            'values_json',
            'source_result_hash',
            'replaces_entry_id',
            'record_hash',
            'created_by',
            'created_at',
        ];
    }

    /** @return list<string> */
    public static function generatedColumns(): array
    {
        return ['predecessor_scope_id'];
    }

    /** @return list<DerivedHash> */
    public static function derivedHashes(): array
    {
        return [[
            'algorithm' => 'sha256_canonical_projection',
            'hash_column' => 'record_hash',
            'nullable' => false,
            'projection' => [
                [
                    'column' => 'calculation_kind',
                    'key' => 'calculation_kind',
                ],
                ['column' => 'employee_id', 'key' => 'employee_id'],
                ['column' => 'period_start', 'key' => 'period_start'],
                [
                    'column' => 'replaces_entry_id',
                    'key' => 'replaces_entry_id',
                ],
                ['column' => 'revision_id', 'key' => 'revision_id'],
                [
                    'key' => 'schema_version',
                    'literal' => 'payroll-statutory-accumulator-entry.v1',
                ],
                [
                    'column' => 'source_result_hash',
                    'key' => 'source_result_hash',
                ],
                ['column' => 'supplier_id', 'key' => 'supplier_id'],
                ['json_column' => 'values_json', 'key' => 'values'],
                ['column' => 'tax_year', 'key' => 'year'],
            ],
        ]];
    }

    /** @return list<HashReference> */
    public static function hashReferences(): array
    {
        return [[
            'column' => 'source_result_hash',
            'nullable' => false,
            'target' => 'table:payroll_statutory_person_results',
            'target_hash_column' => 'result_snapshot_hash',
        ]];
    }

    /** @return list<TableReference> */
    public static function references(): array
    {
        return [
            self::actor('created_by'),
            self::referenceKey(
                [
                    'supplier_id',
                    'employee_id',
                    'tax_year',
                    'period_start',
                    'calculation_kind',
                    'replaces_entry_id',
                ],
                'payroll_statutory_accumulator_entries',
                [
                    'supplier_id',
                    'employee_id',
                    'tax_year',
                    'period_start',
                    'calculation_kind',
                    'id',
                ],
                ['replaces_entry_id'],
            ),
            self::referenceKey(
                ['supplier_id', 'revision_id', 'employee_id'],
                'payroll_run_persons',
                ['supplier_id', 'revision_id', 'employee_id'],
            ),
        ];
    }

    /**
     * @param list<string> $columns
     * @param list<string> $targetColumns
     * @param list<string> $nullableColumns
     * @return TableReference
     */
    private static function referenceKey(
        array $columns,
        string $target,
        array $targetColumns,
        array $nullableColumns = [],
    ): array {
        return [
            'columns' => $columns,
            'target' => 'table:' . $target,
            'target_columns' => $targetColumns,
            'mapping' => CompanyBackupReferenceMapping::TenantReferenceKey->value,
            'constraint' => CompanyBackupReferenceConstraint::Required->value,
            'nullable_columns' => $nullableColumns,
            'fallbacks' => [],
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
