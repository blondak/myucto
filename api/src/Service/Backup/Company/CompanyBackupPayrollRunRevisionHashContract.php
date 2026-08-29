<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/**
 * Hashový kontrakt připravované projekce payroll_run_revisions.
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
 */
final class CompanyBackupPayrollRunRevisionHashContract
{
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
                'dependencies' => [[
                    'path' => ['source_snapshot_hash'],
                    'source_hash_column' => 'input_snapshot_hash',
                ]],
                'hash_column' => 'result_snapshot_hash',
                'nullable' => true,
                'source_column' => 'result_snapshot_json',
            ],
        ];
    }

    /** @return list<EmbeddedHash> */
    public static function embeddedHashes(): array
    {
        return CompanyBackupPayrollPersonSnapshotContract::embeddedHashes([
            'people',
            '*',
        ]);
    }

    /** @return list<EmbeddedHashReference> */
    public static function embeddedHashReferences(): array
    {
        return CompanyBackupPayrollPersonSnapshotContract::embeddedHashReferences([
            'people',
            '*',
        ]);
    }
}
