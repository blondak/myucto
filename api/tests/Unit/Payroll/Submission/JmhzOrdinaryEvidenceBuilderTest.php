<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission;

use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzOrdinaryEvidenceBuilder;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzOrdinaryEvidenceException;
use PHPUnit\Framework\TestCase;

final class JmhzOrdinaryEvidenceBuilderTest extends TestCase
{
    public function testBuildsExplicitFalseEvidenceFromConsistentApprovedRevision(): void
    {
        $snapshot = (new JmhzOrdinaryEvidenceBuilder())->build(
            7,
            $this->source(),
            $this->facts(),
            12,
            '2026-08-13T12:00:00.000000Z',
        );

        self::assertSame(['10116' => false, '10546' => false], $snapshot->payload['attribute_values']);
        self::assertSame(
            ['IN13', 'IN28', 'IN30'],
            array_column($snapshot->payload['interaction_decisions'], 'interaction_id'),
        );
        self::assertSame(false, $snapshot->payload['derived_interactions'][0]['triggered']);
        self::assertSame(101, $snapshot->payload['scope']['employment_id']);
    }

    public function testMissingEnforcementEvidenceIsRejectedFailClosed(): void
    {
        $source = $this->source();
        $input = json_decode($source['revision']['input_snapshot_json'], true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($input);
        unset($input['people'][0]['enforcement_evidence']);
        $this->replaceInput($source, $input);

        try {
            (new JmhzOrdinaryEvidenceBuilder())->build(
                7,
                $source,
                $this->facts(),
                12,
                '2026-08-13T12:00:00Z',
            );
            self::fail('Chybějící enforcement evidence musí být odmítnuta.');
        } catch (JmhzOrdinaryEvidenceException $exception) {
            self::assertSame('jmhz_ordinary_evidence_source_invalid', $exception->validationCode);
        }
    }

    public function testResultDeductionIsRejectedEvenWhenInputRegistersAreEmpty(): void
    {
        $source = $this->source();
        $result = json_decode($source['revision']['result_snapshot_json'], true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($result);
        $result['people'][0]['statutory']['net_pay']['deducted_minor_units'] = 100;
        $source['revision']['result_snapshot_json'] = CanonicalJson::encode($result);
        $source['revision']['result_snapshot_hash'] = hash('sha256', $source['revision']['result_snapshot_json']);

        try {
            (new JmhzOrdinaryEvidenceBuilder())->build(
                7,
                $source,
                $this->facts(),
                12,
                '2026-08-13T12:00:00Z',
            );
            self::fail('Srážka ve výsledku musí být odmítnuta.');
        } catch (JmhzOrdinaryEvidenceException $exception) {
            self::assertSame('jmhz_ordinary_evidence_deduction_conflict', $exception->validationCode);
        }
    }

    /** @return array<string,false> */
    private function facts(): array
    {
        return [
            'reportable_wage_deductions_recorded' => false,
            'employee_social_discount_claimed' => false,
            'specific_legal_fact_occurred' => false,
            'ozp_employment_support_claimed' => false,
            'deep_mining_work_occurred' => false,
        ];
    }

    /** @return array{revision:array<string,mixed>} */
    private function source(): array
    {
        $enforcement = [
            'claim_register_evidence_complete' => true,
            'claims' => [],
            'insolvency' => ['mode' => 'none'],
        ];
        $input = [
            'schema_version' => 'payroll-run-input.v2',
            'supplier_id' => 7,
            'period_start' => '2026-07-01',
            'people' => [[
                'employee' => ['id' => 11],
                'deduction_agreements' => [],
                'enforcement_evidence' => $enforcement,
                'employments' => [[
                    'employment' => ['id' => 101, 'employee_id' => 11],
                    'term' => [
                        'activity_code' => '1',
                        'jmhz_relationship_detail_code' => '1',
                    ],
                ]],
            ]],
        ];
        $inputJson = CanonicalJson::encode($input);
        $result = [
            'schema_version' => 'payroll-run-result.v2',
            'source_snapshot_hash' => hash('sha256', $inputJson),
            'people' => [[
                'employee_id' => 11,
                'statutory' => [
                    'status' => 'calculated',
                    'net_pay' => [
                        'deductions' => [],
                        'deducted_minor_units' => 0,
                    ],
                ],
                'enforcement' => [
                    'input' => $enforcement,
                    'result' => [
                        'status' => 'supported',
                        'issues' => [],
                        'allocations' => [],
                        'total_withheld_minor_units' => 0,
                        'insolvency_applied' => false,
                    ],
                ],
            ]],
        ];
        $resultJson = CanonicalJson::encode($result);
        return ['revision' => [
            'id' => 301,
            'run_id' => 401,
            'revision_no' => 1,
            'current_revision_no' => 1,
            'revision_kind' => 'regular',
            'status' => 'approved',
            'period_start' => '2026-07-01',
            'input_snapshot_json' => $inputJson,
            'input_snapshot_hash' => hash('sha256', $inputJson),
            'result_snapshot_json' => $resultJson,
            'result_snapshot_hash' => hash('sha256', $resultJson),
            'ruleset_manifest_hash' => str_repeat('a', 64),
        ]];
    }

    /** @param array<string,mixed> $source @param array<string,mixed> $input */
    private function replaceInput(array &$source, array $input): void
    {
        $source['revision']['input_snapshot_json'] = CanonicalJson::encode($input);
        $source['revision']['input_snapshot_hash'] = hash('sha256', $source['revision']['input_snapshot_json']);
        $result = json_decode($source['revision']['result_snapshot_json'], true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($result);
        $result['source_snapshot_hash'] = $source['revision']['input_snapshot_hash'];
        $source['revision']['result_snapshot_json'] = CanonicalJson::encode($result);
        $source['revision']['result_snapshot_hash'] = hash('sha256', $source['revision']['result_snapshot_json']);
    }

}
