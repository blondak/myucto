<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Calculation;

use MyInvoice\Service\Payroll\Calculation\MonthlyAdvanceTaxCalculator;
use MyInvoice\Service\Payroll\Calculation\MonthlyAdvanceTaxInput;
use MyInvoice\Service\Payroll\Ruleset\CzechPayrollRulesets2026;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetException;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetLifecycle;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetProvider;
use MyInvoice\Service\Payroll\Ruleset\RulesetApproval;
use PHPUnit\Framework\TestCase;

final class MonthlyAdvanceTaxCalculatorTest extends TestCase
{
    public function testMatchesOfficial2026LowRateExample(): void
    {
        $result = $this->calculator()->calculate(
            '2026-08-03',
            new MonthlyAdvanceTaxInput(
                taxableIncomeMinorUnits: 4_581_000,
                signedDeclaration: false,
                claimTaxpayerCredit: false,
            ),
        );

        self::assertSame(4_590_000, $result->roundedTaxBaseMinorUnits);
        self::assertSame(4_590_000, $result->lowRateBaseMinorUnits);
        self::assertSame(0, $result->highRateBaseMinorUnits);
        self::assertSame(688_500, $result->taxBeforeCreditsMinorUnits);
        self::assertSame(688_500, $result->taxAfterCreditsMinorUnits);
    }

    public function testMatchesOfficial2026HighRateExample(): void
    {
        $result = $this->calculator()->calculate(
            '2026-08-03',
            new MonthlyAdvanceTaxInput(
                taxableIncomeMinorUnits: 16_561_500,
                signedDeclaration: false,
                claimTaxpayerCredit: false,
            ),
        );

        self::assertSame(16_570_000, $result->roundedTaxBaseMinorUnits);
        self::assertSame(14_690_100, $result->lowRateBaseMinorUnits);
        self::assertSame(1_879_900, $result->highRateBaseMinorUnits);
        self::assertSame(2_635_900, $result->taxBeforeCreditsMinorUnits);
    }

    public function testAppliesCreditsInOrderAndDerivesEligibleChildBonus(): void
    {
        $result = $this->calculator()->calculate(
            '2026-08-03',
            new MonthlyAdvanceTaxInput(
                taxableIncomeMinorUnits: 4_790_000,
                signedDeclaration: true,
                claimTaxpayerCredit: true,
                childCreditMinorUnits: 511_500,
            ),
        );

        self::assertSame(718_500, $result->taxBeforeCreditsMinorUnits);
        self::assertSame(257_000, $result->nonRefundableCreditsMinorUnits);
        self::assertSame(0, $result->taxAfterCreditsMinorUnits);
        self::assertTrue($result->taxBonusEligible);
        self::assertSame(50_000, $result->taxBonusMinorUnits);
    }

    public function testDoesNotPayBonusBelowMinimumIncomeOrMinimumBonusAmount(): void
    {
        $belowIncome = $this->calculator()->calculate(
            '2026-08-03',
            new MonthlyAdvanceTaxInput(
                taxableIncomeMinorUnits: 1_100_000,
                signedDeclaration: true,
                claimTaxpayerCredit: true,
                childCreditMinorUnits: 300_000,
            ),
        );
        self::assertFalse($belowIncome->taxBonusEligible);
        self::assertSame(0, $belowIncome->taxBonusMinorUnits);

        $belowBonus = $this->calculator()->calculate(
            '2026-08-03',
            new MonthlyAdvanceTaxInput(
                taxableIncomeMinorUnits: 4_790_000,
                signedDeclaration: true,
                claimTaxpayerCredit: true,
                childCreditMinorUnits: 465_000,
            ),
        );
        self::assertFalse($belowBonus->taxBonusEligible);
        self::assertSame(0, $belowBonus->taxBonusMinorUnits);
    }

    public function testProductionFixtureRemainsFailClosedUntilProfessionalApproval(): void
    {
        $calculator = new MonthlyAdvanceTaxCalculator(CzechPayrollRulesets2026::provider());

        $this->expectException(PayrollRulesetException::class);
        $this->expectExceptionMessage('not active');
        $calculator->calculate(
            '2026-08-03',
            new MonthlyAdvanceTaxInput(4_581_000, false, false),
        );
    }

    private function calculator(): MonthlyAdvanceTaxCalculator
    {
        $reviewed = CzechPayrollRulesets2026::provider()
            ->forDate(\MyInvoice\Service\Payroll\Ruleset\PayrollRulesetDomain::IncomeTax, '2026-08-03');
        $approval = new RulesetApproval(
            'synthetic-independent-reviewer',
            '2026-08-02',
            'synthetic-independent-approver',
            '2026-08-03',
            'Synthetic approval used only by this deterministic unit test.',
        );
        $approved = $reviewed->transition(
            PayrollRulesetLifecycle::Approved,
            'test.cz-payroll-2026.income-tax.approved',
            '2026.1.1-test',
            $approval,
        );
        $active = $approved->transition(
            PayrollRulesetLifecycle::Active,
            'test.cz-payroll-2026.income-tax.active',
            '2026.1.2-test',
            $approval,
        );

        return new MonthlyAdvanceTaxCalculator(new PayrollRulesetProvider([$active]));
    }
}
