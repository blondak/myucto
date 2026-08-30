<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/**
 * Úplná company projekce revizí průměrného výdělku.
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
final class CompanyBackupPayrollAverageEarningSnapshotsProjection
{
    /** @return list<string> */
    public static function dataColumns(): array
    {
        return [
            'id',
            'supplier_id',
            'employment_id',
            'applicable_year',
            'applicable_quarter',
            'revision_no',
            'source_kind',
            'decisive_from',
            'decisive_to',
            'gross_earnings_minor',
            'longer_period_allocated_minor',
            'worked_minutes',
            'worked_days',
            'average_hourly_minor',
            'rationale',
            'support_status',
            'status',
            'ruleset_id',
            'ruleset_hash',
            'input_hash',
            'input_trace',
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
        return ['input_hash' => CompanyBackupColumnCodec::BinaryHex->value];
    }

    /** @return list<string> */
    public static function preservedIdentifiers(): array
    {
        return ['ruleset_id'];
    }

    /** @return list<TableReference> */
    public static function references(): array
    {
        return [
            self::actor('approved_by'),
            self::actor('created_by'),
            [
                'columns' => ['supplier_id', 'employment_id'],
                'target' => 'table:payroll_employments',
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
