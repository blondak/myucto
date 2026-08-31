<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/** Úplný remap identit ve výsledkovém snapshotu payroll revize. */
final class CompanyBackupPayrollRunResultSnapshotContract
{
    /** @return list<array<string,mixed>> */
    public static function embeddedReferences(): array
    {
        $references = [
            ...self::personEmbeddedReferences(['people', '*']),
            ...CompanyBackupPayrollStatutoryResultSnapshotContract::personEnvelopeEmbeddedReferences(
                ['statutory', 'people', '*'],
            ),
            self::tenant(
                ['statutory', 'result_set_ids', '*'],
                'payroll_statutory_results',
            ),
            self::tenant(
                ['statutory', 'risky_savings', '*', 'employment_id'],
                'payroll_employments',
            ),
            self::tenant(
                ['statutory', 'risky_savings', '*', 'institution_account_id'],
                'payroll_institution_accounts',
                nullable: true,
            ),
            self::tenant(
                ['statutory', 'risky_savings', '*', 'source_evidence_id'],
                'payroll_risky_savings_evidence',
                nullable: true,
            ),
        ];

        return self::sortReferences($references);
    }

    /**
     * @param list<string> $personPath
     * @return list<array<string,mixed>>
     */
    public static function personEmbeddedReferences(
        array $personPath = [],
        string $column = 'result_snapshot_json',
    ): array {
        $references = [
            self::tenant(
                [...$personPath, 'employee_id'],
                'payroll_employees',
                column: $column,
            ),
            self::tenant(
                [...$personPath, 'employments', '*', 'employment_id'],
                'payroll_employments',
                column: $column,
            ),
            self::tenant(
                [
                    ...$personPath,
                    'employments',
                    '*',
                    'inputs',
                    '*',
                    'input_id',
                ],
                'payroll_inputs',
                column: $column,
            ),
            self::tenant(
                [
                    ...$personPath,
                    'enforcement',
                    'input',
                    'income',
                    'trace',
                    '*',
                    'id',
                ],
                'payroll_employees',
                valuePrefix: 'revision-person-',
                valueSuffixSeparator: '-',
                column: $column,
            ),
            self::tenant(
                [
                    ...$personPath,
                    'enforcement',
                    'input',
                    'income',
                    'trace',
                    '*',
                    'payer_id',
                ],
                'supplier',
                valuePrefix: 'supplier-',
                column: $column,
            ),
        ];
        foreach (
            CompanyBackupPayrollStatutoryResultSnapshotContract::personEnvelopeEmbeddedReferences(
                [...$personPath, 'statutory'],
            ) as $reference
        ) {
            $reference['column'] = $column;
            $references[] = $reference;
        }

        return self::sortReferences($references);
    }

    /**
     * @param list<string> $path
     * @return array<string,mixed>
     */
    private static function tenant(
        array $path,
        string $target,
        bool $nullable = false,
        ?string $valuePrefix = null,
        ?string $valueSuffixSeparator = null,
        string $column = 'result_snapshot_json',
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
            ...($valuePrefix === null ? [] : ['value_prefix' => $valuePrefix]),
            ...($valueSuffixSeparator === null ? [] : [
                'value_suffix_separator' => $valueSuffixSeparator,
            ]),
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
