<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll;

use MyInvoice\Service\Payroll\PayrollAbsenceValidator;
use MyInvoice\Service\Payroll\Ruleset\CzechPayrollRulesets2026;
use MyInvoice\Tests\Fixtures\Payroll\ShiftedYearPayrollRulesetFixture;
use PHPUnit\Framework\TestCase;

final class PayrollAbsenceValidatorTest extends TestCase
{
    public function testSecondQuarterRequiresExactPreviousCalendarQuarter(): void
    {
        $data = $this->validator()->average([
            'employment_id' => 1,
            'applicable_year' => 2026,
            'applicable_quarter' => 2,
            'decisive_from' => '2026-01-01',
            'decisive_to' => '2026-03-31',
            'gross_earnings_minor' => 1,
            'longer_period_allocated_minor' => 0,
            'worked_minutes' => 1,
            'worked_days' => 21,
        ]);

        self::assertSame('2026-01-01', $data['decisive_from']);
        self::assertSame('2026-03-31', $data['decisive_to']);
    }

    public function testFirstQuarterUsesPreviousYearFourthQuarter(): void
    {
        $data = $this->validator()->average([
            'employment_id' => 1,
            'applicable_year' => 2026,
            'applicable_quarter' => 1,
            'decisive_from' => '2025-10-01',
            'decisive_to' => '2025-12-31',
            'gross_earnings_minor' => 1,
            'longer_period_allocated_minor' => 0,
            'worked_minutes' => 1,
            'worked_days' => 21,
        ]);

        self::assertSame(1, $data['applicable_quarter']);
    }

    public function testUnsupportedAbsenceYearFailsClosed(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('není účinný mzdový ruleset domény compensation_averages');
        $this->validator()->absence([
            'employment_id' => 1,
            'absence_type' => 'other',
            'date_from' => '2025-12-31',
            'date_to' => '2025-12-31',
        ]);
    }

    public function testDpnCompensationRateComesFromRulesetNotFromLiteral(): void
    {
        $data = $this->validator()->absence([
            'employment_id' => 1,
            'absence_type' => 'dpn',
            'date_from' => '2026-06-15',
            'date_to' => '2026-06-20',
            'average_snapshot_id' => 7,
        ]);

        self::assertSame('dpn', $data['compensation_policy']);
        self::assertSame(6_000, $data['compensation_rate_basis_points']);
    }

    public function testEntitlementBelowStatutoryMinimumWeeksIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('zákonné minimum 4 týdny');
        $this->validator()->entitlement([
            'employment_id' => 1,
            'leave_year' => 2026,
            'weekly_minutes' => 2_400,
            'entitlement_weeks' => 1,
            'continuous_calendar_days' => 365,
            'worked_equivalent_minutes' => 124_800,
            'rationale' => 'Pokus o podlimitní výměru.',
        ]);
    }

    public function testEntitlementYearWithoutRulesetFailsClosed(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('není účinný mzdový ruleset domény compensation_averages');
        $this->validator()->entitlement([
            'employment_id' => 1,
            'leave_year' => 2027,
            'weekly_minutes' => 2_400,
            'entitlement_weeks' => 4,
            'continuous_calendar_days' => 365,
            'worked_equivalent_minutes' => 124_800,
            'rationale' => 'Rok bez rulesetu.',
        ]);
    }

    public function testAverageYearWithoutRulesetFailsClosed(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('není účinný mzdový ruleset domény compensation_averages');
        $this->validator()->average([
            'employment_id' => 1,
            'applicable_year' => 2027,
            'applicable_quarter' => 2,
            'decisive_from' => '2027-01-01',
            'decisive_to' => '2027-03-31',
            'gross_earnings_minor' => 1,
            'longer_period_allocated_minor' => 0,
            'worked_minutes' => 1,
            'worked_days' => 21,
        ]);
    }

    public function testLeaveEntryPeriodMustMatchTheEntitlementYear(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('musí ležet v roce nároku');
        $this->validator()->assertLeaveEntryPeriod(2026, '2025-12-31');
    }

    public function testCalendarShiftUnlocksNextYearOnceItsRulesetExists(): void
    {
        $validator = new PayrollAbsenceValidator(
            ShiftedYearPayrollRulesetFixture::provider(2027),
        );

        $absence = $validator->absence([
            'employment_id' => 1,
            'absence_type' => 'dpn',
            'date_from' => '2027-06-15',
            'date_to' => '2027-06-20',
            'average_snapshot_id' => 7,
        ]);
        $average = $validator->average([
            'employment_id' => 1,
            'applicable_year' => 2027,
            'applicable_quarter' => 2,
            'decisive_from' => '2027-01-01',
            'decisive_to' => '2027-03-31',
            'gross_earnings_minor' => 1,
            'longer_period_allocated_minor' => 0,
            'worked_minutes' => 1,
            'worked_days' => 21,
        ]);
        $entitlement = $validator->entitlement([
            'employment_id' => 1,
            'leave_year' => 2027,
            'weekly_minutes' => 2_400,
            'entitlement_weeks' => 4,
            'continuous_calendar_days' => 365,
            'worked_equivalent_minutes' => 124_800,
            'rationale' => 'Rok s rulesetem.',
        ]);
        $validator->assertLeaveEntryPeriod(2027, '2027-03-01');

        self::assertSame(6_000, $absence['compensation_rate_basis_points']);
        self::assertSame(2027, $average['applicable_year']);
        self::assertSame(2027, $entitlement['leave_year']);
    }

    private function validator(): PayrollAbsenceValidator
    {
        return new PayrollAbsenceValidator(CzechPayrollRulesets2026::provider());
    }
}
