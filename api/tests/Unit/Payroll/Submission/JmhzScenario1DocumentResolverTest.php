<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission;

use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzPreparationSnapshotBuilder;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzPreparationSnapshotException;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzPvpojPreview;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzScenario1DocumentResolver;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzVerifiedPreparationSnapshot;
use PHPUnit\Framework\TestCase;

final class JmhzScenario1DocumentResolverTest extends TestCase
{
    public function testSourceReadyV4RemainsBlockedByUnfrozenLegalDecisions(): void
    {
        $resolution = (new JmhzScenario1DocumentResolver())->resolve(
            $this->preparation(),
            $this->pvpoj(),
        );

        self::assertSame('blocked', $resolution->status());
        self::assertNotNull($resolution->candidate);
        self::assertSame(
            [
                'jmhz_attribute_10116_unresolved',
                'jmhz_attribute_10546_unresolved',
                'jmhz_interaction_in13_unresolved',
                'jmhz_interaction_in28_unresolved',
                'jmhz_interaction_in30_unresolved',
            ],
            array_column(
                array_map(
                    static fn ($blocker): array => $blocker->toArray(),
                    $resolution->blockers,
                ),
                'code',
            ),
        );
        self::assertSame(
            ['IN13' => null, 'IN28' => null, 'IN30' => null],
            $resolution->candidate->payload['interactions'],
        );
        self::assertSame(
            ['10328' => 1000, '10329' => 1000, '10330' => 0, '10331' => 0],
            $resolution->candidate->payload['people'][0]['employments'][0]
                ['earnings_by_attribute_czk'],
        );
        self::assertSame(
            $resolution->candidate->sha256(),
            (new JmhzScenario1DocumentResolver())
                ->resolve($this->preparation(), $this->pvpoj())
                ->candidate?->sha256(),
        );
    }

    public function testHistoricalPreparationIsVerifiedButNotNormalized(): void
    {
        $preparation = $this->preparation();
        $preparation = new JmhzVerifiedPreparationSnapshot(
            $preparation->id,
            $preparation->supplierId,
            $preparation->environment,
            $preparation->runId,
            $preparation->sourceRevisionId,
            $preparation->revisionNo,
            $preparation->periodStart,
            $preparation->periodEnd,
            $preparation->scenarioKey,
            JmhzPreparationSnapshotBuilder::PREVIOUS_BUILDER_VERSION,
            $preparation->sourceManifestSha256,
            $preparation->readinessSha256,
            $preparation->snapshotFingerprint,
            $preparation->manifest,
            $preparation->readiness,
            $preparation->payload,
        );

        $resolution = (new JmhzScenario1DocumentResolver())->resolve(
            $preparation,
            null,
        );

        self::assertNull($resolution->candidate);
        self::assertSame(
            'jmhz_scenario1_source_version_unsupported',
            $resolution->blockers[0]->code,
        );
    }

    public function testMissingStatutoryBranchesAndPvpojAreExplicitBlockers(): void
    {
        $preparation = $this->preparation();
        $payload = $preparation->payload;
        unset($payload['people'][0]['person_summary']['statutory']['health_insurance']);
        unset($payload['people'][0]['person_summary']['statutory']['income_tax']);
        unset($payload['people'][0]['person_summary']['statutory']['net_pay']);
        $preparation = $this->withPayload($preparation, $payload);

        $resolution = (new JmhzScenario1DocumentResolver())->resolve(
            $preparation,
            null,
        );
        $codes = array_map(
            static fn ($blocker): string => $blocker->code,
            $resolution->blockers,
        );

        self::assertContains('jmhz_scenario1_health_result_not_calculated', $codes);
        self::assertContains('jmhz_scenario1_income_tax_result_not_calculated', $codes);
        self::assertContains('jmhz_scenario1_net_result_not_calculated', $codes);
        self::assertContains('jmhz_scenario1_pvpoj_unavailable', $codes);
    }

    public function testMissingExplicitZeroAndHalereNeverBecomeSilentZero(): void
    {
        $preparation = $this->preparation();
        $payload = $preparation->payload;
        unset($payload['people'][0]['employments'][0]['earnings_by_attribute_minor']['10330']);
        $payload['people'][0]['employments'][0]['earnings_by_attribute_minor']['10329'] = 100_001;
        $preparation = $this->withPayload($preparation, $payload);

        $resolution = (new JmhzScenario1DocumentResolver())->resolve(
            $preparation,
            $this->pvpoj(),
        );
        $codes = array_map(
            static fn ($blocker): string => $blocker->code,
            $resolution->blockers,
        );

        self::assertContains('jmhz_scenario1_earnings_vector_incomplete', $codes);
        self::assertContains('jmhz_scenario1_whole_czk_required', $codes);
        self::assertArrayNotHasKey(
            '10329',
            $resolution->candidate?->payload['people'][0]['employments'][0]
                ['earnings_by_attribute_czk'] ?? [],
        );
    }

    public function testBlockedCandidateCannotBeUsedAsResolvedDocument(): void
    {
        $resolution = (new JmhzScenario1DocumentResolver())->resolve(
            $this->preparation(),
            $this->pvpoj(),
        );

        $this->expectException(JmhzPreparationSnapshotException::class);
        try {
            $resolution->requireResolvedDocument();
        } catch (JmhzPreparationSnapshotException $exception) {
            self::assertSame(
                'jmhz_scenario1_resolution_blocked',
                $exception->validationCode,
            );
            throw $exception;
        }
    }

    private function preparation(): JmhzVerifiedPreparationSnapshot
    {
        $payload = [
            'schema_reference' => 'payroll-jmhz-preparation-source.v4',
            'builder_version' => JmhzPreparationSnapshotBuilder::BUILDER_VERSION,
            'scope' => [
                'supplier_id' => 7,
                'environment' => 'test',
                'run_id' => 401,
                'source_revision_id' => 301,
                'revision_no' => 1,
                'period_start' => '2026-07-01',
                'period_end' => '2026-07-31',
                'scenario_key' => 'scenario_1',
            ],
            'specification' => [
                'package_key' => 'synthetic-package',
                'spec_manifest_sha256' => str_repeat('a', 64),
                'scenario_catalog_key' => 'synthetic-scenarios',
                'scenario_manifest_sha256' => str_repeat('b', 64),
                'control_catalog_key' => 'synthetic-controls',
                'control_manifest_sha256' => str_repeat('c', 64),
            ],
            'source_revision' => [
                'input_snapshot_hash' => str_repeat('d', 64),
                'result_snapshot_hash' => str_repeat('e', 64),
                'ruleset_manifest_hash' => str_repeat('f', 64),
            ],
            'employer_summary' => [
                'employer' => ['identification_number' => '00000019'],
                'office' => ['social_security_variable_symbol' => '1234567890'],
            ],
            'people' => [[
                'employee_id' => 11,
                'person_summary' => [
                    'totals' => ['jmhz_amount_minor' => 100_000],
                    'statutory' => [
                        'status' => 'calculated',
                        'health_insurance' => [
                            'status' => 'calculated',
                            'issues' => [],
                            'employee_contribution_minor_units' => 4_500,
                            'employer_contribution_minor_units' => 9_000,
                        ],
                        'income_tax' => [
                            'status' => 'calculated',
                            'issues' => [],
                            'withholding_tax_minor_units' => 0,
                            'withholding_groups' => [],
                            'advance_tax' => [
                                'tax_credits_minor_units' => 0,
                                'tax_bonus_minor_units' => 0,
                            ],
                        ],
                        'net_pay' => [
                            'status' => 'calculated',
                            'issues' => [],
                            'net_before_deductions_minor_units' => 86_500,
                            'deducted_minor_units' => 0,
                            'deductions' => [],
                        ],
                    ],
                ],
                'employments' => [[
                    'employment_id' => 101,
                    'identity' => [
                        'person_external_identifier' => ['value' => '1000000001'],
                        'jmhz_employment_external_identifier' => ['value' => '2000000000000000000001'],
                    ],
                    'employment' => ['is_primary' => true],
                    'term' => [
                        'activity_code' => '1',
                        'jmhz_relationship_detail_code' => '1',
                    ],
                    'scenario_resolution' => ['scenario_key' => 'scenario_1'],
                    'eldp' => ['confirmation' => ['in03_active' => false, 'in04_active' => false]],
                    'work_month' => [
                        'jmhz_work_summary' => [
                            'interactions' => ['IN07' => false, 'IN08' => false],
                        ],
                    ],
                    'average_earning' => ['average_hourly_minor' => 27_550],
                    'earnings_by_attribute_minor' => [
                        '10328' => 100_000,
                        '10329' => 100_000,
                        '10330' => 0,
                        '10331' => 0,
                    ],
                    'insurance' => ['relationship_id' => 'employment:101'],
                ]],
            ]],
            'source_versions' => ['office_id' => 9, 'employments' => []],
            'readiness_issue_codes' => [],
            'readiness_issues' => [],
        ];
        $readiness = [
            'schema_reference' => 'payroll-jmhz-preparation-readiness.v1',
            'status' => 'source_ready',
            'issue_count' => 0,
            'issues' => [],
            'official_submission_supported' => false,
        ];
        return new JmhzVerifiedPreparationSnapshot(
            501,
            7,
            'test',
            401,
            301,
            1,
            '2026-07-01',
            '2026-07-31',
            'scenario_1',
            JmhzPreparationSnapshotBuilder::BUILDER_VERSION,
            str_repeat('1', 64),
            str_repeat('2', 64),
            str_repeat('3', 64),
            [],
            $readiness,
            $payload,
        );
    }

    private function pvpoj(): JmhzPvpojPreview
    {
        return new JmhzPvpojPreview(
            7,
            401,
            301,
            1,
            '2026-07',
            ['revision_input_hash' => str_repeat('d', 64)],
            [
                'pojistne' => [
                    'zakladZamestnavateleA' => 1_000,
                    'pojistneZamestnavateleA' => 248,
                    'pojistneZamestnance' => 71,
                ],
                'pojistneUhrada' => 319,
            ],
            [['employee_id' => 11]],
        );
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function withPayload(
        JmhzVerifiedPreparationSnapshot $preparation,
        array $payload,
    ): JmhzVerifiedPreparationSnapshot {
        return new JmhzVerifiedPreparationSnapshot(
            $preparation->id,
            $preparation->supplierId,
            $preparation->environment,
            $preparation->runId,
            $preparation->sourceRevisionId,
            $preparation->revisionNo,
            $preparation->periodStart,
            $preparation->periodEnd,
            $preparation->scenarioKey,
            $preparation->builderVersion,
            $preparation->sourceManifestSha256,
            $preparation->readinessSha256,
            $preparation->snapshotFingerprint,
            $preparation->manifest,
            $preparation->readiness,
            $payload,
        );
    }
}
