<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission;

use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzPvpojPreviewBuilder;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzPvpojPreviewException;
use PHPUnit\Framework\TestCase;

/**
 * @phpstan-type FrozenEmployment array{
 *   employment:array{id:int,employee_id:int}
 * }
 * @phpstan-type FrozenPerson array{
 *   employee:array{id:int},
 *   employments:list<FrozenEmployment>
 * }
 * @phpstan-type RelationshipFixture array{
 *   employment_id:int,
 *   result_status:string,
 *   input_snapshot:array<string,mixed>,
 *   input_snapshot_hash:string,
 *   result_snapshot:array<string,mixed>,
 *   result_snapshot_hash:string
 * }
 * @phpstan-type PersonFixture array{
 *   employee_id:int,
 *   result_status:string,
 *   input_snapshot:array<string,mixed>,
 *   input_snapshot_hash:string,
 *   result_snapshot:array<string,mixed>,
 *   result_snapshot_hash:string,
 *   relationships:list<RelationshipFixture>
 * }
 * @phpstan-type LiabilityFixture array{
 *   id:int,
 *   liability_reference:string,
 *   direction:string,
 *   recipient_reference:string,
 *   currency_code:string,
 *   amount_minor:int,
 *   previous_liability_id:null,
 *   source_snapshot:array<string,mixed>,
 *   source_snapshot_json:string,
 *   source_snapshot_hash:string
 * }
 * @phpstan-type SourceFixture array{
 *   revision:array{
 *     id:int,
 *     run_id:int,
 *     revision_no:int,
 *     revision_status:string,
 *     current_revision_no:int,
 *     period_start:string,
 *     input_snapshot_json:string,
 *     input_snapshot_hash:string
 *   },
 *   statutory_result:array{
 *     id:int,
 *     supplier_id:int,
 *     revision_id:int,
 *     schema_version:string,
 *     result_status:string,
 *     ruleset_id:string,
 *     ruleset_hash:string,
 *     input_snapshot:array<string,mixed>,
 *     input_snapshot_hash:string,
 *     result_snapshot:array<string,mixed>,
 *     result_snapshot_hash:string,
 *     people:list<PersonFixture>
 *   },
 *   social_liabilities:list<LiabilityFixture>
 * }
 */
final class JmhzPvpojPreviewBuilderTest extends TestCase
{
    private JmhzPvpojPreviewBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new JmhzPvpojPreviewBuilder();
    }

    public function testBuildsDeterministicPvpojOnlyPreview(): void
    {
        $source = $this->source();
        $preview = $this->builder->build(41, $source);

        self::assertSame('2026-06', $preview->period);
        self::assertSame([
            'zakladZamestnavateleA' => 17_000,
            'pojistneZamestnavateleA' => 4_216,
            'pojistneZamestnavateleCelkem' => 4_216,
            'pojistneZamestnance' => 1_207,
            'pojistneCelkem' => 5_423,
        ], $preview->pvpoj['pojistne']);
        self::assertSame([
            'pocetZamestnancu' => 1,
            'uhrnVymerovacichZakladu' => 10_000,
            'pojistneSleva' => 500,
        ], $preview->pvpoj['slevaZamestnavatele']);
        self::assertSame([
            'pocetZamestnancu' => 1,
            'uhrnVymerovacichZakladu' => 10_000,
            'pojistneSleva' => 65,
        ], $preview->pvpoj['slevyZamestnancu']);
        self::assertArrayNotHasKey('slevyZamestnancuOvoZel', $preview->pvpoj);
        self::assertSame(4_858, $preview->pvpoj['pojistneUhrada']);
        $previewData = $preview->toArray();
        $officialSubmission = $this->object(
            $previewData['official_submission'] ?? null,
        );
        self::assertFalse($officialSubmission['supported']);
        self::assertSame(
            'pvpoj_only_identity_snapshot_incomplete',
            $officialSubmission['reason_code'],
        );
        self::assertSame(
            ['employee:7', 'employee:12'],
            array_column($preview->reconciliation, 'employee_reference'),
        );
        self::assertSame(
            hash('sha256', $preview->downloadBytes()),
            $preview->sha256(),
        );

        $reordered = $source;
        $reordered['statutory_result']['people'] = array_reverse(
            $reordered['statutory_result']['people'],
        );
        $reordered['statutory_result']['people'][1]['relationships'] =
            array_reverse(
                $reordered['statutory_result']['people'][1]['relationships'],
            );
        self::assertSame(
            $preview->downloadBytes(),
            $this->builder->build(41, $reordered)->downloadBytes(),
        );
    }

    public function testRejectsRevisionThatIsNotCurrentAndApproved(): void
    {
        $source = $this->source();
        $source['revision']['current_revision_no'] = 3;

        $this->expectCode(
            'jmhz_revision_not_current_approved',
            fn () => $this->builder->build(41, $source),
        );
    }

    public function testRejectsMissingRelationshipResult(): void
    {
        $source = $this->source();
        array_pop($source['statutory_result']['people'][0]['relationships']);

        $this->expectCode(
            'jmhz_relationship_set_mismatch',
            fn () => $this->builder->build(41, $source),
        );
    }

    /**
     * PVPOJ se podává za registraci u OSSZ, a ta je na mzdové účtárně. Běh přes
     * víc účtáren má od rozpadu sociálního závazku víc než jeden závazek ČSSZ —
     * bez pojmenované hlášky by to spadlo na „chybějící závazek", který nechybí.
     */
    public function testRejectsRunSpanningMultipleOffices(): void
    {
        $source = $this->source();
        $input = $source['statutory_result']['input_snapshot'];
        self::assertIsArray($input['people']);
        $input['people'][1]['employments'][0]['employment']['office_id'] = 7;
        $source['statutory_result']['input_snapshot'] = $input;
        $source['statutory_result']['input_snapshot_hash'] = $this->hash($input);
        $source['revision']['input_snapshot_json'] = CanonicalJson::encode($input);
        $source['revision']['input_snapshot_hash'] = $this->hash($input);

        $this->expectCode(
            'jmhz_social_multiple_offices',
            fn () => $this->builder->build(41, $source),
        );
    }

    public function testRejectsFrozenEmploymentWithoutOffice(): void
    {
        $source = $this->source();
        $input = $source['statutory_result']['input_snapshot'];
        self::assertIsArray($input['people']);
        unset($input['people'][0]['employments'][0]['employment']['office_id']);
        $source['statutory_result']['input_snapshot'] = $input;
        $source['statutory_result']['input_snapshot_hash'] = $this->hash($input);
        $source['revision']['input_snapshot_json'] = CanonicalJson::encode($input);
        $source['revision']['input_snapshot_hash'] = $this->hash($input);

        $this->expectCode(
            'jmhz_employment_without_office',
            fn () => $this->builder->build(41, $source),
        );
    }

    public function testRejectsMissingCsszLiability(): void
    {
        $source = $this->source();
        $source['social_liabilities'] = [];

        $this->expectCode(
            'jmhz_social_liability_missing',
            fn () => $this->builder->build(41, $source),
        );
    }

    public function testRejectsTamperedRelationshipSnapshot(): void
    {
        $source = $this->source();
        $relationship = &$source['statutory_result']['people'][0][
            'relationships'
        ][0];
        $relationshipResult = &$relationship['result_snapshot'];
        $base = $relationshipResult['capped_assessment_base_minor_units']
            ?? null;
        self::assertIsInt($base);
        $relationshipResult['capped_assessment_base_minor_units'] = $base + 1;

        $this->expectCode(
            'jmhz_snapshot_hash_mismatch',
            fn () => $this->builder->build(41, $source),
        );
    }

    public function testRejectsPersonAndRootContributionMismatch(): void
    {
        $source = $this->source();
        $root = &$source['statutory_result']['result_snapshot'];
        $contribution = $root['employee_contribution_minor_units'] ?? null;
        self::assertIsInt($contribution);
        $root['employee_contribution_minor_units'] = $contribution + 1;
        $source['statutory_result']['result_snapshot_hash'] = $this->hash($root);

        $this->expectCode(
            'jmhz_social_totals_mismatch',
            fn () => $this->builder->build(41, $source),
        );
    }

    public function testRejectsCsszLiabilityThatDoesNotMatchPvpojPayable(): void
    {
        $source = $this->source();
        $liabilitySource = &$source['social_liabilities'][0]['source_snapshot'];
        $targetAmount = $liabilitySource['target_amount_minor'] ?? null;
        self::assertIsInt($targetAmount);
        $liabilitySource['target_amount_minor'] = $targetAmount + 1;
        $source['social_liabilities'][0]['source_snapshot_json'] =
            CanonicalJson::encode($liabilitySource);
        $source['social_liabilities'][0]['source_snapshot_hash'] = hash(
            'sha256',
            $source['social_liabilities'][0]['source_snapshot_json'],
        );

        $this->expectCode(
            'jmhz_social_liability_mismatch',
            fn () => $this->builder->build(41, $source),
        );
    }

    /**
     * Rozpad § 5a odst. 1 se vykazuje v samostatných blocích A, B a C — ČSSZ ho
     * kontrolami 8, 10 a 167 počítá po blocích (10024 ze 10023, 10026 ze 10025,
     * 10484 ze 10483) a teprve 10027 je jejich součet. Sečíst dvě kategorie do
     * bloku A by podání neprošlo.
     *
     * Zaměstnanec 12 (7 000 Kč) je tu rizikový: 27,8 % = 1 946 Kč; zbylých
     * 10 000 Kč zůstává v písm. a) s 24,8 % = 2 480 Kč.
     */
    public function testReportsSeparateBlocksForEachRateCategory(): void
    {
        $source = $this->source();
        $relationship = &$source['statutory_result']['people'][0]['relationships'][0];
        $relationship['result_snapshot']['employer_rate_category'] = 'risk_employment';
        $relationship['result_snapshot_hash'] = $this->hash($relationship['result_snapshot']);
        unset($relationship);

        $root = &$source['statutory_result']['result_snapshot'];
        $root['employer_categories'] = [
            [
                'category' => 'ordinary',
                'paragraph5a_letter' => 'a',
                'assessment_base_minor_units' => 1_000_000,
                'contribution_minor_units' => 248_000,
            ],
            [
                'category' => 'risk_employment',
                'paragraph5a_letter' => 'c',
                'assessment_base_minor_units' => 700_000,
                'contribution_minor_units' => 194_600,
            ],
        ];
        $root['employer_contribution_before_discount_minor_units'] = 442_600;
        $root['employer_contribution_minor_units'] = 392_600;
        $rootHash = $this->hash($root);
        unset($root);
        $source['statutory_result']['result_snapshot_hash'] = $rootHash;

        $liability = $source['social_liabilities'][0]['source_snapshot'];
        $liability['statutory_result_hash'] = $rootHash;
        $liability['employer_contribution_minor'] = 392_600;
        $liability['target_amount_minor'] = 506_800;
        $liability['delta_signed_minor'] = 506_800;
        $source['social_liabilities'][0]['source_snapshot'] = $liability;
        $source['social_liabilities'][0]['source_snapshot_json'] =
            CanonicalJson::encode($liability);
        $source['social_liabilities'][0]['source_snapshot_hash'] = $this->hash($liability);
        $source['social_liabilities'][0]['amount_minor'] = 506_800;

        $preview = $this->builder->build(41, $source);

        self::assertSame([
            'zakladZamestnavateleA' => 10_000,
            'pojistneZamestnavateleA' => 2_480,
            'zakladZamestnavateleC' => 7_000,
            'pojistneZamestnavateleC' => 1_946,
            'pojistneZamestnavateleCelkem' => 4_426,
            'pojistneZamestnance' => 1_207,
            'pojistneCelkem' => 5_633,
        ], $preview->pvpoj['pojistne']);
        self::assertArrayNotHasKey('zakladZamestnavateleB', $preview->pvpoj['pojistne']);
    }

    public function testRejectsUnsupportedEmployerRateCategory(): void
    {
        $source = $this->source();
        $relationship = &$source['statutory_result']['people'][0][
            'relationships'
        ][0];
        $relationship['result_snapshot']['employer_rate_category'] =
            'risk_employment';
        $relationship['result_snapshot_hash'] = $this->hash(
            $relationship['result_snapshot'],
        );

        $this->expectCode(
            'jmhz_pvpoj_rate_category_unsupported',
            fn () => $this->builder->build(41, $source),
        );
    }

    /**
     * @return SourceFixture
     */
    private function source(): array
    {
        $frozenPeople = [
            $this->frozenPerson(7, [70, 71]),
            $this->frozenPerson(12, [120]),
        ];
        $input = [
            'schema_version' => 'payroll-run-input.v2',
            'supplier_id' => 41,
            'office_id' => 4,
            'period_start' => '2026-06-01',
            'people' => $frozenPeople,
        ];
        $people = [
            $this->person(
                $frozenPeople[1],
                12,
                [
                    $this->relationship(
                        $frozenPeople[1]['employments'][0],
                        120,
                        700_000,
                        false,
                    ),
                ],
                700_000,
                49_700,
                0,
                49_700,
            ),
            $this->person(
                $frozenPeople[0],
                7,
                [
                    $this->relationship(
                        $frozenPeople[0]['employments'][1],
                        71,
                        0,
                        false,
                        'does_not_participate',
                    ),
                    $this->relationship(
                        $frozenPeople[0]['employments'][0],
                        70,
                        1_000_000,
                        true,
                    ),
                ],
                1_000_000,
                71_000,
                6_500,
                64_500,
                'evidence:pensioner:7',
            ),
        ];
        $root = [
            'calculation_date' => '2026-06-30',
            'status' => 'calculated',
            'participating_assessment_base_minor_units' => 1_700_000,
            'capped_assessment_base_minor_units' => 1_700_000,
            'employee_contribution_minor_units' => 114_200,
            'employer_contribution_before_discount_minor_units' => 421_600,
            'part_time_discount_assessment_base_minor_units' => 1_000_000,
            'part_time_discount_minor_units' => 50_000,
            'employer_contribution_minor_units' => 371_600,
            'issues' => [],
            'ruleset_id' => 'cz-social-2026',
            'ruleset_hash' => str_repeat('b', 64),
        ];
        $liability = [
            'schema_reference' => 'payroll-payment-social-insurance-source.v1',
            'run_id' => 19,
            'revision_id' => 53,
            'revision_no' => 2,
            'statutory_result_hash' => $this->hash($root),
            'logical_reference' => 'social-insurance:office:4',
            'recipient_reference' => 'institution:social_security:110:account:5',
            'payroll_office_id' => 4,
            'employee_contribution_minor' => 114_200,
            'employer_contribution_minor' => 371_600,
            'target_amount_minor' => 485_800,
            'prior_signed_minor' => 0,
            'delta_signed_minor' => 485_800,
        ];

        return [
            'revision' => [
                'id' => 53,
                'run_id' => 19,
                'revision_no' => 2,
                'revision_status' => 'approved',
                'current_revision_no' => 2,
                'period_start' => '2026-06-01',
                'input_snapshot_json' => CanonicalJson::encode($input),
                'input_snapshot_hash' => $this->hash($input),
            ],
            'statutory_result' => [
                'id' => 71,
                'supplier_id' => 41,
                'revision_id' => 53,
                'schema_version' => 'payroll-social-result.v1',
                'result_status' => 'calculated',
                'ruleset_id' => 'cz-social-2026',
                'ruleset_hash' => str_repeat('b', 64),
                'input_snapshot' => $input,
                'input_snapshot_hash' => $this->hash($input),
                'result_snapshot' => $root,
                'result_snapshot_hash' => $this->hash($root),
                'people' => $people,
            ],
            'social_liabilities' => [[
                'id' => 81,
                'liability_reference' => 'social-insurance:office:4',
                'direction' => 'outgoing',
                'recipient_reference' =>
                    'institution:social_security:110:account:5',
                'currency_code' => 'CZK',
                'amount_minor' => 485_800,
                'previous_liability_id' => null,
                'source_snapshot' => $liability,
                'source_snapshot_json' => CanonicalJson::encode($liability),
                'source_snapshot_hash' => $this->hash($liability),
            ]],
        ];
    }

    /**
     * @param list<int> $employmentIds
     * @return FrozenPerson
     */
    private function frozenPerson(int $employeeId, array $employmentIds): array
    {
        return [
            'employee' => ['id' => $employeeId],
            'employments' => array_map(
                static fn (int $employmentId): array => [
                    'employment' => [
                        'id' => $employmentId,
                        'employee_id' => $employeeId,
                        'office_id' => 4,
                    ],
                ],
                $employmentIds,
            ),
        ];
    }

    /**
     * @param FrozenPerson $input
     * @param list<RelationshipFixture> $relationships
     * @return PersonFixture
     */
    private function person(
        array $input,
        int $employeeId,
        array $relationships,
        int $base,
        int $beforeDiscount,
        int $discount,
        int $afterDiscount,
        ?string $evidence = null,
    ): array {
        $result = [
            'person_id' => "employee:{$employeeId}",
            'status' => 'calculated',
            'capped_assessment_base_minor_units' => $base,
            'employee_contribution_before_discount_minor_units' =>
                $beforeDiscount,
            'working_pensioner_discount_minor_units' => $discount,
            'employee_contribution_minor_units' => $afterDiscount,
            'working_pensioner_discount_evidence_reference' => $evidence,
            'issues' => [],
        ];

        return [
            'employee_id' => $employeeId,
            'result_status' => 'calculated',
            'input_snapshot' => $input,
            'input_snapshot_hash' => $this->hash($input),
            'result_snapshot' => $result,
            'result_snapshot_hash' => $this->hash($result),
            'relationships' => $relationships,
        ];
    }

    /**
     * @param FrozenEmployment $input
     * @return RelationshipFixture
     */
    private function relationship(
        array $input,
        int $employmentId,
        int $base,
        bool $partTimeDiscount,
        string $participation = 'participates',
    ): array {
        $result = [
            'relationship_id' => "employment:{$employmentId}",
            'participation' => [
                'relationship_id' => "employment:{$employmentId}",
                'status' => $participation,
                'reason_codes' => ['synthetic'],
            ],
            'capped_assessment_base_minor_units' => $base,
            'part_time_employer_discount' =>
                $partTimeDiscount ? 'verified' : 'not_claimed',
            'part_time_employer_discount_evidence_reference' =>
                $partTimeDiscount ? "evidence:part-time:{$employmentId}" : null,
            'employer_rate_category' => 'ordinary',
        ];

        return [
            'employment_id' => $employmentId,
            'result_status' => 'calculated',
            'input_snapshot' => $input,
            'input_snapshot_hash' => $this->hash($input),
            'result_snapshot' => $result,
            'result_snapshot_hash' => $this->hash($result),
        ];
    }

    /** @param array<string,mixed> $value */
    private function hash(array $value): string
    {
        return hash('sha256', CanonicalJson::encode($value));
    }

    /** @param callable():mixed $callback */
    private function expectCode(string $code, callable $callback): void
    {
        try {
            $callback();
            self::fail("Očekávána chyba {$code}.");
        } catch (JmhzPvpojPreviewException $exception) {
            self::assertSame($code, $exception->validationCode);
        }
    }

    /** @return array<string,mixed> */
    private function object(mixed $value): array
    {
        self::assertIsArray($value);
        self::assertFalse(array_is_list($value));

        $result = [];
        foreach ($value as $key => $item) {
            self::assertIsString($key);
            $result[$key] = $item;
        }

        return $result;
    }
}
