<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/**
 * Úplná company projekce zákonného výsledku jedné osoby a výpočtového druhu.
 *
 * @phpstan-import-type EmbeddedHash from CompanyBackupPayrollPersonSnapshotContract
 * @phpstan-import-type EmbeddedHashReference from CompanyBackupPayrollPersonSnapshotContract
 * @phpstan-type DerivedHash array{
 *   algorithm:string,
 *   dependencies?:list<array{
 *     path:list<string>,
 *     source_hash_column:string
 *   }>,
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
final class CompanyBackupPayrollStatutoryPersonResultsProjection
{
    /** @return list<string> */
    public static function dataColumns(): array
    {
        return [
            'id',
            'supplier_id',
            'statutory_result_id',
            'revision_id',
            'calculation_kind',
            'employee_id',
            'result_status',
            'input_snapshot_json',
            'input_snapshot_hash',
            'result_snapshot_json',
            'result_snapshot_hash',
            'created_at',
        ];
    }

    /** @return list<TableReference> */
    public static function references(): array
    {
        return [
            self::referenceKey(
                ['supplier_id', 'revision_id', 'employee_id'],
                'payroll_run_persons',
                ['supplier_id', 'revision_id', 'employee_id'],
            ),
            self::referenceKey(
                [
                    'supplier_id',
                    'statutory_result_id',
                    'revision_id',
                    'calculation_kind',
                ],
                'payroll_statutory_results',
                ['supplier_id', 'id', 'revision_id', 'calculation_kind'],
            ),
        ];
    }

    /** @return list<array<string,mixed>> */
    public static function embeddedReferences(): array
    {
        return [
            ...CompanyBackupPayrollPersonSnapshotContract::inputEmbeddedReferences(),
            ...CompanyBackupPayrollPersonSnapshotContract::resultEmbeddedReferences(),
        ];
    }

    /** @return list<EmbeddedHash> */
    public static function embeddedHashes(): array
    {
        return CompanyBackupPayrollPersonSnapshotContract::embeddedHashes();
    }

    /** @return list<EmbeddedHashReference> */
    public static function embeddedHashReferences(): array
    {
        return CompanyBackupPayrollPersonSnapshotContract::embeddedHashReferences();
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

    /**
     * @param list<string> $columns
     * @param list<string> $targetColumns
     * @return TableReference
     */
    private static function referenceKey(
        array $columns,
        string $target,
        array $targetColumns,
    ): array {
        return [
            'columns' => $columns,
            'target' => 'table:' . $target,
            'target_columns' => $targetColumns,
            'mapping' => CompanyBackupReferenceMapping::TenantReferenceKey->value,
            'constraint' => CompanyBackupReferenceConstraint::Required->value,
            'nullable_columns' => [],
            'fallbacks' => [],
        ];
    }
}
