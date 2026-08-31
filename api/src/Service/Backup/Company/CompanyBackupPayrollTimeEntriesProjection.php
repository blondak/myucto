<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/**
 * Úplná company projekce verzované evidence skutečně odpracovaného času.
 *
 * source_hash je historický idempotency identifikátor, ne integritní pečeť.
 * Manuální řádky jej mají náhodný a importní algoritmus není v řádku verzovaný,
 * proto se při obnově pouze bezeztrátově překóduje a nepřepočítává.
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
final class CompanyBackupPayrollTimeEntriesProjection
{
    /** @return list<string> */
    public static function dataColumns(): array
    {
        return [
            'id',
            'supplier_id',
            'employment_id',
            'series_key',
            'revision_no',
            'supersedes_id',
            'category',
            'starts_at_utc',
            'ends_at_utc',
            'timezone_name',
            'break_minutes',
            'source_kind',
            'source_reference',
            'source_hash',
            'status',
            'row_version',
            'created_by',
            'approved_by',
            'approved_at',
            'created_at',
        ];
    }

    /** @return array<string,string> */
    public static function columnCodecs(): array
    {
        return ['source_hash' => CompanyBackupColumnCodec::BinaryHex->value];
    }

    /** @return list<TableReference> */
    public static function references(): array
    {
        return [
            self::actor('approved_by'),
            self::actor('created_by'),
            self::tenant('employment_id', 'payroll_employments'),
            self::tenant('supersedes_id', 'payroll_time_entries', nullable: true),
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
}
