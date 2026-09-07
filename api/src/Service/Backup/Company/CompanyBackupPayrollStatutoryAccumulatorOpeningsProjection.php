<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/**
 * Úplná company projekce append-only počátečních stavů zákonné kumulace.
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
final class CompanyBackupPayrollStatutoryAccumulatorOpeningsProjection
{
    /** @return list<string> */
    public static function dataColumns(): array
    {
        return [
            'id',
            'supplier_id',
            'employee_id',
            'tax_year',
            'calculation_kind',
            'values_json',
            'source_reference',
            'evidence_json',
            'replaces_opening_id',
            'idempotency_key_hash',
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

    /** @return array<string,string> */
    public static function columnCodecs(): array
    {
        return [
            'idempotency_key_hash' => CompanyBackupColumnCodec::BinaryHex->value,
        ];
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
                ['json_column' => 'evidence_json', 'key' => 'evidence'],
                [
                    'column' => 'replaces_opening_id',
                    'key' => 'replaces_opening_id',
                ],
                [
                    'key' => 'schema_version',
                    'literal' => 'payroll-statutory-opening.v1',
                ],
                [
                    'column' => 'source_reference',
                    'key' => 'source_reference',
                ],
                ['column' => 'supplier_id', 'key' => 'supplier_id'],
                ['json_column' => 'values_json', 'key' => 'values'],
                ['column' => 'tax_year', 'key' => 'year'],
            ],
        ]];
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
                    'tax_year',
                    'calculation_kind',
                    'replaces_opening_id',
                ],
                'target' => 'table:payroll_statutory_accumulator_openings',
                'target_columns' => [
                    'supplier_id',
                    'employee_id',
                    'tax_year',
                    'calculation_kind',
                    'id',
                ],
                'mapping' => CompanyBackupReferenceMapping::TenantReferenceKey->value,
                'constraint' => CompanyBackupReferenceConstraint::Required->value,
                'nullable_columns' => ['replaces_opening_id'],
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
