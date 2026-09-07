<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/**
 * Úplná company projekce zákonného výsledku jednoho pracovního vztahu.
 *
 * @phpstan-import-type EmbeddedHash from CompanyBackupPayrollPersonSnapshotContract
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
final class CompanyBackupPayrollStatutoryRelationshipResultsProjection
{
    /** @return list<string> */
    public static function dataColumns(): array
    {
        return [
            'id',
            'supplier_id',
            'statutory_result_id',
            'person_result_id',
            'revision_id',
            'calculation_kind',
            'employee_id',
            'employment_id',
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
                [
                    'supplier_id',
                    'person_result_id',
                    'statutory_result_id',
                    'revision_id',
                    'calculation_kind',
                    'employee_id',
                ],
                'payroll_statutory_person_results',
                [
                    'supplier_id',
                    'id',
                    'statutory_result_id',
                    'revision_id',
                    'calculation_kind',
                    'employee_id',
                ],
            ),
            self::referenceKey(
                [
                    'supplier_id',
                    'revision_id',
                    'employee_id',
                    'employment_id',
                ],
                'payroll_run_employments',
                [
                    'supplier_id',
                    'revision_id',
                    'employee_id',
                    'employment_id',
                ],
            ),
        ];
    }

    /** @return list<array<string,mixed>> */
    public static function embeddedReferences(): array
    {
        return [
            ...CompanyBackupPayrollPersonSnapshotContract::employmentInputEmbeddedReferences(),
            ...CompanyBackupPayrollStatutoryResultSnapshotContract::relationshipEmbeddedReferences(),
        ];
    }

    /** @return list<EmbeddedHash> */
    public static function embeddedHashes(): array
    {
        return CompanyBackupPayrollPersonSnapshotContract::employmentInputEmbeddedHashes();
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
