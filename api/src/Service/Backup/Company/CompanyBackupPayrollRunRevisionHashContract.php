<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/** Hashový kontrakt připravované projekce payroll_run_revisions. */
final class CompanyBackupPayrollRunRevisionHashContract
{
    /** @return list<array<string,mixed>> */
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

    /** @return list<array<string,mixed>> */
    public static function embeddedHashes(): array
    {
        return [
            [
                'algorithm' => 'sha256_canonical_json',
                'column' => 'input_snapshot_json',
                'dependencies' => [],
                'hash_path' => [
                    'people', '*', 'employments', '*', 'inputs', '*',
                    'component_snapshot_hash',
                ],
                'name' => 'input_component_snapshot',
                'nullable' => false,
                'omit_paths' => [],
                'source_path' => [
                    'people', '*', 'employments', '*', 'inputs', '*', 'component',
                ],
            ],
            ...self::accumulatorHashes('income_tax'),
            [
                'algorithm' => 'sha256_exact_string',
                'column' => 'input_snapshot_json',
                'dependencies' => [],
                'hash_path' => [
                    'people', '*', 'employments', '*', 'time_month',
                    'jmhz_work_summary', 'source_snapshot_sha256',
                ],
                'name' => 'input_jmhz_source_snapshot',
                'nullable' => true,
                'omit_paths' => [],
                'source_path' => [
                    'people', '*', 'employments', '*', 'time_month',
                    'jmhz_work_summary', 'source_snapshot_json',
                ],
            ],
            [
                'algorithm' => 'sha256_canonical_json',
                'column' => 'input_snapshot_json',
                'dependencies' => ['input_jmhz_source_snapshot'],
                'hash_path' => [
                    'people', '*', 'employments', '*', 'time_month',
                    'jmhz_work_summary', 'summary_sha256',
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
                    'people', '*', 'employments', '*', 'time_month',
                    'jmhz_work_summary',
                ],
            ],
            ...self::accumulatorHashes('social_insurance'),
        ];
    }

    /** @return list<array<string,mixed>> */
    public static function embeddedHashReferences(): array
    {
        return [
            self::accumulatorSourceHashReference('income_tax'),
            self::accumulatorSourceHashReference('social_insurance'),
        ];
    }

    /** @return list<array<string,mixed>> */
    private static function accumulatorHashes(string $kind): array
    {
        $prefix = 'input_' . $kind;
        $state = [
            'people', '*', 'statutory_accumulators', $kind, 'state',
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

    /** @return array<string,mixed> */
    private static function accumulatorSourceHashReference(string $kind): array
    {
        return [
            'column' => 'input_snapshot_json',
            'nullable' => true,
            'path' => [
                'people', '*', 'statutory_accumulators', $kind, 'state',
                'approved_results', '*', 'source_result_hash',
            ],
            'target' => 'table:payroll_statutory_person_results',
            'target_hash_column' => 'result_snapshot_hash',
        ];
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
