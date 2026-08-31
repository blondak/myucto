<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/**
 * Úplná company projekce zapečetěného výsledku osoby v payroll revizi.
 *
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
final class CompanyBackupPayrollRunPersonsProjection
{
    /** @return list<string> */
    public static function dataColumns(): array
    {
        return [
            'id',
            'supplier_id',
            'revision_id',
            'employee_id',
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
            self::tenant('revision_id', 'payroll_run_revisions'),
        ];
    }

    /** @return list<array<string,mixed>> */
    public static function embeddedReferences(): array
    {
        return CompanyBackupPayrollRunResultSnapshotContract::personEmbeddedReferences(
            column: 'result_json',
        );
    }

    /** @return list<DerivedHash> */
    public static function derivedHashes(): array
    {
        return [[
            'algorithm' => 'sha256_canonical_json',
            'hash_column' => 'result_hash',
            'nullable' => true,
            'source_column' => 'result_json',
        ]];
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
