<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/**
 * Úplná company projekce pracovních cest a jejich schváleného výpočtu.
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
final class CompanyBackupPayrollBusinessTripsProjection
{
    /** @return list<string> */
    public static function dataColumns(): array
    {
        return [
            'id',
            'supplier_id',
            'employee_id',
            'employment_id',
            'country_code',
            'timezone_name',
            'departure_at_utc',
            'arrival_at_utc',
            'origin_place',
            'destination_place',
            'purpose',
            'transport_mode',
            'meal_rate_band_1_minor',
            'meal_rate_band_2_minor',
            'meal_rate_band_3_minor',
            'advance_minor',
            'settlement_period_start',
            'status',
            'entitlement_total_minor',
            'exempt_total_minor',
            'taxable_total_minor',
            'ruleset_id',
            'calculation_json',
            'calculation_hash',
            'row_version',
            'created_by',
            'approved_by',
            'approved_at',
            'created_at',
            'updated_at',
        ];
    }

    /** @return array<string,string> */
    public static function columnCodecs(): array
    {
        return ['calculation_hash' => CompanyBackupColumnCodec::BinaryHex->value];
    }

    /** @return list<string> */
    public static function preservedIdentifiers(): array
    {
        return ['ruleset_id'];
    }

    /** @return list<DerivedHash> */
    public static function derivedHashes(): array
    {
        return [[
            'algorithm' => 'sha256_canonical_json',
            'hash_column' => 'calculation_hash',
            'nullable' => true,
            'source_column' => 'calculation_json',
        ]];
    }

    /** @return list<TableReference> */
    public static function references(): array
    {
        return [
            self::actor('approved_by'),
            self::actor('created_by'),
            self::tenant('employee_id', 'payroll_employees'),
            self::tenant('employment_id', 'payroll_employments'),
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
