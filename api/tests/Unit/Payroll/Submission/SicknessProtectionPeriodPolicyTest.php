<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission;

use MyInvoice\Service\Payroll\Submission\Sickness\SicknessBenefitKind;
use MyInvoice\Service\Payroll\Submission\Sickness\SicknessException;
use MyInvoice\Service\Payroll\Submission\Sickness\SicknessProtectionPeriodPolicy;
use PHPUnit\Framework\TestCase;

/**
 * Ochranná lhůta podle § 15 zák. č. 187/2006 Sb. Data jsou syntetická.
 */
final class SicknessProtectionPeriodPolicyTest extends TestCase
{
    private SicknessProtectionPeriodPolicy $policy;

    protected function setUp(): void
    {
        $this->policy = new SicknessProtectionPeriodPolicy();
    }

    public function testEventDuringEmploymentNeedsNoProtectionPeriod(): void
    {
        $result = $this->policy->assess(
            SicknessBenefitKind::Ose,
            '2026-03-10',
            $this->context('2025-01-01', '2026-03-31'),
        );

        self::assertSame(SicknessProtectionPeriodPolicy::STATUS_DURING_EMPLOYMENT, $result['status']);
    }

    /** Sedm kalendářních dnů ode dne zániku pojištění: 1. až 7. 4. */
    public function testSicknessWithinSevenDaysAfterEndIsCovered(): void
    {
        $result = $this->policy->assess(
            SicknessBenefitKind::Nem,
            '2026-04-07',
            $this->context('2025-01-01', '2026-03-31'),
        );

        self::assertSame(SicknessProtectionPeriodPolicy::STATUS_PROTECTION_PERIOD, $result['status']);
        self::assertSame('2026-04-07', $result['protection_until']);
    }

    public function testSicknessOnEighthDayAfterEndIsRefused(): void
    {
        $this->expectRefused(
            'sickness_event_outside_protection_period',
            SicknessBenefitKind::Nem,
            '2026-04-08',
            $this->context('2025-01-01', '2026-03-31'),
        );
    }

    /** Pojištění trvalo tři dny (29.–31. 3.), ochranná lhůta tedy jen tři dny. */
    public function testShortInsuranceShortensTheProtectionPeriod(): void
    {
        $result = $this->policy->assess(
            SicknessBenefitKind::Nem,
            '2026-04-03',
            $this->context('2026-03-29', '2026-03-31'),
        );
        self::assertSame('2026-04-03', $result['protection_until']);

        $this->expectRefused(
            'sickness_event_outside_protection_period',
            SicknessBenefitKind::Nem,
            '2026-04-04',
            $this->context('2026-03-29', '2026-03-31'),
        );
    }

    /** Skutečný nástup, ne sjednaný, určuje délku pojištění. */
    public function testActualStartDecidesTheInsuredDays(): void
    {
        $context = $this->context('2026-03-01', '2026-03-31');
        $context['actual_start_date'] = '2026-03-30';

        $this->expectRefused(
            'sickness_event_outside_protection_period',
            SicknessBenefitKind::Nem,
            '2026-04-03',
            $context,
        );
    }

    /** § 15 odst. 4 písm. c), d) a f). */
    public function testExcludedEmploymentsHaveNoProtectionPeriod(): void
    {
        $dpp = $this->context('2025-01-01', '2026-03-31');
        $dpp['relation_type'] = 'dpp';
        $this->expectRefused('sickness_protection_period_excluded', SicknessBenefitKind::Nem, '2026-04-02', $dpp);

        $this->expectRefused(
            'sickness_protection_period_excluded',
            SicknessBenefitKind::Nem,
            '2026-04-02',
            $this->context('2025-01-01', '2026-03-31'),
            ['small_scope_income_minor' => 300_000],
        );

        $this->expectRefused(
            'sickness_protection_period_excluded',
            SicknessBenefitKind::Nem,
            '2026-04-02',
            $this->context('2025-01-01', '2026-03-31'),
            ['is_student' => 1, 'within_school_holidays' => 1],
        );
    }

    /**
     * DPN-08, § 15 odst. 4 písm. a): poživateli starobního důchodu (kód 1)
     * a invalidního důchodu třetího stupně (kód 2) ochranná lhůta neplyne.
     * Invalidní důchod prvního nebo druhého stupně (kód 8) ji nevylučuje.
     */
    public function testOldAgeOrThirdDegreeInvalidityPensionerHasNoProtectionPeriod(): void
    {
        $this->expectRefused(
            'sickness_protection_period_excluded',
            SicknessBenefitKind::Nem,
            '2026-07-03',
            $this->context('2020-01-01', '2026-06-30'),
            ['receives_pension' => 1, 'pension_kind' => '1'],
        );
        $this->expectRefused(
            'sickness_protection_period_excluded',
            SicknessBenefitKind::Nem,
            '2026-07-03',
            $this->context('2020-01-01', '2026-06-30'),
            ['receives_pension' => '1', 'pension_kind' => '2'],
        );

        $result = $this->policy->assess(
            SicknessBenefitKind::Nem,
            '2026-07-03',
            $this->context('2020-01-01', '2026-06-30'),
            ['receives_pension' => 1, 'pension_kind' => '8'],
        );
        self::assertSame(SicknessProtectionPeriodPolicy::STATUS_PROTECTION_PERIOD, $result['status']);
    }

    /** Pobírá-li důchod a druh chybí, nárok se nedomýšlí — politika chce druh. */
    public function testPensionWithoutKindIsRefusedUntilKindIsKnown(): void
    {
        $this->expectRefused(
            'sickness_protection_period_pension_kind_missing',
            SicknessBenefitKind::Nem,
            '2026-07-03',
            $this->context('2020-01-01', '2026-06-30'),
            ['receives_pension' => 1, 'pension_kind' => null],
        );
    }

    /** Ošetřovné, DLO, otcovská ani vyrovnávací příspěvek ochrannou lhůtu nemají. */
    public function testBenefitsWithoutProtectionPeriodAreRefusedAfterEnd(): void
    {
        foreach ([SicknessBenefitKind::Ose, SicknessBenefitKind::Dlo, SicknessBenefitKind::Opp, SicknessBenefitKind::Vpm] as $kind) {
            $this->expectRefused(
                'sickness_event_after_employment',
                $kind,
                '2026-04-01',
                $this->context('2025-01-01', '2026-03-31'),
            );
        }
    }

    /** PPM: až 180 dnů u ženy, jejíž pojištění zaniklo v těhotenství. */
    public function testMaternityAllowsUpToOneHundredEightyDays(): void
    {
        $result = $this->policy->assess(
            SicknessBenefitKind::Ppm,
            '2026-09-27',
            $this->context('2024-01-01', '2026-03-31'),
        );
        self::assertSame('2026-09-27', $result['protection_until']);

        $this->expectRefused(
            'sickness_event_outside_protection_period',
            SicknessBenefitKind::Ppm,
            '2026-09-28',
            $this->context('2024-01-01', '2026-03-31'),
        );
    }

    /** @return array<string,mixed> */
    private function context(string $start, ?string $end): array
    {
        return [
            'start_date' => $start,
            'actual_start_date' => null,
            'end_date' => $end,
            'relation_type' => 'employment',
        ];
    }

    /**
     * @param array<string,mixed> $context
     * @param array<string,mixed> $row
     */
    private function expectRefused(
        string $code,
        SicknessBenefitKind $kind,
        string $eventFrom,
        array $context,
        array $row = [],
    ): void {
        try {
            $this->policy->assess($kind, $eventFrom, $context, $row);
            self::fail('Událost mimo ochrannou lhůtu nesmí projít: ' . $code);
        } catch (SicknessException $exception) {
            self::assertSame($code, $exception->validationCode);
            self::assertStringContainsString('§ 15', $exception->getMessage());
        }
    }
}
