<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/**
 * Úplná company projekce neměnné revize mzdového běhu.
 *
 * @phpstan-import-type DerivedHash from CompanyBackupPayrollRunRevisionHashContract
 * @phpstan-import-type EmbeddedHash from CompanyBackupPayrollPersonSnapshotContract
 * @phpstan-import-type EmbeddedHashReference from CompanyBackupPayrollPersonSnapshotContract
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
final class CompanyBackupPayrollRunRevisionsProjection
{
    /** @return list<string> */
    public static function dataColumns(): array
    {
        return [
            'id',
            'supplier_id',
            'run_id',
            'revision_no',
            'previous_revision_id',
            'revision_kind',
            'status',
            'schema_version',
            'ruleset_manifest_hash',
            'input_snapshot_json',
            'input_snapshot_hash',
            'result_snapshot_json',
            'result_snapshot_hash',
            'idempotency_key_hash',
            'calculated_by',
            'reviewed_by',
            'approved_by',
            'calculated_at',
            'reviewed_at',
            'approved_at',
            'created_at',
        ];
    }

    /** @return array<string,string> */
    public static function columnCodecs(): array
    {
        return ['idempotency_key_hash' => CompanyBackupColumnCodec::BinaryHex->value];
    }

    /** @return list<TableReference> */
    public static function references(): array
    {
        return [
            self::actor('approved_by'),
            self::actor('calculated_by'),
            self::actor('reviewed_by'),
            self::tenant(
                ['supplier_id', 'previous_revision_id'],
                'payroll_run_revisions',
                ['supplier_id', 'id'],
                nullableColumns: ['previous_revision_id'],
            ),
            self::tenant(
                ['supplier_id', 'run_id'],
                'payroll_runs',
                ['supplier_id', 'id'],
            ),
        ];
    }

    /** @return list<array<string,mixed>> */
    public static function embeddedReferences(): array
    {
        $references = [
            ...CompanyBackupPayrollPersonSnapshotContract::inputEmbeddedReferences([
                'people',
                '*',
            ]),
            self::embeddedTenant(
                'input_snapshot_json',
                ['employer_policy', 'id'],
                'payroll_employer_policies',
            ),
            self::embeddedTenant(
                'input_snapshot_json',
                ['office_id'],
                'payroll_offices',
                nullable: true,
            ),
            self::embeddedTenant(
                'input_snapshot_json',
                ['supplier_id'],
                'supplier',
            ),
            ...CompanyBackupPayrollRunResultSnapshotContract::embeddedReferences(),
        ];
        usort(
            $references,
            static fn (array $left, array $right): int => strcmp(
                CompanyBackupEmbeddedReference::fromArray(
                    $left,
                    'table:payroll_run_revisions',
                )->signature(),
                CompanyBackupEmbeddedReference::fromArray(
                    $right,
                    'table:payroll_run_revisions',
                )->signature(),
            ),
        );
        return $references;
    }

    /** @return list<EmbeddedHash> */
    public static function embeddedHashes(): array
    {
        return CompanyBackupPayrollRunRevisionHashContract::embeddedHashes();
    }

    /** @return list<EmbeddedHashReference> */
    public static function embeddedHashReferences(): array
    {
        return CompanyBackupPayrollRunRevisionHashContract::embeddedHashReferences();
    }

    /** @return list<DerivedHash> */
    public static function derivedHashes(): array
    {
        return CompanyBackupPayrollRunRevisionHashContract::derivedHashes();
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

    /**
     * @param list<string> $columns
     * @param list<string> $targetColumns
     * @param list<string> $nullableColumns
     * @return TableReference
     */
    private static function tenant(
        array $columns,
        string $target,
        array $targetColumns,
        array $nullableColumns = [],
    ): array {
        return [
            'columns' => $columns,
            'target' => 'table:' . $target,
            'target_columns' => $targetColumns,
            'mapping' => CompanyBackupReferenceMapping::TenantId->value,
            'constraint' => CompanyBackupReferenceConstraint::Required->value,
            'nullable_columns' => $nullableColumns,
            'fallbacks' => [],
        ];
    }

    /**
     * @param list<string> $path
     * @return array<string,mixed>
     */
    private static function embeddedTenant(
        string $column,
        array $path,
        string $target,
        bool $nullable = false,
    ): array {
        return [
            'column' => $column,
            'condition' => null,
            'fallbacks' => [],
            'mapping' => CompanyBackupReferenceMapping::TenantId->value,
            'nullable' => $nullable,
            'path' => $path,
            'target' => 'table:' . $target,
            'target_columns' => ['id'],
        ];
    }
}
