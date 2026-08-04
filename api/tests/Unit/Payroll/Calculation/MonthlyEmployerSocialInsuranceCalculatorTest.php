<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Calculation;

use MyInvoice\Service\Payroll\Calculation\MonthlyEmployerSocialInsuranceCalculator;
use MyInvoice\Service\Payroll\Calculation\MonthlyEmployerSocialInsuranceEmployeeInput;
use MyInvoice\Service\Payroll\Calculation\MonthlyEmployerSocialInsuranceInput;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetDomain;
use MyInvoice\Tests\Fixtures\Payroll\ActivePayrollRulesetFixture;
use PHPUnit\Framework\TestCase;

final class MonthlyEmployerSocialInsuranceCalculatorTest extends TestCase
{
    public function testCalculatesOrdinaryEmployerContributionFromAggregateBase(): void
    {
        $result = $this->calculator()->calculate(
            '2026-08-03',
            new MonthlyEmployerSocialInsuranceInput([
                new MonthlyEmployerSocialInsuranceEmployeeInput(4_790_000, 0, true),
            ]),
        );

        self::assertSame(1_188_000, $result->contributionBeforeDiscountMinorUnits);
        self::assertSame(1_188_000, $result->contributionMinorUnits);
    }

    public function testRoundsPartTimeDiscountOnceFromAggregateEligibleBase(): void
    {
        $result = $this->calculator()->calculate(
            '2026-08-03',
            new MonthlyEmployerSocialInsuranceInput([
                new MonthlyEmployerSocialInsuranceEmployeeInput(100_100, 0, true, true),
                new MonthlyEmployerSocialInsuranceEmployeeInput(100_100, 0, true, true),
            ]),
        );

        self::assertSame(49_700, $result->contributionBeforeDiscountMinorUnits);
        self::assertSame(10_100, $result->partTimeDiscountMinorUnits);
        self::assertSame(39_600, $result->contributionMinorUnits);
        self::assertSame(200_200, $result->partTimeDiscountAssessmentBaseMinorUnits);
        self::assertNotNull($result->discountStep);
    }

    public function testCapsEveryEmployeeBeforeAggregatingEmployerContribution(): void
    {
        $result = $this->calculator()->calculate(
            '2026-08-03',
            new MonthlyEmployerSocialInsuranceInput([
                new MonthlyEmployerSocialInsuranceEmployeeInput(
                    5_000_000,
                    234_941_600,
                    true,
                ),
                new MonthlyEmployerSocialInsuranceEmployeeInput(200_000, 0, true),
                new MonthlyEmployerSocialInsuranceEmployeeInput(300_000, 0, false),
            ]),
        );

        self::assertSame(5_500_000, $result->inputAssessmentBaseMinorUnits);
        self::assertSame(300_000, $result->cappedAssessmentBaseMinorUnits);
        self::assertSame(74_400, $result->contributionMinorUnits);
    }

    private function calculator(): MonthlyEmployerSocialInsuranceCalculator
    {
        return new MonthlyEmployerSocialInsuranceCalculator(
            ActivePayrollRulesetFixture::provider(PayrollRulesetDomain::SocialInsurance),
        );
    }
}
