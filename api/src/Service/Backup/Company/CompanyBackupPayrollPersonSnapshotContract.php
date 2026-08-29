<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/**
 * Sdílený remap a hashový kontrakt osoby uvnitř payroll snapshotů.
 *
 * @phpstan-type EmbeddedHash array{
 *   algorithm:string,
 *   column:string,
 *   dependencies:list<string>,
 *   hash_path:list<string>,
 *   name:string,
 *   nullable:bool,
 *   omit_paths:list<list<string>>,
 *   projection?:list<array{
 *     key:string,
 *     literal?:string|int|bool|null,
 *     path?:list<string>
 *   }>,
 *   source_path:list<string>
 * }
 * @phpstan-type EmbeddedHashReference array{
 *   column:string,
 *   nullable:bool,
 *   path:list<string>,
 *   target:string,
 *   target_hash_column:string
 * }
 */
final class CompanyBackupPayrollPersonSnapshotContract
{
    /**
     * @param list<string> $personPath
     * @return list<array<string,mixed>>
     */
    public static function inputEmbeddedReferences(array $personPath = []): array
    {
        $references = [
            self::tenant(
                'input_snapshot_json',
                [...$personPath, 'deduction_agreements', '*', 'id'],
                'payroll_deduction_agreements',
            ),
            self::tenant(
                'input_snapshot_json',
                [...$personPath, 'employee', 'id'],
                'payroll_employees',
            ),
            self::tenant(
                'input_snapshot_json',
                [
                    ...$personPath,
                    'employments',
                    '*',
                    'absences',
                    '*',
                    'average_snapshot_id',
                ],
                'payroll_average_earning_snapshots',
                nullable: true,
            ),
            self::tenant(
                'input_snapshot_json',
                [...$personPath, 'employments', '*', 'absences', '*', 'id'],
                'payroll_absences',
            ),
            self::tenant(
                'input_snapshot_json',
                [...$personPath, 'employments', '*', 'average_earning', 'id'],
                'payroll_average_earning_snapshots',
                nullable: true,
            ),
            self::tenant(
                'input_snapshot_json',
                [
                    ...$personPath,
                    'employments',
                    '*',
                    'employment',
                    'employee_id',
                ],
                'payroll_employees',
            ),
            self::tenant(
                'input_snapshot_json',
                [...$personPath, 'employments', '*', 'employment', 'id'],
                'payroll_employments',
            ),
            self::tenant(
                'input_snapshot_json',
                [...$personPath, 'employments', '*', 'employment', 'office_id'],
                'payroll_offices',
                nullable: true,
            ),
            self::tenant(
                'input_snapshot_json',
                [
                    ...$personPath,
                    'employments',
                    '*',
                    'inputs',
                    '*',
                    'component',
                    'component_id',
                ],
                'payroll_component_definitions',
            ),
            self::tenant(
                'input_snapshot_json',
                [...$personPath, 'employments', '*', 'inputs', '*', 'id'],
                'payroll_inputs',
            ),
            self::tenant(
                'input_snapshot_json',
                [
                    ...$personPath,
                    'employments',
                    '*',
                    'ordinary_evidence_profile',
                    'source_term_id',
                ],
                'payroll_employment_terms',
                nullable: true,
            ),
            self::actor(
                'input_snapshot_json',
                [
                    ...$personPath,
                    'employments',
                    '*',
                    'risky_savings_evidence',
                    'approved_by',
                ],
            ),
            self::tenant(
                'input_snapshot_json',
                [
                    ...$personPath,
                    'employments',
                    '*',
                    'risky_savings_evidence',
                    'id',
                ],
                'payroll_risky_savings_evidence',
                nullable: true,
            ),
            self::tenant(
                'input_snapshot_json',
                [
                    ...$personPath,
                    'employments',
                    '*',
                    'risky_savings_evidence',
                    'institution_account_id',
                ],
                'payroll_institution_accounts',
                nullable: true,
            ),
            self::tenant(
                'input_snapshot_json',
                [...$personPath, 'employments', '*', 'term', 'id'],
                'payroll_employment_terms',
                nullable: true,
            ),
            self::tenant(
                'input_snapshot_json',
                [...$personPath, 'employments', '*', 'time_month', 'id'],
                'payroll_time_months',
                nullable: true,
            ),
            self::tenant(
                'input_snapshot_json',
                [
                    ...$personPath,
                    'employments',
                    '*',
                    'time_month',
                    'jmhz_work_summary',
                    'id',
                ],
                'payroll_jmhz_work_month_revisions',
                nullable: true,
            ),
            self::tenant(
                'input_snapshot_json',
                [
                    ...$personPath,
                    'enforcement_evidence',
                    'insolvency',
                    'employment_id',
                ],
                'payroll_employments',
                nullable: true,
            ),
            self::tenant(
                'input_snapshot_json',
                [
                    ...$personPath,
                    'enforcement_evidence',
                    'insolvency',
                    'payment_instruction_id',
                ],
                'payroll_insolvency_payment_instructions',
                nullable: true,
            ),
            self::tenant(
                'input_snapshot_json',
                [...$personPath, 'payout_accounts', '*', 'id'],
                'payroll_person_accounts',
            ),
            self::actor(
                'input_snapshot_json',
                [...$personPath, 'payout_accounts', '*', 'verified_by'],
            ),
            self::tenant(
                'input_snapshot_json',
                [...$personPath, 'payout_rules', '*', 'destination_reference'],
                'payroll_person_accounts',
                nullable: true,
                valuePrefix: 'account:',
                condition: [
                    'path' => [...$personPath, 'payout_rules', '*', 'destination_kind'],
                    'equals' => 'bank',
                ],
            ),
            self::tenant(
                'input_snapshot_json',
                [...$personPath, 'payout_rules', '*', 'id'],
                'payroll_payout_rules',
            ),
            ...self::accumulatorReferences($personPath, 'income_tax'),
            ...self::accumulatorReferences($personPath, 'social_insurance'),
            self::tenant(
                'input_snapshot_json',
                [...$personPath, 'statutory_evidence', 'employee_id'],
                'payroll_employees',
                nullable: true,
            ),
            self::tenant(
                'input_snapshot_json',
                [...$personPath, 'statutory_evidence', 'health', 'coverage', 'id'],
                'payroll_person_health_coverage_history',
                nullable: true,
            ),
            self::tenant(
                'input_snapshot_json',
                [
                    ...$personPath,
                    'statutory_evidence',
                    'health',
                    'minimum_reductions',
                    '*',
                    'id',
                ],
                'payroll_person_health_minimum_reductions',
                nullable: true,
            ),
            self::tenant(
                'input_snapshot_json',
                [
                    ...$personPath,
                    'statutory_evidence',
                    'health',
                    'month_evidence',
                    'id',
                ],
                'payroll_person_health_month_evidence',
                nullable: true,
            ),
            self::tenant(
                'input_snapshot_json',
                [
                    ...$personPath,
                    'statutory_evidence',
                    'health',
                    'other_employer_bases',
                    '*',
                    'id',
                ],
                'payroll_person_health_other_employer_bases',
                nullable: true,
            ),
            self::tenant(
                'input_snapshot_json',
                [
                    ...$personPath,
                    'statutory_evidence',
                    'income_tax',
                    'child_claims',
                    '*',
                    'id',
                ],
                'payroll_person_tax_child_claims',
                nullable: true,
            ),
            self::tenant(
                'input_snapshot_json',
                [
                    ...$personPath,
                    'statutory_evidence',
                    'income_tax',
                    'credit_claims',
                    '*',
                    'id',
                ],
                'payroll_person_tax_credit_claims',
                nullable: true,
            ),
            self::tenant(
                'input_snapshot_json',
                [
                    ...$personPath,
                    'statutory_evidence',
                    'income_tax',
                    'declaration',
                    'id',
                ],
                'payroll_person_tax_declarations',
                nullable: true,
            ),
            self::tenant(
                'input_snapshot_json',
                [
                    ...$personPath,
                    'statutory_evidence',
                    'income_tax',
                    'residence',
                    'id',
                ],
                'payroll_person_tax_residences',
                nullable: true,
            ),
            self::tenant(
                'input_snapshot_json',
                [
                    ...$personPath,
                    'statutory_evidence',
                    'social',
                    'jurisdiction',
                    'id',
                ],
                'payroll_person_social_jurisdictions',
                nullable: true,
            ),
            self::tenant(
                'input_snapshot_json',
                [
                    ...$personPath,
                    'statutory_evidence',
                    'social',
                    'working_pensioner_discount',
                    'id',
                ],
                'payroll_person_social_discount_claims',
                nullable: true,
            ),
            ...self::jmhzSourceReferences($personPath),
        ];

        return self::sortReferences($references);
    }

    /** @return list<array<string,mixed>> */
    public static function resultEmbeddedReferences(): array
    {
        return CompanyBackupPayrollStatutoryResultSnapshotContract::directEmbeddedReferences();
    }

    /**
     * @param list<string> $personPath
     * @return list<EmbeddedHash>
     */
    public static function embeddedHashes(array $personPath = []): array
    {
        return [
            [
                'algorithm' => 'sha256_canonical_json',
                'column' => 'input_snapshot_json',
                'dependencies' => [],
                'hash_path' => [
                    ...$personPath,
                    'employments',
                    '*',
                    'inputs',
                    '*',
                    'component_snapshot_hash',
                ],
                'name' => 'input_component_snapshot',
                'nullable' => false,
                'omit_paths' => [],
                'source_path' => [
                    ...$personPath,
                    'employments',
                    '*',
                    'inputs',
                    '*',
                    'component',
                ],
            ],
            ...self::accumulatorHashes($personPath, 'income_tax'),
            [
                'algorithm' => 'sha256_exact_string',
                'column' => 'input_snapshot_json',
                'dependencies' => [],
                'hash_path' => [
                    ...$personPath,
                    'employments',
                    '*',
                    'time_month',
                    'jmhz_work_summary',
                    'source_snapshot_sha256',
                ],
                'name' => 'input_jmhz_source_snapshot',
                'nullable' => true,
                'omit_paths' => [],
                'source_path' => [
                    ...$personPath,
                    'employments',
                    '*',
                    'time_month',
                    'jmhz_work_summary',
                    'source_snapshot_json',
                ],
            ],
            [
                'algorithm' => 'sha256_canonical_json',
                'column' => 'input_snapshot_json',
                'dependencies' => ['input_jmhz_source_snapshot'],
                'hash_path' => [
                    ...$personPath,
                    'employments',
                    '*',
                    'time_month',
                    'jmhz_work_summary',
                    'summary_sha256',
                ],
                'name' => 'input_jmhz_summary',
                'nullable' => true,
                'omit_paths' => [
                    ['id'],
                    ['source_snapshot_json'],
                    ['summary_sha256'],
                    ['time_month_revision_no'],
                ],
                'source_path' => [
                    ...$personPath,
                    'employments',
                    '*',
                    'time_month',
                    'jmhz_work_summary',
                ],
            ],
            ...self::accumulatorHashes($personPath, 'social_insurance'),
        ];
    }

    /**
     * @param list<string> $personPath
     * @return list<EmbeddedHashReference>
     */
    public static function embeddedHashReferences(array $personPath = []): array
    {
        return [
            self::accumulatorSourceHashReference($personPath, 'income_tax'),
            self::accumulatorSourceHashReference($personPath, 'social_insurance'),
        ];
    }

    /**
     * @param list<string> $personPath
     * @return list<array<string,mixed>>
     */
    private static function accumulatorReferences(
        array $personPath,
        string $kind,
    ): array {
        $state = [
            ...$personPath,
            'statutory_accumulators',
            $kind,
            'state',
        ];

        return [
            self::tenant(
                'input_snapshot_json',
                [...$state, 'approved_results', '*', 'id'],
                'payroll_statutory_accumulator_entries',
                nullable: true,
            ),
            self::tenant(
                'input_snapshot_json',
                [...$state, 'approved_results', '*', 'replaces_entry_id'],
                'payroll_statutory_accumulator_entries',
                nullable: true,
            ),
            self::tenant(
                'input_snapshot_json',
                [...$state, 'approved_results', '*', 'revision_id'],
                'payroll_run_revisions',
                nullable: true,
            ),
            self::tenant(
                'input_snapshot_json',
                [...$state, 'employee_id'],
                'payroll_employees',
                nullable: true,
            ),
            self::tenant(
                'input_snapshot_json',
                [...$state, 'opening_balance', 'id'],
                'payroll_statutory_accumulator_openings',
                nullable: true,
            ),
            self::tenant(
                'input_snapshot_json',
                [...$state, 'opening_balance', 'replaces_opening_id'],
                'payroll_statutory_accumulator_openings',
                nullable: true,
            ),
            self::tenant(
                'input_snapshot_json',
                [...$state, 'supplier_id'],
                'supplier',
                nullable: true,
            ),
        ];
    }

    /**
     * @param list<string> $personPath
     * @return list<array<string,mixed>>
     */
    private static function jmhzSourceReferences(array $personPath): array
    {
        $document = [
            ...$personPath,
            'employments',
            '*',
            'time_month',
            'jmhz_work_summary',
            'source_snapshot_json',
        ];

        return [
            self::tenant(
                'input_snapshot_json',
                ['absences', '*', 'average_snapshot_id'],
                'payroll_average_earning_snapshots',
                nullable: true,
                documentPath: $document,
            ),
            self::tenant(
                'input_snapshot_json',
                ['absences', '*', 'id'],
                'payroll_absences',
                documentPath: $document,
            ),
            self::tenant(
                'input_snapshot_json',
                ['calendars', '*', 'id'],
                'payroll_work_calendars',
                documentPath: $document,
            ),
            self::tenant(
                'input_snapshot_json',
                ['employment', 'id'],
                'payroll_employments',
                documentPath: $document,
            ),
            self::tenant(
                'input_snapshot_json',
                ['employment', 'term_id'],
                'payroll_employment_terms',
                nullable: true,
                documentPath: $document,
            ),
            self::tenant(
                'input_snapshot_json',
                ['employment', 'term_versions', '*', 'id'],
                'payroll_employment_terms',
                documentPath: $document,
            ),
            self::tenant(
                'input_snapshot_json',
                ['supplier_id'],
                'supplier',
                documentPath: $document,
            ),
            self::tenant(
                'input_snapshot_json',
                ['time_entries', '*', 'id'],
                'payroll_time_entries',
                documentPath: $document,
            ),
        ];
    }

    /**
     * @param list<string> $personPath
     * @return list<EmbeddedHash>
     */
    private static function accumulatorHashes(array $personPath, string $kind): array
    {
        $prefix = 'input_' . $kind;
        $state = [
            ...$personPath,
            'statutory_accumulators',
            $kind,
            'state',
        ];
        $opening = [...$state, 'opening_balance'];
        $approved = [...$state, 'approved_results', '*'];

        return [
            [
                'algorithm' => 'sha256_canonical_projection',
                'column' => 'input_snapshot_json',
                'dependencies' => [],
                'hash_path' => [...$approved, 'record_hash'],
                'name' => $prefix . '_approved_record',
                'nullable' => true,
                'omit_paths' => [],
                'projection' => [
                    self::pathField(
                        'calculation_kind',
                        [...$state, 'calculation_kind'],
                    ),
                    self::pathField('employee_id', [...$state, 'employee_id']),
                    self::pathField('period_start', [...$approved, 'period_start']),
                    self::pathField(
                        'replaces_entry_id',
                        [...$approved, 'replaces_entry_id'],
                    ),
                    self::pathField('revision_id', [...$approved, 'revision_id']),
                    self::literalField(
                        'schema_version',
                        'payroll-statutory-accumulator-entry.v1',
                    ),
                    self::pathField(
                        'source_result_hash',
                        [...$approved, 'source_result_hash'],
                    ),
                    self::pathField('supplier_id', [...$state, 'supplier_id']),
                    self::pathField('values', [...$approved, 'values']),
                    self::pathField('year', [...$state, 'year']),
                ],
                'source_path' => $approved,
            ],
            [
                'algorithm' => 'sha256_canonical_projection',
                'column' => 'input_snapshot_json',
                'dependencies' => [],
                'hash_path' => [...$opening, 'record_hash'],
                'name' => $prefix . '_opening_record',
                'nullable' => true,
                'omit_paths' => [],
                'projection' => [
                    self::pathField(
                        'calculation_kind',
                        [...$state, 'calculation_kind'],
                    ),
                    self::pathField('employee_id', [...$state, 'employee_id']),
                    self::pathField('evidence', [...$opening, 'evidence']),
                    self::pathField(
                        'replaces_opening_id',
                        [...$opening, 'replaces_opening_id'],
                    ),
                    self::literalField(
                        'schema_version',
                        'payroll-statutory-opening.v1',
                    ),
                    self::pathField(
                        'source_reference',
                        [...$opening, 'source_reference'],
                    ),
                    self::pathField('supplier_id', [...$state, 'supplier_id']),
                    self::pathField('values', [...$opening, 'values']),
                    self::pathField('year', [...$state, 'year']),
                ],
                'source_path' => $opening,
            ],
            [
                'algorithm' => 'sha256_canonical_json',
                'column' => 'input_snapshot_json',
                'dependencies' => [
                    $prefix . '_approved_record',
                    $prefix . '_opening_record',
                ],
                'hash_path' => [...$state, 'snapshot_hash'],
                'name' => $prefix . '_state',
                'nullable' => true,
                'omit_paths' => [['snapshot_hash']],
                'source_path' => $state,
            ],
        ];
    }

    /**
     * @param list<string> $personPath
     * @return EmbeddedHashReference
     */
    private static function accumulatorSourceHashReference(
        array $personPath,
        string $kind,
    ): array {
        return [
            'column' => 'input_snapshot_json',
            'nullable' => true,
            'path' => [
                ...$personPath,
                'statutory_accumulators',
                $kind,
                'state',
                'approved_results',
                '*',
                'source_result_hash',
            ],
            'target' => 'table:payroll_statutory_person_results',
            'target_hash_column' => 'result_snapshot_hash',
        ];
    }

    /**
     * @param list<string> $path
     * @param array{path:list<string>,equals:string}|null $condition
     * @param list<string> $documentPath
     * @return array<string,mixed>
     */
    private static function tenant(
        string $column,
        array $path,
        string $target,
        bool $nullable = false,
        ?string $valuePrefix = null,
        ?array $condition = null,
        array $documentPath = [],
    ): array {
        return self::reference(
            $column,
            $path,
            $target,
            CompanyBackupReferenceMapping::TenantId,
            $nullable,
            [],
            $valuePrefix,
            $condition,
            $documentPath,
        );
    }

    /**
     * @param list<string> $path
     * @return array<string,mixed>
     */
    private static function actor(string $column, array $path): array
    {
        return self::reference(
            $column,
            $path,
            'users',
            CompanyBackupReferenceMapping::Actor,
            true,
            ['null', 'restore_actor'],
        );
    }

    /**
     * @param list<string> $path
     * @param list<string> $fallbacks
     * @param array{path:list<string>,equals:string}|null $condition
     * @param list<string> $documentPath
     * @return array<string,mixed>
     */
    private static function reference(
        string $column,
        array $path,
        string $target,
        CompanyBackupReferenceMapping $mapping,
        bool $nullable,
        array $fallbacks,
        ?string $valuePrefix = null,
        ?array $condition = null,
        array $documentPath = [],
    ): array {
        return [
            'column' => $column,
            'condition' => $condition,
            ...($documentPath === [] ? [] : [
                'document_nullable' => true,
                'document_path' => $documentPath,
            ]),
            'fallbacks' => $fallbacks,
            'mapping' => $mapping->value,
            'nullable' => $nullable,
            'path' => $path,
            'target' => 'table:' . $target,
            'target_columns' => ['id'],
            ...($valuePrefix === null ? [] : ['value_prefix' => $valuePrefix]),
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
                self::referenceSignature($left),
                self::referenceSignature($right),
            ),
        );
        return $references;
    }

    /** @param array<string,mixed> $reference */
    private static function referenceSignature(array $reference): string
    {
        /** @var list<string> $documentPath */
        $documentPath = $reference['document_path'] ?? [];
        /** @var list<string> $path */
        $path = $reference['path'];
        /** @var list<string> $targetColumns */
        $targetColumns = $reference['target_columns'];
        $signature = (string) $reference['column'] . ':';
        if ($documentPath !== []) {
            $signature .= implode('.', $documentPath) . '::';
        }
        $signature .= implode('.', $path)
            . '->'
            . substr((string) $reference['target'], strlen('table:'))
            . ':'
            . implode(',', $targetColumns);
        $prefix = $reference['value_prefix'] ?? null;
        if (is_string($prefix)) {
            $signature .= '@' . $prefix;
        }
        $condition = $reference['condition'] ?? null;
        if (is_array($condition)) {
            /** @var list<string> $conditionPath */
            $conditionPath = $condition['path'];
            $signature .= '?'
                . implode('.', $conditionPath)
                . '='
                . (string) $condition['equals'];
        }
        return $signature;
    }

    /**
     * @param list<string> $path
     * @return array{key:string,path:list<string>}
     */
    private static function pathField(string $key, array $path): array
    {
        return ['key' => $key, 'path' => $path];
    }

    /** @return array{key:string,literal:string} */
    private static function literalField(string $key, string $literal): array
    {
        return ['key' => $key, 'literal' => $literal];
    }
}
