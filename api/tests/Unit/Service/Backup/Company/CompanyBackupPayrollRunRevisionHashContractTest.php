<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup\Company;

use MyInvoice\Service\Backup\CanonicalJson;
use MyInvoice\Service\Backup\Company\CompanyBackupDerivedHashSet;
use MyInvoice\Service\Backup\Company\CompanyBackupEmbeddedHashSet;
use MyInvoice\Service\Backup\Company\CompanyBackupPayrollRunRevisionHashContract;
use PHPUnit\Framework\TestCase;

final class CompanyBackupPayrollRunRevisionHashContractTest extends TestCase
{
    public function testRefreshesCompleteNestedPayrollHashChain(): void
    {
        $embedded = CompanyBackupEmbeddedHashSet::fromArray(
            CompanyBackupPayrollRunRevisionHashContract::embeddedHashes(),
            'table:payroll_run_revisions',
            ['input_snapshot_json'],
        );
        $derived = CompanyBackupDerivedHashSet::fromArray(
            CompanyBackupPayrollRunRevisionHashContract::derivedHashes(),
            'table:payroll_run_revisions',
            [
                'input_snapshot_json',
                'input_snapshot_hash',
                'result_snapshot_json',
                'result_snapshot_hash',
            ],
        );
        $component = ['code' => 'base_wage', 'component_id' => 13];
        $sourceJson = CanonicalJson::encode([
            'employment' => ['id' => 19],
            'schema_version' => 'jmhz-work-month.v2',
        ]);
        $summaryPayload = [
            'derivation_version' => 'jmhz-work-month.v2',
            'source_snapshot_sha256' => hash('sha256', $sourceJson),
            'values' => ['worked_millihours' => 160000],
        ];
        $summary = $summaryPayload + [
            'id' => 23,
            'source_snapshot_json' => $sourceJson,
            'summary_sha256' => hash(
                'sha256',
                CanonicalJson::encode($summaryPayload),
            ),
            'time_month_revision_no' => 1,
        ];
        $state = $this->incomeTaxState(7, 17, 41, 51, 29);
        $input = CanonicalJson::encode([
            'people' => [[
                'employee' => ['id' => 17],
                'employments' => [[
                    'inputs' => [[
                        'component' => $component,
                        'component_snapshot_hash' => hash(
                            'sha256',
                            CanonicalJson::encode($component),
                        ),
                    ]],
                    'time_month' => ['jmhz_work_summary' => $summary],
                ]],
                'statutory_accumulators' => [
                    'income_tax' => [
                        'issue_code' => null,
                        'state' => $state,
                        'status' => 'verified',
                    ],
                    'schema_version' =>
                        'payroll-person-statutory-accumulators.v1',
                    'social_insurance' => [
                        'issue_code' => 'annual_accumulator_missing',
                        'state' => null,
                        'status' => 'unverified',
                    ],
                ],
            ]],
            'schema_version' => 'payroll-run-input.v2',
            'supplier_id' => 7,
        ]);
        $inputHash = hash('sha256', $input);
        $result = CanonicalJson::encode([
            'schema_version' => 'payroll-run-result.v2',
            'source_snapshot_hash' => $inputHash,
        ]);
        $row = [
            'input_snapshot_json' => $input,
            'input_snapshot_hash' => $inputHash,
            'result_snapshot_json' => $result,
            'result_snapshot_hash' => hash('sha256', $result),
        ];

        $embedded->assertSourceRow($row);
        $restored = $derived->transform(
            $row,
            fn (array $outer): array => $embedded->transform(
                $outer,
                static function (array $changed): array {
                    $snapshot = json_decode(
                        (string) $changed['input_snapshot_json'],
                        true,
                        flags: JSON_THROW_ON_ERROR,
                    );
                    $snapshot['supplier_id'] = 107;
                    $snapshot['people'][0]['employee']['id'] = 117;
                    $employment =& $snapshot['people'][0]['employments'][0];
                    $employment['inputs'][0]['component']['component_id'] = 113;
                    $summary =& $employment['time_month']['jmhz_work_summary'];
                    $source = json_decode(
                        $summary['source_snapshot_json'],
                        true,
                        flags: JSON_THROW_ON_ERROR,
                    );
                    $source['employment']['id'] = 119;
                    $summary['source_snapshot_json'] = CanonicalJson::encode($source);
                    $state =& $snapshot['people'][0]['statutory_accumulators']
                        ['income_tax']['state'];
                    $state['supplier_id'] = 107;
                    $state['employee_id'] = 117;
                    $state['opening_balance']['id'] = 141;
                    $state['opening_balance']['replaces_opening_id'] = 140;
                    $state['approved_results'][0]['id'] = 151;
                    $state['approved_results'][0]['revision_id'] = 129;
                    $state['approved_results'][0]['replaces_entry_id'] = 150;
                    $state['approved_results'][0]['source_result_hash'] =
                        str_repeat('d', 64);
                    $changed['input_snapshot_json'] = CanonicalJson::encode($snapshot);
                    return $changed;
                },
            ),
        );

        $restoredInput = json_decode(
            (string) $restored['input_snapshot_json'],
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $employment = $restoredInput['people'][0]['employments'][0];
        $componentInput = $employment['inputs'][0];
        self::assertSame(
            hash('sha256', CanonicalJson::encode($componentInput['component'])),
            $componentInput['component_snapshot_hash'],
        );
        $workSummary = $employment['time_month']['jmhz_work_summary'];
        self::assertSame(
            hash('sha256', $workSummary['source_snapshot_json']),
            $workSummary['source_snapshot_sha256'],
        );
        $summaryPayload = $workSummary;
        unset(
            $summaryPayload['id'],
            $summaryPayload['source_snapshot_json'],
            $summaryPayload['summary_sha256'],
            $summaryPayload['time_month_revision_no'],
        );
        self::assertSame(
            hash('sha256', CanonicalJson::encode($summaryPayload)),
            $workSummary['summary_sha256'],
        );

        $state = $restoredInput['people'][0]['statutory_accumulators']
            ['income_tax']['state'];
        self::assertSame(
            $this->openingHash($state),
            $state['opening_balance']['record_hash'],
        );
        self::assertSame(
            $this->approvedResultHash($state, $state['approved_results'][0]),
            $state['approved_results'][0]['record_hash'],
        );
        $statePayload = $state;
        unset($statePayload['snapshot_hash']);
        self::assertSame(
            hash('sha256', CanonicalJson::encode($statePayload)),
            $state['snapshot_hash'],
        );
        self::assertSame(
            hash('sha256', (string) $restored['input_snapshot_json']),
            $restored['input_snapshot_hash'],
        );
        $restoredResult = json_decode(
            (string) $restored['result_snapshot_json'],
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        self::assertSame(
            $restored['input_snapshot_hash'],
            $restoredResult['source_snapshot_hash'],
        );
        self::assertSame(
            hash('sha256', (string) $restored['result_snapshot_json']),
            $restored['result_snapshot_hash'],
        );
        $embedded->assertSourceRow($restored);
    }

    /** @return array<string,mixed> */
    private function incomeTaxState(
        int $supplierId,
        int $employeeId,
        int $openingId,
        int $entryId,
        int $revisionId,
    ): array {
        $state = [
            'approved_results' => [[
                'created_at' => '2026-06-30 10:00:00',
                'id' => $entryId,
                'period_start' => '2026-05-01',
                'record_hash' => '',
                'replaces_entry_id' => 50,
                'revision_id' => $revisionId,
                'source_result_hash' => str_repeat('c', 64),
                'values' => ['completed_months' => 1],
            ]],
            'before_period_start' => '2026-06-01',
            'calculation_kind' => 'income_tax',
            'employee_id' => $employeeId,
            'opening_balance' => [
                'created_at' => '2026-01-01 10:00:00',
                'evidence' => ['verified_zero' => true],
                'id' => $openingId,
                'record_hash' => '',
                'replaces_opening_id' => 40,
                'source_reference' => 'synthetic:opening',
                'values' => ['completed_months' => 0],
            ],
            'schema_version' => 'payroll-statutory-accumulator-state.v1',
            'supplier_id' => $supplierId,
            'totals' => ['completed_months' => 1],
            'year' => 2026,
        ];
        $state['opening_balance']['record_hash'] = $this->openingHash($state);
        $state['approved_results'][0]['record_hash'] = $this->approvedResultHash(
            $state,
            $state['approved_results'][0],
        );
        $state['snapshot_hash'] = hash('sha256', CanonicalJson::encode($state));
        return $state;
    }

    /** @param array<string,mixed> $state */
    private function openingHash(array $state): string
    {
        $opening = $state['opening_balance'];
        return hash('sha256', CanonicalJson::encode([
            'calculation_kind' => $state['calculation_kind'],
            'employee_id' => $state['employee_id'],
            'evidence' => $opening['evidence'],
            'replaces_opening_id' => $opening['replaces_opening_id'],
            'schema_version' => 'payroll-statutory-opening.v1',
            'source_reference' => $opening['source_reference'],
            'supplier_id' => $state['supplier_id'],
            'values' => $opening['values'],
            'year' => $state['year'],
        ]));
    }

    /**
     * @param array<string,mixed> $state
     * @param array<string,mixed> $entry
     */
    private function approvedResultHash(array $state, array $entry): string
    {
        return hash('sha256', CanonicalJson::encode([
            'calculation_kind' => $state['calculation_kind'],
            'employee_id' => $state['employee_id'],
            'period_start' => $entry['period_start'],
            'replaces_entry_id' => $entry['replaces_entry_id'],
            'revision_id' => $entry['revision_id'],
            'schema_version' => 'payroll-statutory-accumulator-entry.v1',
            'source_result_hash' => $entry['source_result_hash'],
            'supplier_id' => $state['supplier_id'],
            'values' => $entry['values'],
            'year' => $state['year'],
        ]));
    }
}
