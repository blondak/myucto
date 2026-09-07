<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/**
 * Úplná company projekce immutable kořene zákonného mzdového výsledku.
 *
 * @phpstan-import-type EmbeddedHash from CompanyBackupPayrollPersonSnapshotContract
 * @phpstan-import-type EmbeddedHashReference from CompanyBackupPayrollPersonSnapshotContract
 * @phpstan-type DerivedHash array{
 *   algorithm:string,
 *   hash_column:string,
 *   nullable:bool,
 *   source_column:string
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
final class CompanyBackupPayrollStatutoryResultsProjection
{
    /** @return list<string> */
    public static function dataColumns(): array
    {
        return [
            'id',
            'supplier_id',
            'revision_id',
            'calculation_kind',
            'schema_version',
            'result_status',
            'ruleset_id',
            'ruleset_hash',
            'input_snapshot_json',
            'input_snapshot_hash',
            'result_snapshot_json',
            'result_snapshot_hash',
            'result_set_hash',
            'created_by',
            'created_at',
        ];
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
            [
                'columns' => ['created_by'],
                'target' => 'table:users',
                'target_columns' => ['id'],
                'mapping' => CompanyBackupReferenceMapping::Actor->value,
                'constraint' => CompanyBackupReferenceConstraint::Optional->value,
                'nullable_columns' => ['created_by'],
                'fallbacks' => ['null', 'restore_actor'],
            ],
            [
                'columns' => ['supplier_id', 'revision_id'],
                'target' => 'table:payroll_run_revisions',
                'target_columns' => ['supplier_id', 'id'],
                'mapping' => CompanyBackupReferenceMapping::TenantId->value,
                'constraint' => CompanyBackupReferenceConstraint::Required->value,
                'nullable_columns' => [],
                'fallbacks' => [],
            ],
        ];
    }

    /** @return list<array<string,mixed>> */
    public static function embeddedReferences(): array
    {
        return [
            ...CompanyBackupPayrollRunInputSnapshotContract::embeddedReferences(),
            ...CompanyBackupPayrollStatutoryResultSnapshotContract::directEmbeddedReferences(),
        ];
    }

    /** @return list<EmbeddedHash> */
    public static function embeddedHashes(): array
    {
        return CompanyBackupPayrollRunInputSnapshotContract::embeddedHashes();
    }

    /** @return list<EmbeddedHashReference> */
    public static function embeddedHashReferences(): array
    {
        return CompanyBackupPayrollRunInputSnapshotContract::embeddedHashReferences();
    }

    /** @return list<DerivedHash> */
    public static function derivedHashes(): array
    {
        return [
            [
                'algorithm' => 'sha256_canonical_json',
                'hash_column' => 'input_snapshot_hash',
                'nullable' => false,
                'source_column' => 'input_snapshot_json',
            ],
            [
                'algorithm' => 'sha256_canonical_json',
                'hash_column' => 'result_snapshot_hash',
                'nullable' => false,
                'source_column' => 'result_snapshot_json',
            ],
        ];
    }
}
