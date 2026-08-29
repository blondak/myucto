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
            self::tenant(
                ['people', '*', 'employee_id'],
                'payroll_employees',
            ),
            self::tenant(
                ['people', '*', 'employments', '*', 'employment_id'],
                'payroll_employments',
            ),
            self::tenant(
                [
                    'people',
                    '*',
                    'employments',
                    '*',
                    'inputs',
                    '*',
                    'input_id',
                ],
                'payroll_inputs',
            ),
            self::tenant(
                [
                    'people',
                    '*',
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
            ),
            self::tenant(
                [
                    'people',
                    '*',
                    'enforcement',
                    'input',
                    'income',
                    'trace',
                    '*',
                    'payer_id',
                ],
                'supplier',
                valuePrefix: 'supplier-',
            ),
            ...CompanyBackupPayrollStatutoryResultSnapshotContract::personEnvelopeEmbeddedReferences(
                ['people', '*', 'statutory'],
            ),
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
    ): array {
        return [
            'column' => 'result_snapshot_json',
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
}
