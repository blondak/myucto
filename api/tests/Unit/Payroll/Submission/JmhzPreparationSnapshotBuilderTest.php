<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission;

use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzPreparationSnapshotBuilder;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzPreparationSnapshotException;
use PHPUnit\Framework\TestCase;

final class JmhzPreparationSnapshotBuilderTest extends TestCase
{
    public function testBuildsNormalizedBlockedScenarioOneEvidence(): void
    {
        $snapshot = (new JmhzPreparationSnapshotBuilder())->build(
            7,
            'test',
            $this->source(),
            [],
            [],
        );

        self::assertSame(
            'payroll-jmhz-preparation-source.v1',
            $snapshot->payload['schema_reference'],
        );
        self::assertSame('blocked', $snapshot->readiness()['status']);
        self::assertContains(
            'scenario_selector_not_frozen',
            $snapshot->payload['readiness_issue_codes'],
        );
        self::assertContains(
            'eldp_block_missing',
            $snapshot->payload['readiness_issue_codes'],
        );
        self::assertArrayHasKey('header', $snapshot->payload);
        self::assertArrayHasKey('employer_summary', $snapshot->payload);
        self::assertArrayHasKey('people', $snapshot->payload);
        self::assertArrayNotHasKey('run_input', $snapshot->payload);
        self::assertArrayNotHasKey('run_result', $snapshot->payload);
        self::assertSame(
            101,
            $snapshot->payload['people'][0]['employments'][0]['employment_id'],
        );
        $public = CanonicalJson::encode($snapshot->readiness());
        self::assertStringNotContainsString('101', $public);
        self::assertStringNotContainsString('Synthetic Person', $public);
        self::assertFalse($snapshot->readiness()['official_submission_supported']);
    }

    public function testRejectsResultThatDoesNotBelongToInputSnapshot(): void
    {
        $source = $this->source();
        $result = json_decode(
            $source['revision']['result_snapshot_json'],
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($result);
        $result['source_snapshot_hash'] = str_repeat('f', 64);
        $source['revision']['result_snapshot_json'] = CanonicalJson::encode($result);
        $source['revision']['result_snapshot_hash'] = hash(
            'sha256',
            $source['revision']['result_snapshot_json'],
        );

        $this->expectException(JmhzPreparationSnapshotException::class);
        $this->expectExceptionMessage('stejneho zmrazeneho vstupu');
        (new JmhzPreparationSnapshotBuilder())->build(
            7,
            'test',
            $source,
            [],
            [],
        );
    }

    public function testRejectsTamperedNestedComponentSnapshot(): void
    {
        $source = $this->source(true);

        $this->expectException(JmhzPreparationSnapshotException::class);
        $this->expectExceptionMessage('mzdove slozky');
        (new JmhzPreparationSnapshotBuilder())->build(
            7,
            'test',
            $source,
            [],
            [],
        );
    }

    public function testRejectsExtraSocialInsuranceRelationship(): void
    {
        $source = $this->source();
        $result = json_decode(
            $source['revision']['result_snapshot_json'],
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($result);
        $result['people'][0]['statutory']['social_insurance']['relationships'][] = [
            'relationship_id' => 'employment:999',
            'part_time_employer_discount' => 'not_claimed',
        ];
        $source['revision']['result_snapshot_json'] = CanonicalJson::encode($result);
        $source['revision']['result_snapshot_hash'] = hash(
            'sha256',
            $source['revision']['result_snapshot_json'],
        );

        $this->expectException(JmhzPreparationSnapshotException::class);
        $this->expectExceptionMessage('presne zmrazene pracovni vztahy');
        (new JmhzPreparationSnapshotBuilder())->build(
            7,
            'test',
            $source,
            [],
            [],
        );
    }

    /** @return array<string,mixed> */
    private function source(bool $tamperedComponent = false): array
    {
        $inputs = [];
        if ($tamperedComponent) {
            $component = [
                'component_id' => 501,
                'component_row_version' => 1,
                'code' => 'SYNTHETIC',
                'jmhz_treatment' => 'excluded',
            ];
            $inputs[] = [
                'id' => 601,
                'amount_minor' => 100_000,
                'component_snapshot_hash' => str_repeat('0', 64),
                'component' => $component,
            ];
        }
        $employment = [
            'employment' => [
                'id' => 101,
                'employee_id' => 11,
                'relation_type' => 'employment',
            ],
            'term' => [
                'id' => 201,
                'row_version' => 1,
                'jmhz_external_codebooks_verified_for_period' => false,
                'jmhz_apz_contribution_status' => 'unverified',
                'jmhz_apz_instrument_code' => null,
                'jmhz_functional_benefits_status' => 'unverified',
                'jmhz_temporary_assignment_status' => 'unverified',
                'risky_work' => false,
            ],
            'time_month' => null,
            'inputs' => $inputs,
        ];
        $input = [
            'schema_version' => 'payroll-run-input.v2',
            'supplier_id' => 7,
            'period_start' => '2026-07-01',
            'period_end' => '2026-07-31',
            'employer' => [
                'name' => 'Synthetic Employer',
                'identification_number' => '00000019',
            ],
            'people' => [[
                'employee' => [
                    'id' => 11,
                    'full_name' => 'Synthetic Person',
                ],
                'employments' => [$employment],
            ]],
        ];
        $inputJson = CanonicalJson::encode($input);
        $inputHash = hash('sha256', $inputJson);
        $socialRelationship = [
            'relationship_id' => 'employment:101',
            'part_time_employer_discount' => 'not_claimed',
        ];
        $result = [
            'schema_version' => 'payroll-run-result.v2',
            'source_snapshot_hash' => $inputHash,
            'people' => [[
                'employee_id' => 11,
                'employments' => [[
                    'employment_id' => 101,
                    'totals' => [],
                ]],
                'statutory' => [
                    'social_insurance' => [
                        'status' => 'calculated',
                        'working_pensioner_discount_minor_units' => 0,
                        'relationships' => [$socialRelationship],
                    ],
                ],
            ]],
        ];
        $resultJson = CanonicalJson::encode($result);

        return [
            'revision' => [
                'id' => 301,
                'run_id' => 401,
                'revision_no' => 1,
                'current_revision_no' => 1,
                'revision_kind' => 'regular',
                'status' => 'approved',
                'period_start' => '2026-07-01',
                'ruleset_manifest_hash' => str_repeat('a', 64),
                'input_snapshot_json' => $inputJson,
                'input_snapshot_hash' => $inputHash,
                'result_snapshot_json' => $resultJson,
                'result_snapshot_hash' => hash('sha256', $resultJson),
            ],
            'office' => [
                'id' => 9,
                'social_security_variable_symbol' => '1234567890',
            ],
        ];
    }
}
