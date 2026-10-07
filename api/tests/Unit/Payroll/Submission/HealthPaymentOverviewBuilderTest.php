<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission;

use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Submission\HealthInsurance\HealthInsuranceOverviewException;
use MyInvoice\Service\Payroll\Submission\HealthInsurance\HealthPaymentOverviewBuilder;
use PHPUnit\Framework\TestCase;

final class HealthPaymentOverviewBuilderTest extends TestCase
{
    private HealthPaymentOverviewBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new HealthPaymentOverviewBuilder();
    }

    public function testBuildsDeterministicOverviewPerInsurer(): void
    {
        $source = $this->source();
        $overviews = $this->builder->build(41, $source);

        self::assertCount(2, $overviews);
        self::assertSame('regular', $overviews[0]->revisionKind);
        self::assertSame(['111', '201'], array_map(
            static fn ($overview): string => $overview->insurerCode,
            $overviews,
        ));
        self::assertSame(
            ['employee:7', 'employee:12'],
            array_column($overviews[0]->people, 'employee_reference'),
        );
        self::assertSame(1_700_000, $overviews[0]->totals[
            'assessment_base_minor_units'
        ]);
        self::assertSame(229_500, $overviews[0]->totals[
            'total_contribution_minor_units'
        ]);
        self::assertSame(
            'zp-prehled-2026-06-111-revize-2.json',
            $overviews[0]->filename(),
        );
        self::assertSame(
            hash('sha256', $overviews[0]->downloadBytes()),
            $overviews[0]->sha256(),
        );
        self::assertFalse(
            $overviews[0]->toArray()['official_submission']['supported'],
        );

        $reordered = $source;
        $reordered['statutory_result']['people'] = array_reverse(
            $reordered['statutory_result']['people'],
        );
        $rebuilt = $this->builder->build(41, $reordered);

        self::assertSame(
            array_map(
                static fn ($overview): string => $overview->downloadBytes(),
                $overviews,
            ),
            array_map(
                static fn ($overview): string => $overview->downloadBytes(),
                $rebuilt,
            ),
        );
    }

    public function testReportsMinimumAssessmentBaseWhenTopUpApplies(): void
    {
        // Jednatel s odměnou 4 500 Kč: pojistné 3 024 Kč se odvádí z minimálního
        // vyměřovacího základu 22 400 Kč, takže přehled musí vykázat minimum.
        // Kdyby hlásil skutečných 4 500 Kč, nesedělo by 13,5 % z vykázaného základu.
        $overviews = $this->builder->build(41, $this->minimumTopUpSource());

        self::assertCount(1, $overviews);
        self::assertSame(2_240_000, $overviews[0]->totals[
            'assessment_base_minor_units'
        ]);
        self::assertSame(302_400, $overviews[0]->totals[
            'total_contribution_minor_units'
        ]);
        self::assertSame(2_240_000, $overviews[0]->people[0][
            'assessment_base_minor_units'
        ]);
    }

    public function testFallsBackToPlainBaseOnSnapshotsWithoutPpzBase(): void
    {
        // Revize spočtené dřív, než se PPZ základ začal ukládat, klíč nemají.
        // Sestavit přehled musí jít dál — jen z toho, co ve snapshotu skutečně je.
        $overviews = $this->builder->build(41, $this->source());

        self::assertSame(1_700_000, $overviews[0]->totals[
            'assessment_base_minor_units'
        ]);
    }

    /**
     * ZP-03: bývalý zaměstnanec s příjmem po skončení vztahu (ppz_counted =
     * false) se účastní pojištění a výpočet ho má v závazku pojišťovny. Do
     * počtu zaměstnanců přehledu se nezapočítává, do součtů ano. Dřív ho
     * builder vynechal celého a přehled spadl na nesouhlasu součtů.
     */
    public function testFormerEmployeeWithIncomeCountsInTotalsButNotInHeadcount(): void
    {
        $overviews = $this->builder->build(41, $this->formerEmployeeSource());

        self::assertCount(1, $overviews);
        $totals = $overviews[0]->totals;
        self::assertSame(1, $totals['person_count']);
        self::assertSame(1_100_000, $totals['assessment_base_minor_units']);
        self::assertSame(148_500, $totals['total_contribution_minor_units']);
        self::assertCount(2, $overviews[0]->people);
        $former = array_values(array_filter(
            $overviews[0]->people,
            static fn (array $person): bool => $person['employee_reference'] === 'employee:9',
        ));
        self::assertFalse($former[0]['ppz_counted']);
        self::assertArrayNotHasKey(
            'ppz_counted',
            array_values(array_filter(
                $overviews[0]->people,
                static fn (array $person): bool => $person['employee_reference'] === 'employee:7',
            ))[0],
            'Započtená osoba nese stejný tvar jako dřív — otisk běžných přehledů se nemění.',
        );
    }

    /** Osoba bez účasti (a mimo závazek) do přehledu nepatří ani teď. */
    public function testPersonWithoutParticipationStaysOutOfTheOverview(): void
    {
        $overviews = $this->builder->build(41, $this->source([
            $this->person(9, 'Syntetická osoba bez účasti', '111', 50_000, 0, 0, false, 'does_not_participate'),
        ]));

        self::assertSame(
            ['employee:7', 'employee:12'],
            array_column($overviews[0]->people, 'employee_reference'),
        );
    }

    /** Vada u jiné pojišťovny nesmí zablokovat přehled té, o kterou jde. */
    public function testMismatchAtAnotherInsurerDoesNotBlockTheRequestedOne(): void
    {
        $source = $this->source();
        $source['statutory_result']['people'] = array_values(array_filter(
            $source['statutory_result']['people'],
            static fn (array $person): bool => $person['employee_id'] !== 28,
        ));

        $overviews = $this->builder->build(41, $source, '111');
        self::assertSame(['111'], array_map(
            static fn ($overview): string => $overview->insurerCode,
            $overviews,
        ));

        try {
            $this->builder->build(41, $source, '201');
            self::fail('Pojišťovna s nesouhlasem musí dál selhat.');
        } catch (HealthInsuranceOverviewException $exception) {
            self::assertSame('health_insurance_totals_mismatch', $exception->validationCode);
        }
    }

    /**
     * ZP-03 (W2): výpis přehledů po pojišťovnách. Nesouhlas osob a závazku
     * u jedné pojišťovny ji vyřadí do `failures`, přehled druhé se sestaví;
     * dřív spadl celý výpis.
     */
    public function testReportIsolatesTheInsurerThatCannotBeBuilt(): void
    {
        $source = $this->source();
        $source['statutory_result']['people'] = array_values(array_filter(
            $source['statutory_result']['people'],
            static fn (array $person): bool => $person['employee_id'] !== 28,
        ));

        $report = $this->builder->buildReport(41, $source);

        self::assertSame(['111'], array_map(
            static fn ($overview): string => $overview->insurerCode,
            $report['overviews'],
        ));
        self::assertCount(1, $report['failures']);
        self::assertSame('201', $report['failures'][0]->insurerCode);
        self::assertSame('health_insurance_totals_mismatch', $report['failures'][0]->code);
        self::assertInstanceOf(HealthInsuranceOverviewException::class, $report['failures'][0]->cause);

        try {
            $this->builder->build(41, $source);
            self::fail('Přísné sestavení všech pojišťoven musí dál selhat.');
        } catch (HealthInsuranceOverviewException $exception) {
            self::assertSame('health_insurance_totals_mismatch', $exception->validationCode);
        }

        $intact = $this->builder->buildReport(41, $this->source());
        self::assertCount(2, $intact['overviews']);
        self::assertSame([], $intact['failures']);
    }

    public function testRejectsRevisionThatIsNotCurrentAndApproved(): void
    {
        $source = $this->source();
        $source['revision']['current_revision_no'] = 3;

        $this->expectException(HealthInsuranceOverviewException::class);
        $this->expectExceptionMessage('aktuální schválené');
        $this->builder->build(41, $source);
    }

    public function testRejectsTamperedImmutablePersonSnapshot(): void
    {
        $source = $this->source();
        $source['statutory_result']['people'][0]['result_snapshot'][
            'assessment_base_minor_units'
        ]++;

        try {
            $this->builder->build(41, $source);
            self::fail('Pozměněný snapshot musí být odmítnut.');
        } catch (HealthInsuranceOverviewException $exception) {
            self::assertSame(
                'health_insurance_snapshot_hash_mismatch',
                $exception->validationCode,
            );
        }
    }

    public function testRejectsDifferenceBetweenPeopleAndInsurerLiability(): void
    {
        $source = $this->source();
        $source['statutory_result']['result_snapshot'][
            'insurer_liabilities'
        ][0]['total_contribution_minor_units']++;
        $source['statutory_result']['result_snapshot'][
            'total_contribution_minor_units'
        ]++;
        $source['statutory_result']['result_snapshot_hash'] = hash(
            'sha256',
            CanonicalJson::encode(
                $source['statutory_result']['result_snapshot'],
            ),
        );

        try {
            $this->builder->build(41, $source);
            self::fail('Rozdílné kontrolní součty musí být odmítnuty.');
        } catch (HealthInsuranceOverviewException $exception) {
            self::assertSame(
                'health_insurance_totals_mismatch',
                $exception->validationCode,
            );
        }
    }

    public function testRejectsManualReviewPersonEvenWhenRootClaimsCalculated(): void
    {
        $source = $this->source();
        $source['statutory_result']['people'][0]['result_status'] =
            'manual_review';

        try {
            $this->builder->build(41, $source);
            self::fail('Ruční kontrola osoby musí přehled zablokovat.');
        } catch (HealthInsuranceOverviewException $exception) {
            self::assertSame(
                'health_insurance_person_not_calculated',
                $exception->validationCode,
            );
        }
    }

    public function testRejectsInsurerOutsideTheCodebook(): void
    {
        $source = $this->source();
        $root = $source['statutory_result']['result_snapshot'];
        $root['insurer_liabilities'][0]['insurer_code'] = '999';
        $source['statutory_result']['result_snapshot'] = $root;
        $source['statutory_result']['result_snapshot_hash'] = hash(
            'sha256',
            CanonicalJson::encode($root),
        );

        try {
            $this->builder->build(41, $source);
            self::fail('Neexistující pojišťovna musí přehled zablokovat.');
        } catch (HealthInsuranceOverviewException $exception) {
            self::assertSame(
                'health_insurance_insurer_invalid',
                $exception->validationCode,
            );
            self::assertStringContainsString('999', $exception->getMessage());
            self::assertStringContainsString('111 VZP', $exception->getMessage());
        }
    }

    /**
     * @return array{
     *   revision:array<string,mixed>,
     *   statutory_result:array<string,mixed>
     * }
     */
    /** @param list<array<string,mixed>> $extraPeople */
    private function source(array $extraPeople = []): array
    {
        $people = [
            $this->person(12, 'Syntetická osoba B', '111', 700_000, 31_500, 63_000),
            $this->person(7, 'Syntetická osoba A', '111', 1_000_000, 45_000, 90_000),
            $this->person(28, 'Syntetická osoba C', '201', 500_000, 22_500, 45_000),
            ...$extraPeople,
        ];
        $root = [
            'calculation_date' => '2026-06-30',
            'status' => 'calculated',
            'assessment_base_minor_units' => 2_200_000,
            'employee_contribution_minor_units' => 99_000,
            'employer_contribution_minor_units' => 198_000,
            'total_contribution_minor_units' => 297_000,
            'insurer_liabilities' => [
                [
                    'insurer_code' => '201',
                    'person_count' => 1,
                    'assessment_base_minor_units' => 500_000,
                    'employee_contribution_minor_units' => 22_500,
                    'employer_contribution_minor_units' => 45_000,
                    'total_contribution_minor_units' => 67_500,
                ],
                [
                    'insurer_code' => '111',
                    'person_count' => 2,
                    'assessment_base_minor_units' => 1_700_000,
                    'employee_contribution_minor_units' => 76_500,
                    'employer_contribution_minor_units' => 153_000,
                    'total_contribution_minor_units' => 229_500,
                ],
            ],
            'issues' => [],
            'ruleset_id' => 'cz-health-2026',
            'ruleset_hash' => str_repeat('b', 64),
        ];

        return [
            'revision' => [
                'id' => 53,
                'run_id' => 19,
                'revision_no' => 2,
                'revision_kind' => 'regular',
                'revision_status' => 'approved',
                'period_start' => '2026-06-01',
                'current_revision_no' => 2,
            ],
            'statutory_result' => [
                'id' => 71,
                'supplier_id' => 41,
                'revision_id' => 53,
                'schema_version' => 'payroll-health-result.v1',
                'result_status' => 'calculated',
                'ruleset_id' => 'cz-health-2026',
                'ruleset_hash' => str_repeat('b', 64),
                'result_snapshot' => $root,
                'result_snapshot_hash' => hash(
                    'sha256',
                    CanonicalJson::encode($root),
                ),
                'people' => $people,
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function minimumTopUpSource(): array
    {
        $person = $this->person(3, 'Jednatel-společník', '111', 450_000, 261_900, 40_500);
        $person['result_snapshot']['ppz_assessment_base_minor_units'] = 2_240_000;
        $person['result_snapshot_hash'] = hash(
            'sha256',
            CanonicalJson::encode($person['result_snapshot']),
        );
        $root = [
            'calculation_date' => '2026-06-30',
            'status' => 'calculated',
            'assessment_base_minor_units' => 450_000,
            'ppz_assessment_base_minor_units' => 2_240_000,
            'employee_contribution_minor_units' => 261_900,
            'employer_contribution_minor_units' => 40_500,
            'total_contribution_minor_units' => 302_400,
            'insurer_liabilities' => [
                [
                    'insurer_code' => '111',
                    'person_count' => 1,
                    'assessment_base_minor_units' => 450_000,
                    'ppz_assessment_base_minor_units' => 2_240_000,
                    'employee_contribution_minor_units' => 261_900,
                    'employer_contribution_minor_units' => 40_500,
                    'total_contribution_minor_units' => 302_400,
                ],
            ],
            'issues' => [],
            'ruleset_id' => 'cz-health-2026',
            'ruleset_hash' => str_repeat('b', 64),
        ];

        return [
            'revision' => [
                'id' => 53,
                'run_id' => 19,
                'revision_no' => 2,
                'revision_kind' => 'regular',
                'revision_status' => 'approved',
                'period_start' => '2026-06-01',
                'current_revision_no' => 2,
            ],
            'statutory_result' => [
                'id' => 71,
                'supplier_id' => 41,
                'revision_id' => 53,
                'schema_version' => 'payroll-health-result.v1',
                'result_status' => 'calculated',
                'ruleset_id' => 'cz-health-2026',
                'ruleset_hash' => str_repeat('b', 64),
                'result_snapshot' => $root,
                'result_snapshot_hash' => hash(
                    'sha256',
                    CanonicalJson::encode($root),
                ),
                'people' => [$person],
            ],
        ];
    }

    /**
     * Dvě osoby u 111: zaměstnanec se základem 1 000 000 a bývalý zaměstnanec
     * (ppz_counted = false) se základem 100 000 a pojistným 13 500. Závazek
     * pojišťovny obsahuje oba, počet osob jen jednoho — přesně jak ho skládá
     * HealthInsuranceMonthCalculator.
     *
     * @return array<string,mixed>
     */
    private function formerEmployeeSource(): array
    {
        $people = [
            $this->person(7, 'Syntetická osoba A', '111', 1_000_000, 45_000, 90_000),
            $this->person(9, 'Syntetický bývalý zaměstnanec', '111', 100_000, 4_500, 9_000, false),
        ];
        $root = [
            'calculation_date' => '2026-06-30',
            'status' => 'calculated',
            'assessment_base_minor_units' => 1_100_000,
            'employee_contribution_minor_units' => 49_500,
            'employer_contribution_minor_units' => 99_000,
            'total_contribution_minor_units' => 148_500,
            'insurer_liabilities' => [[
                'insurer_code' => '111',
                'person_count' => 1,
                'assessment_base_minor_units' => 1_100_000,
                'employee_contribution_minor_units' => 49_500,
                'employer_contribution_minor_units' => 99_000,
                'total_contribution_minor_units' => 148_500,
            ]],
            'issues' => [],
            'ruleset_id' => 'cz-health-2026',
            'ruleset_hash' => str_repeat('b', 64),
        ];
        $source = $this->source();
        $source['statutory_result']['result_snapshot'] = $root;
        $source['statutory_result']['result_snapshot_hash'] = hash(
            'sha256',
            CanonicalJson::encode($root),
        );
        $source['statutory_result']['people'] = $people;

        return $source;
    }

    /** @return array<string,mixed> */
    private function person(
        int $employeeId,
        string $name,
        string $insurerCode,
        int $base,
        int $employee,
        int $employer,
        bool $ppzCounted = true,
        ?string $participation = null,
    ): array {
        $input = [
            'employee' => [
                'id' => $employeeId,
                'full_name' => $name,
            ],
        ];
        $result = [
            'person_id' => "employee:{$employeeId}",
            'status' => 'calculated',
            'insurer_status' => 'verified',
            'insurer_code' => $insurerCode,
            'ppz_counted' => $ppzCounted,
            'assessment_base_minor_units' => $base,
            'employee_contribution_minor_units' => $employee,
            'employer_contribution_minor_units' => $employer,
            'total_contribution_minor_units' => $employee + $employer,
        ];
        if (!$ppzCounted || $participation !== null) {
            $result['relationships'] = [[
                'relationship_id' => "employment:{$employeeId}",
                'participation' => ['status' => $participation ?? 'participates'],
            ]];
        }

        return [
            'employee_id' => $employeeId,
            'result_status' => 'calculated',
            'input_snapshot' => $input,
            'input_snapshot_hash' => hash(
                'sha256',
                CanonicalJson::encode($input),
            ),
            'result_snapshot' => $result,
            'result_snapshot_hash' => hash(
                'sha256',
                CanonicalJson::encode($result),
            ),
            'relationships' => [],
        ];
    }
}
