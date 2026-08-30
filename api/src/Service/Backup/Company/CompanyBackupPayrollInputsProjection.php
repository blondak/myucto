<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/**
 * Úplná company projekce měsíčních mzdových vstupů a jejich snapshotů.
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
 * @phpstan-type DerivedHash array{
 *   algorithm:string,
 *   hash_column:string,
 *   nullable:bool,
 *   source_column:string
 * }
 */
final class CompanyBackupPayrollInputsProjection
{
    /** @return list<string> */
    public static function dataColumns(): array
    {
        return [
            'id',
            'supplier_id',
            'employee_id',
            'employment_id',
            'component_id',
            'period_start',
            'source_period_start',
            'amount_minor',
            'quantity_milliunits',
            'source_kind',
            'external_id',
            'import_id',
            'recurring_component_id',
            'source_snapshot_json',
            'source_snapshot_hash',
            'status',
            'component_snapshot_json',
            'component_snapshot_hash',
            'row_version',
            'created_by',
            'approved_by',
            'approved_at',
            'created_at',
            'updated_at',
            'benefit_basket',
            'benefit_exempt_minor',
            'benefit_taxable_minor',
        ];
    }

    /** @return list<string> */
    public static function generatedColumns(): array
    {
        return ['external_dedupe_key'];
    }

    /** @return array<string,string> */
    public static function columnCodecs(): array
    {
        return [
            'component_snapshot_hash' => CompanyBackupColumnCodec::BinaryHex->value,
            'source_snapshot_hash' => CompanyBackupColumnCodec::BinaryHex->value,
        ];
    }

    /** @return list<TableReference> */
    public static function references(): array
    {
        return [
            self::actor('approved_by'),
            self::actor('created_by'),
            self::tenant('component_id', 'payroll_component_definitions'),
            self::tenant('employee_id', 'payroll_employees'),
            self::tenant('employment_id', 'payroll_employments'),
            self::tenant('import_id', 'payroll_input_imports', nullable: true),
            self::tenant(
                'recurring_component_id',
                'payroll_recurring_components',
                nullable: true,
            ),
        ];
    }

    /** @return list<array<string,mixed>> */
    public static function encodedReferences(): array
    {
        return [[
            'column' => 'external_id',
            'condition' => [
                'column' => 'source_kind',
                'equals' => 'travel',
            ],
            'mapping' => CompanyBackupReferenceMapping::TenantId->value,
            'nullable' => false,
            'target' => 'table:payroll_business_trips',
            'target_columns' => ['id'],
            'value_prefix' => 'travel:',
            'value_suffix_separator' => ':',
        ]];
    }

    /** @return list<array<string,mixed>> */
    public static function embeddedReferences(): array
    {
        return [
            self::embedded(
                'component_snapshot_json',
                'component_id',
                'payroll_component_definitions',
            ),
            self::embedded(
                'source_snapshot_json',
                'average_snapshot_id',
                'payroll_average_earning_snapshots',
            ),
            self::embedded(
                'source_snapshot_json',
                'business_trip_id',
                'payroll_business_trips',
            ),
            self::embedded(
                'source_snapshot_json',
                'recurring_component_id',
                'payroll_recurring_components',
            ),
        ];
    }

    /** @return list<DerivedHash> */
    public static function derivedHashes(): array
    {
        return [
            self::derivedHash(
                'component_snapshot_json',
                'component_snapshot_hash',
            ),
            self::derivedHash('source_snapshot_json', 'source_snapshot_hash'),
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
        string $target,
        bool $nullable = false,
    ): array {
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

    /** @return array<string,mixed> */
    private static function embedded(
        string $column,
        string $path,
        string $target,
    ): array {
        return [
            'column' => $column,
            'condition' => null,
            'fallbacks' => [],
            'mapping' => CompanyBackupReferenceMapping::TenantId->value,
            'nullable' => false,
            'path' => [$path],
            'target' => 'table:' . $target,
            'target_columns' => ['id'],
        ];
    }

    /** @return DerivedHash */
    private static function derivedHash(
        string $sourceColumn,
        string $hashColumn,
    ): array {
        return [
            'algorithm' => 'sha256_canonical_json',
            'hash_column' => $hashColumn,
            'nullable' => true,
            'source_column' => $sourceColumn,
        ];
    }
}
