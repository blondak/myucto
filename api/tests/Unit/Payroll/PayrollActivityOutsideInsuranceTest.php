<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll;

use MyInvoice\Service\Payroll\HealthInsurance\HealthAssessmentComponent;
use MyInvoice\Service\Payroll\HealthInsurance\HealthComponentTreatment;
use MyInvoice\Service\Payroll\HealthInsurance\HealthCorrectionTreatment;
use MyInvoice\Service\Payroll\HealthInsurance\HealthEmploymentKind;
use MyInvoice\Service\Payroll\HealthInsurance\HealthIncomeAttribution;
use MyInvoice\Service\Payroll\HealthInsurance\HealthInsuranceMonthCalculator;
use MyInvoice\Service\Payroll\HealthInsurance\HealthInsuranceMonthInput;
use MyInvoice\Service\Payroll\HealthInsurance\HealthInsuranceMonthResult;
use MyInvoice\Service\Payroll\HealthInsurance\HealthInsuranceRelationshipInput;
use MyInvoice\Service\Payroll\HealthInsurance\HealthInsurerSnapshotStatus;
use MyInvoice\Service\Payroll\HealthInsurance\HealthJurisdictionEvidence;
use MyInvoice\Service\Payroll\HealthInsurance\HealthMinimumTopUpEmployerSelection;
use MyInvoice\Service\Payroll\HealthInsurance\HealthMinimumTopUpResponsibility;
use MyInvoice\Service\Payroll\HealthInsurance\HealthParticipationStatus;
use MyInvoice\Service\Payroll\HealthInsurance\HealthPersonMonthInput;
use MyInvoice\Service\Payroll\HealthInsurance\PayrollExpectedHealthParticipation;
use MyInvoice\Service\Payroll\PayrollEmploymentJmhzActivityFamily;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetDomain;
use MyInvoice\Service\Payroll\SocialInsurance\SocialAssessmentBaseResolver;
use MyInvoice\Service\Payroll\SocialInsurance\SocialAssessmentComponent;
use MyInvoice\Service\Payroll\SocialInsurance\SocialComponentTreatment;
use MyInvoice\Service\Payroll\SocialInsurance\SocialEmploymentKind;
use MyInvoice\Service\Payroll\SocialInsurance\SocialIncomeAttribution;
use MyInvoice\Service\Payroll\SocialInsurance\SocialInsuranceRelationshipInput;
use MyInvoice\Service\Payroll\SocialInsurance\SocialParticipationAggregationGroup;
use MyInvoice\Service\Payroll\SocialInsurance\SocialParticipationResolver;
use MyInvoice\Service\Payroll\SocialInsurance\SocialParticipationStatus;
use MyInvoice\Tests\Fixtures\Payroll\ActivePayrollRulesetFixture;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Evidence a výpočet vztahů datových scénářů JMHZ 4 až 6.
 *
 * Vězeň (1 až 9 s bližším určením 2) je pro pojistné zaměstnanec a počítá se
 * jako pracovní poměr. Druhy činnosti 11 až 14 jsou příjmem ze závislé
 * činnosti, který u plátce účast na pojištění nezakládá: formuláře
 * `jinyPrijem` a `mezinarodniPronajemSily` pojištění nenesou a pravidla
 * podání JMHZ 1.4.5, kap. 13 bod 4 u neuvolněného zastupitele (14) uvádějí,
 * že pojistné se z odměny neodvádí.
 */
final class PayrollActivityOutsideInsuranceTest extends TestCase
{
    /** @return iterable<string,array{string,string,?string,bool}> */
    public static function relationFamilies(): iterable
    {
        yield 'prisoner employment' => ['employment', '1', '2', true];
        yield 'prisoner employment 9' => ['employment', '9', '2', true];
        yield 'prisoner small scale' => ['small_scale_employment', '3', '2', true];
        yield 'specific group stays unsupported' => ['employment', '1', '3', false];
        foreach (['11', '12', '13', '14'] as $code) {
            yield "other income {$code}" => ['employment', $code, '1', true];
            yield "other income {$code} not prisoner" => ['employment', $code, '2', false];
            yield "other income {$code} not small scale" => ['small_scale_employment', $code, '1', false];
            yield "other income {$code} not agreement" => ['dpp', $code, null, false];
        }
    }

    #[DataProvider('relationFamilies')]
    public function testEmploymentEvidenceAcceptsScenarioFourToSixCodes(
        string $relationType,
        string $activityCode,
        ?string $detailCode,
        bool $expected,
    ): void {
        self::assertSame(
            $expected,
            PayrollEmploymentJmhzActivityFamily::matches($relationType, $activityCode, $detailCode),
        );
    }

    public function testOnlyActivitiesElevenToFourteenAreOutsideInsurance(): void
    {
        foreach (['11', '12', '13', '14'] as $code) {
            self::assertTrue(PayrollEmploymentJmhzActivityFamily::isOutsideStatutoryInsurance($code));
        }
        foreach (['1', '9', '10', '15', 'A', 'S', 'M', null] as $code) {
            self::assertFalse(PayrollEmploymentJmhzActivityFamily::isOutsideStatutoryInsurance($code));
        }
    }

    public function testOutsideInsuranceRelationshipDoesNotParticipateAndStaysOutOfGroups(): void
    {
        $baseResolver = new SocialAssessmentBaseResolver();
        $decisions = (new SocialParticipationResolver())->resolve(
            array_map($baseResolver->resolve(...), [
                $this->socialRelationship(
                    'other-income',
                    SocialEmploymentKind::Employment,
                    3_000_000,
                    SocialParticipationAggregationGroup::OutsideInsurance,
                ),
                $this->socialRelationship('dpc', SocialEmploymentKind::Dpc, 300_000, null),
            ]),
            450_000,
            1_200_000,
        );

        self::assertSame(SocialParticipationStatus::DoesNotParticipate, $decisions['other-income']->status);
        self::assertSame(['activity_outside_insurance'], $decisions['other-income']->reasonCodes);
        // Příjem mimo pojištění se do rozhodného příjmu dohod nezapočítá.
        self::assertSame(SocialParticipationStatus::DoesNotParticipate, $decisions['dpc']->status);
        self::assertSame(300_000, $decisions['dpc']->groupIncomeMinorUnits);
    }

    public function testOutsideInsuranceRelationshipHasNoHealthInsurance(): void
    {
        $result = $this->healthMonth(true);

        $relationship = $result->people[0]->relationships[0];
        self::assertSame(HealthParticipationStatus::DoesNotParticipate, $relationship->participation->status);
        self::assertSame(['activity_outside_insurance'], $relationship->participation->reasonCodes);
        self::assertSame(0, $result->totalContributionMinorUnits);

        $ordinary = $this->healthMonth(false);
        self::assertSame(
            HealthParticipationStatus::Participates,
            $ordinary->people[0]->relationships[0]->participation->status,
        );
        self::assertGreaterThan(0, $ordinary->totalContributionMinorUnits);
    }

    public function testHealthInsurerNotificationIsNotExpectedForOutsideInsuranceActivity(): void
    {
        self::assertTrue(PayrollExpectedHealthParticipation::expected('automatic', 'employment', null, null));
        self::assertTrue(PayrollExpectedHealthParticipation::expected('automatic', 'employment', null, null, false, activityCode: '2'));
        foreach (['11', '12', '13', '14'] as $code) {
            self::assertFalse(PayrollExpectedHealthParticipation::expected(
                'automatic',
                'employment',
                3_000_000,
                null,
                false,
                activityCode: $code,
            ));
        }
    }

    private function socialRelationship(
        string $id,
        SocialEmploymentKind $kind,
        int $income,
        ?SocialParticipationAggregationGroup $group,
    ): SocialInsuranceRelationshipInput {
        return new SocialInsuranceRelationshipInput(
            $id,
            $kind,
            null,
            true,
            SocialIncomeAttribution::CurrentEmploymentMonth,
            [new SocialAssessmentComponent(
                'wage',
                $income,
                SocialComponentTreatment::Included,
                SocialComponentTreatment::Included,
            )],
            participationAggregationGroup: $group,
        );
    }

    private function healthMonth(bool $outsideInsurance): HealthInsuranceMonthResult
    {
        $relationship = new HealthInsuranceRelationshipInput(
            'employment-1',
            HealthEmploymentKind::Employment,
            '2026-08-01',
            null,
            HealthIncomeAttribution::CurrentEmploymentMonth,
            [new HealthAssessmentComponent(
                'wage',
                3_000_000,
                HealthComponentTreatment::Included,
                HealthComponentTreatment::Included,
                HealthCorrectionTreatment::CurrentMonth,
            )],
            outsideInsurance: $outsideInsurance,
        );
        $person = new HealthPersonMonthInput(
            'person-1',
            HealthJurisdictionEvidence::CzechRegimeVerified,
            null,
            HealthInsurerSnapshotStatus::Verified,
            '111',
            'insurer:synthetic-snapshot',
            [$relationship],
            [],
            [],
            HealthMinimumTopUpResponsibility::Employee,
            null,
            null,
            HealthMinimumTopUpEmployerSelection::Unverified,
        );

        return (new HealthInsuranceMonthCalculator(
            ActivePayrollRulesetFixture::provider(PayrollRulesetDomain::HealthInsurance),
        ))->calculate(new HealthInsuranceMonthInput('2026-08-31', [$person]));
    }
}
