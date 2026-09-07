<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/**
 * Úplná company projekce vstupu a výsledku pracovního vztahu v payroll revizi.
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
final class CompanyBackupPayrollRunEmploymentsProjection
{
    /** @return list<string> */
    public static function dataColumns(): array
    {
        return [
            'id',
            'supplier_id',
            'revision_id',
            'employee_id',
            'employment_id',
            'input_json',
            'input_hash',
            'result_json',
            'result_hash',
            'status',
        ];
    }

    /** @return list<TableReference> */
    public static function references(): array
    {
        return [
            self::tenant('employee_id', 'payroll_employees'),
            self::referenceKey(
                ['supplier_id', 'employment_id', 'employee_id'],
                'payroll_employments',
                ['supplier_id', 'id', 'employee_id'],
            ),
            self::tenant('employment_id', 'payroll_employments'),
            self::tenant('revision_id', 'payroll_run_revisions'),
        ];
    }

    /** @return list<array<string,mixed>> */
    public static function embeddedReferences(): array
    {
        $input = CompanyBackupPayrollPersonSnapshotContract::
            employmentInputEmbeddedReferences(column: 'input_json');
        $result = CompanyBackupPayrollRunResultSnapshotContract::
            employmentEmbeddedReferences();

        return [...$input, ...$result];
    }

    /** @return list<EmbeddedHash> */
    public static function embeddedHashes(): array
    {
        return CompanyBackupPayrollPersonSnapshotContract::
            employmentInputEmbeddedHashes(column: 'input_json');
    }

    /** @return list<DerivedHash> */
    public static function derivedHashes(): array
    {
        return [
            [
                'algorithm' => 'sha256_canonical_json',
                'hash_column' => 'input_hash',
                'nullable' => false,
                'source_column' => 'input_json',
            ],
            [
                'algorithm' => 'sha256_canonical_json',
                'hash_column' => 'result_hash',
                'nullable' => true,
                'source_column' => 'result_json',
            ],
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
