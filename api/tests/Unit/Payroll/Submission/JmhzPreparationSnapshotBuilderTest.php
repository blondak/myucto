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
            'payroll-jmhz-preparation-source.v6',
            $snapshot->payload['schema_reference'],
        );
        self::assertSame('blocked', $snapshot->readiness()['status']);
        self::assertContains(
            'jmhz_eldp_evidence_missing',
            $snapshot->payload['readiness_issue_codes'],
        );
        self::assertContains(
            'jmhz_ordinary_evidence_missing',
            $snapshot->payload['readiness_issue_codes'],
        );
        self::assertNotContains('scenario_selector_not_frozen', $snapshot->payload['readiness_issue_codes']);
        self::assertNotContains(
            'jmhz_average_hourly_earning_missing',
            $snapshot->payload['readiness_issue_codes'],
        );
        self::assertNotContains(
            'jmhz_primary_employment_unresolved',
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
        self::assertSame(
            'scenario_1',
            $snapshot->payload['people'][0]['employments'][0]['scenario_resolution']['scenario_key'],
        );
        self::assertSame(
            27550,
            $snapshot->payload['people'][0]['employments'][0]
                ['average_earning']['average_hourly_minor'],
        );
        self::assertSame(
            str_repeat('9', 64),
            $snapshot->payload['source_versions']['employments'][0]
                ['average_earning_input_hash'],
        );
        $public = CanonicalJson::encode($snapshot->readiness());
        self::assertStringNotContainsString('"entity_id"', $public);
        self::assertStringNotContainsString('Synthetic Person', $public);
        self::assertFalse($snapshot->readiness()['official_submission_supported']);
    }

    public function testLegacyRunWithoutSelectorEvidenceRemainsBlocked(): void
    {
        $source = $this->source();
        $input = json_decode(
            $source['revision']['input_snapshot_json'],
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($input);
        unset($input['people'][0]['employments'][0]['term']['activity_code']);
        unset($input['people'][0]['employments'][0]['term']['jmhz_relationship_detail_code']);
        $source['revision']['input_snapshot_json'] = CanonicalJson::encode($input);
        $source['revision']['input_snapshot_hash'] = hash(
            'sha256',
            $source['revision']['input_snapshot_json'],
        );
        $result = json_decode(
            $source['revision']['result_snapshot_json'],
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($result);
        $result['source_snapshot_hash'] = $source['revision']['input_snapshot_hash'];
        $source['revision']['result_snapshot_json'] = CanonicalJson::encode($result);
        $source['revision']['result_snapshot_hash'] = hash(
            'sha256',
            $source['revision']['result_snapshot_json'],
        );

        $snapshot = (new JmhzPreparationSnapshotBuilder())->build(
            7,
            'test',
            $source,
            [],
            [],
        );

        self::assertContains(
            'jmhz_scenario_activity_code_missing',
            $snapshot->payload['readiness_issue_codes'],
        );
    }

    public function testMissingAverageEarningAndPrimaryEmploymentStayBlocked(): void
    {
        $source = $this->source();
        $input = json_decode(
            $source['revision']['input_snapshot_json'],
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($input);
        unset($input['people'][0]['employments'][0]['average_earning']);
        $input['people'][0]['employments'][0]['employment']['is_primary'] = false;
        $source['revision']['input_snapshot_json'] = CanonicalJson::encode($input);
        $source['revision']['input_snapshot_hash'] = hash(
            'sha256',
            $source['revision']['input_snapshot_json'],
        );
        $result = json_decode(
            $source['revision']['result_snapshot_json'],
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($result);
        $result['source_snapshot_hash'] = $source['revision']['input_snapshot_hash'];
        $source['revision']['result_snapshot_json'] = CanonicalJson::encode($result);
        $source['revision']['result_snapshot_hash'] = hash(
            'sha256',
            $source['revision']['result_snapshot_json'],
        );

        $snapshot = (new JmhzPreparationSnapshotBuilder())->build(
            7,
            'test',
            $source,
            [],
            [],
        );

        self::assertContains(
            'jmhz_average_hourly_earning_missing',
            $snapshot->payload['readiness_issue_codes'],
        );
        self::assertContains(
            'jmhz_primary_employment_unresolved',
            $snapshot->payload['readiness_issue_codes'],
        );
    }

    public function testVerifiedEldpEvidenceRemovesOnlyEldpBlocker(): void
    {
        $source = $this->source();
        $input = json_decode(
            $source['revision']['input_snapshot_json'],
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($input);
        $input['people'][0]['employments'][0]['time_month'] = [
            'status' => 'approved',
            'jmhz_work_summary_status' => 'frozen_work_summary',
            'jmhz_work_summary' => [
                'id' => 301,
                'derivation_version' => 'jmhz-work-month.v2',
                'summary_sha256' => str_repeat('d', 64),
            ],
        ];
        $source['revision']['input_snapshot_json'] = CanonicalJson::encode($input);
        $source['revision']['input_snapshot_hash'] = hash('sha256', $source['revision']['input_snapshot_json']);
        $result = json_decode(
            $source['revision']['result_snapshot_json'],
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($result);
        $result['source_snapshot_hash'] = $source['revision']['input_snapshot_hash'];
        $source['revision']['result_snapshot_json'] = CanonicalJson::encode($result);
        $source['revision']['result_snapshot_hash'] = hash('sha256', $source['revision']['result_snapshot_json']);
        $eldp = [
            'id' => 901,
            'source_manifest_sha256' => str_repeat('e', 64),
            'snapshot_fingerprint' => str_repeat('f', 64),
            'payload' => [
                'schema_reference' => 'payroll-jmhz-eldp-evidence.v1',
                'scope' => [
                    'supplier_id' => 7,
                    'run_id' => 401,
                    'source_revision_id' => 301,
                    'employee_id' => 11,
                    'employment_id' => 101,
                    'period_start' => '2026-07-01',
                    'scenario_key' => 'scenario_1',
                ],
                'source_revision' => [
                    'input_snapshot_hash' => $source['revision']['input_snapshot_hash'],
                    'result_snapshot_hash' => $source['revision']['result_snapshot_hash'],
                    'ruleset_manifest_hash' => $source['revision']['ruleset_manifest_hash'],
                ],
                'source_evidence' => [
                    'term_id' => 201,
                    'term_row_version' => 1,
                    'work_summary_id' => 301,
                    'work_summary_sha256' => str_repeat('d', 64),
                ],
                'eldp_sections' => [[
                    'ordinal' => 1,
                    'code' => '1++',
                ]],
            ],
        ];

        $snapshot = (new JmhzPreparationSnapshotBuilder())->build(
            7,
            'test',
            $source,
            [],
            [],
            [],
            [101 => $eldp],
        );

        self::assertNotContains(
            'jmhz_eldp_evidence_missing',
            $snapshot->payload['readiness_issue_codes'],
        );
        self::assertSame(
            '1++',
            $snapshot->payload['people'][0]['employments'][0]['eldp']['eldp_sections'][0]['code'],
        );
        self::assertSame(
            901,
            $snapshot->payload['source_versions']['employments'][0]['eldp_evidence_id'],
        );
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

    /**
     * Uplatněná sleva podle § 7a už podání neblokuje: rozpad 10372, 10373
     * a 10374 se dá vykázat, takže zaměstnavatel s doloženým nárokem měsíční
     * hlášení podat může.
     */
    public function testAppliedPartTimeDiscountNoLongerBlocksPreparation(): void
    {
        $codes = $this->readinessCodes($this->sourceWithDiscount([
            'part_time_employer_discount' => 'verified',
            'part_time_employer_discount_outcome' => 'applied',
            'part_time_employer_discount_reason' => 'age_55_plus',
            'agreed_weekly_working_millihours' => 20_000,
        ]));

        foreach ($codes as $code) {
            self::assertStringNotContainsString('part_time_discount', $code);
        }
    }

    /**
     * Zaměstnanci mladšímu 21 let podle § 7a odst. 1 písm. g) sleva náleží
     * i při plném úvazku, takže sjednanou týdenní dobu vykazovat nemusí.
     */
    public function testUnder21DiscountDoesNotRequireAgreedWeeklyWorkingTime(): void
    {
        $codes = $this->readinessCodes($this->sourceWithDiscount([
            'part_time_employer_discount' => 'verified',
            'part_time_employer_discount_outcome' => 'applied',
            'part_time_employer_discount_reason' => 'under_21',
        ]));

        foreach ($codes as $code) {
            self::assertStringNotContainsString('part_time_discount', $code);
        }
    }

    public function testDiscountWithoutAgreedWeeklyWorkingTimeStaysBlocked(): void
    {
        $codes = $this->readinessCodes($this->sourceWithDiscount([
            'part_time_employer_discount' => 'verified',
            'part_time_employer_discount_outcome' => 'applied',
            'part_time_employer_discount_reason' => 'age_55_plus',
        ]));

        self::assertContains(
            'jmhz_employer_part_time_discount_working_time_missing',
            $codes,
        );
    }

    public function testDiscountWithoutStatutoryReasonStaysBlocked(): void
    {
        $codes = $this->readinessCodes($this->sourceWithDiscount([
            'part_time_employer_discount' => 'verified',
            'part_time_employer_discount_outcome' => 'applied',
            'agreed_weekly_working_millihours' => 20_000,
        ]));

        self::assertContains(
            'jmhz_employer_part_time_discount_reason_missing',
            $codes,
        );
    }

    /**
     * Nárok, který limity § 7a odst. 3 vyloučily, se v podání neuplatňuje —
     * XML pro něj žádnou položku nenese, takže ani blokovat nemá co.
     */
    public function testDiscountRefusedByStatutoryLimitsRaisesNoIssue(): void
    {
        $codes = $this->readinessCodes($this->sourceWithDiscount([
            'part_time_employer_discount' => 'verified',
            'part_time_employer_discount_outcome' => 'assessment_base_above_limit',
            'part_time_employer_discount_reason' => 'age_55_plus',
        ]));

        foreach ($codes as $code) {
            self::assertStringNotContainsString('part_time_discount', $code);
        }
    }

    /**
     * @param array<string,mixed> $source
     * @return list<string>
     */
    private function readinessCodes(array $source): array
    {
        $snapshot = (new JmhzPreparationSnapshotBuilder())->build(
            7,
            'test',
            $source,
            [],
            [],
        );
        $codes = $snapshot->payload['readiness_issue_codes'];
        self::assertIsArray($codes);

        return array_values(array_filter($codes, 'is_string'));
    }

    /**
     * @param array<string,mixed> $relationship
     * @return array<string,mixed>
     */
    private function sourceWithDiscount(array $relationship): array
    {
        $source = $this->source();
        $result = json_decode(
            $source['revision']['result_snapshot_json'],
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($result);
        $result['people'][0]['statutory']['social_insurance']['relationships'][0]
            = ['relationship_id' => 'employment:101'] + $relationship;
        $source['revision']['result_snapshot_json'] = CanonicalJson::encode($result);
        $source['revision']['result_snapshot_hash'] = hash(
            'sha256',
            $source['revision']['result_snapshot_json'],
        );

        return $source;
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
                'office_id' => 9,
                'relation_type' => 'employment',
                'is_primary' => true,
            ],
            'term' => [
                'id' => 201,
                'row_version' => 1,
                'activity_code' => '1',
                'jmhz_relationship_detail_code' => '1',
                'jmhz_external_codebooks_verified_for_period' => false,
                'jmhz_apz_contribution_status' => 'unverified',
                'jmhz_apz_instrument_code' => null,
                'jmhz_functional_benefits_status' => 'unverified',
                'jmhz_temporary_assignment_status' => 'unverified',
                'risky_work' => false,
            ],
            'time_month' => null,
            'average_earning' => [
                'id' => 701,
                'row_version' => 1,
                'applicable_year' => 2026,
                'applicable_quarter' => 3,
                'revision_no' => 1,
                'source_kind' => 'probable',
                'average_hourly_minor' => 27550,
                'support_status' => 'supported',
                'status' => 'approved',
                'ruleset_id' => 'synthetic-average-v1',
                'ruleset_hash' => str_repeat('8', 64),
                'input_hash' => str_repeat('9', 64),
            ],
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
            'offices' => [
                [
                    'id' => 9,
                    'code' => 'UC9',
                    'name' => 'Mzdová účtárna 9',
                    'social_security_variable_symbol' => '1234567890',
                    'is_active' => true,
                ],
            ],
        ];
    }

    /**
     * Přehled i hlášení se podávají za REGISTRACI u OSSZ, ne za mzdový běh.
     * Příprava proto musí nést variabilní symbol každé účtárny, ze které se
     * z revize podává — dokud si ho brala z účtárny běhu, byl u celofiremního
     * běhu `NULL` a příprava se nikdy nedostala do stavu `source_ready`.
     */
    public function testRegistrationsComeFromEmploymentsNotFromTheRun(): void
    {
        $snapshot = (new JmhzPreparationSnapshotBuilder())->build(
            7,
            'test',
            $this->sourceWithTwoOffices(),
            [],
            [],
        );

        self::assertSame(
            [
                [
                    'id' => 9,
                    'code' => 'UC9',
                    'name' => 'Mzdová účtárna 9',
                    'social_security_variable_symbol' => '1234567890',
                ],
                [
                    'id' => 12,
                    'code' => 'UC12',
                    'name' => 'Mzdová účtárna 12',
                    'social_security_variable_symbol' => '9990001234',
                ],
            ],
            $snapshot->payload['employer_summary']['offices'],
        );
        self::assertSame([9, 12], $snapshot->payload['source_versions']['office_ids']);
        // Jediná registrace se do `office` promítne, dvě už ne — hlášení si
        // účtárnu musí zvolit.
        self::assertNull($snapshot->payload['employer_summary']['office']);
        self::assertNotContains(
            'social_security_variable_symbol_missing',
            $snapshot->payload['readiness_issue_codes'],
        );
    }

    /**
     * Chybějící variabilní symbol je adresný nález: účetní se z něj musí
     * dozvědět, KTEROU registraci má doplnit.
     */
    public function testRegistrationWithoutVariableSymbolNamesItsOffice(): void
    {
        $source = $this->sourceWithTwoOffices();
        $source['offices'][1]['social_security_variable_symbol'] = null;

        $snapshot = (new JmhzPreparationSnapshotBuilder())->build(
            7,
            'test',
            $source,
            [],
            [],
        );

        $missing = array_values(array_filter(
            $snapshot->payload['readiness_issues'],
            static fn (array $issue): bool =>
                $issue['code'] === 'social_security_variable_symbol_missing',
        ));
        self::assertCount(1, $missing);
        self::assertSame('office', $missing[0]['entity_type']);
        self::assertSame(12, $missing[0]['entity_id']);
        self::assertNull(
            $snapshot->payload['employer_summary']['offices'][1]
                ['social_security_variable_symbol'],
        );
    }

    /**
     * Zdroj se dvěma pracovními vztahy ve dvou mzdových účtárnách.
     *
     * @return array<string,mixed>
     */
    private function sourceWithTwoOffices(): array
    {
        $source = $this->source();
        $input = json_decode(
            $source['revision']['input_snapshot_json'],
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $result = json_decode(
            $source['revision']['result_snapshot_json'],
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($input);
        self::assertIsArray($result);

        $person = $input['people'][0];
        $person['employee']['id'] = 12;
        $person['employments'][0]['employment']['id'] = 102;
        $person['employments'][0]['employment']['employee_id'] = 12;
        $person['employments'][0]['employment']['office_id'] = 12;
        $person['employments'][0]['term']['id'] = 202;
        $input['people'][] = $person;

        $resultPerson = $result['people'][0];
        $resultPerson['employee_id'] = 12;
        $resultPerson['employments'][0]['employment_id'] = 102;
        $resultPerson['statutory']['social_insurance']['relationships'][0]
            ['relationship_id'] = 'employment:102';
        $result['people'][] = $resultPerson;

        $source['revision']['input_snapshot_json'] = CanonicalJson::encode($input);
        $source['revision']['input_snapshot_hash'] = hash(
            'sha256',
            $source['revision']['input_snapshot_json'],
        );
        $source['revision']['result_snapshot_json'] = CanonicalJson::encode($result);
        $source['revision']['result_snapshot_hash'] = hash(
            'sha256',
            $source['revision']['result_snapshot_json'],
        );
        $result = json_decode(
            $source['revision']['result_snapshot_json'],
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($result);
        $result['source_snapshot_hash'] = $source['revision']['input_snapshot_hash'];
        $source['revision']['result_snapshot_json'] = CanonicalJson::encode($result);
        $source['revision']['result_snapshot_hash'] = hash(
            'sha256',
            $source['revision']['result_snapshot_json'],
        );
        $source['offices'][] = [
            'id' => 12,
            'code' => 'UC12',
            'name' => 'Mzdová účtárna 12',
            'social_security_variable_symbol' => '9990001234',
            'is_active' => true,
        ];

        return $source;
    }
}
