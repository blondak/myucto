<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/**
 * Identity a pečetě společného zmrazeného vstupu payroll-run-input.v2.
 *
 * @phpstan-import-type EmbeddedHash from CompanyBackupPayrollPersonSnapshotContract
 * @phpstan-import-type EmbeddedHashReference from CompanyBackupPayrollPersonSnapshotContract
 */
final class CompanyBackupPayrollRunInputSnapshotContract
{
    /** @return list<array<string,mixed>> */
    public static function embeddedReferences(): array
    {
        return self::sortReferences([
            ...CompanyBackupPayrollPersonSnapshotContract::inputEmbeddedReferences([
                'people',
                '*',
            ]),
            self::tenant(
                ['employer_policy', 'id'],
                'payroll_employer_policies',
            ),
            self::tenant(['office_id'], 'payroll_offices', nullable: true),
            self::tenant(['supplier_id'], 'supplier'),
        ]);
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

    /**
     * @param list<string> $path
     * @return array<string,mixed>
     */
    private static function tenant(
        array $path,
        string $target,
        bool $nullable = false,
    ): array {
        return [
            'column' => 'input_snapshot_json',
            'condition' => null,
            'fallbacks' => [],
            'mapping' => CompanyBackupReferenceMapping::TenantId->value,
            'nullable' => $nullable,
            'path' => $path,
            'target' => 'table:' . $target,
            'target_columns' => ['id'],
        ];
    }

    /**
     * @param list<array<string,mixed>> $references
     * @return list<array<string,mixed>>
     */
    private static function sortReferences(array $references): array
    {
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
}
