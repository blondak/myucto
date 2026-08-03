<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Calculation;

use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetDomain;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetProvider;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetVersion;
use OverflowException;
use UnexpectedValueException;

final class MonthlyEmployerSocialInsuranceCalculator
{
    public function __construct(private readonly PayrollRulesetProvider $rulesets) {}

    public function calculate(
        string $calculationDate,
        MonthlyEmployerSocialInsuranceInput $input,
    ): MonthlyEmployerSocialInsuranceResult {
        $ruleset = $this->rulesets->forCalculation(
            PayrollRulesetDomain::SocialInsurance,
            $calculationDate,
        );
        $annualMaximum = $this->moneyParameter($ruleset, 'maximum_assessment_base.yearly');
        $inputBase = 0;
        $cappedBase = 0;
        $partTimeDiscountBase = 0;
        foreach ($input->employees as $employee) {
            $inputBase = $this->add($inputBase, $employee->assessmentBaseMinorUnits);
            if (!$employee->participates) {
                continue;
            }
            $remainingAnnualBase = max(
                0,
                $annualMaximum - min(
                    $annualMaximum,
                    $employee->yearToDateAssessmentBaseBeforeMonthMinorUnits,
                ),
            );
            $employeeCappedBase = min($employee->assessmentBaseMinorUnits, $remainingAnnualBase);
            $cappedBase = $this->add($cappedBase, $employeeCappedBase);
            if ($employee->partTimeDiscountEligible) {
                $partTimeDiscountBase = $this->add($partTimeDiscountBase, $employeeCappedBase);
            }
        }

        $contributionStep = CalculationStep::calculate(
            'monthly-employer-social-insurance-ordinary',
            $cappedBase,
            $this->rateParameter($ruleset, 'employer.rate.ordinary'),
            RoundingMode::Ceil,
        );
        $contribution = PayrollRounding::ceilToCzk($contributionStep->outputMinorUnits);

        $discountStep = null;
        $discount = 0;
        if ($partTimeDiscountBase > 0) {
            $discountStep = CalculationStep::calculate(
                'monthly-employer-part-time-discount',
                $partTimeDiscountBase,
                $this->rateParameter($ruleset, 'employer.discount.part_time'),
                RoundingMode::Ceil,
            );
            $discount = PayrollRounding::ceilToCzk($discountStep->outputMinorUnits);
        }
        $discount = min($discount, $contribution);

        return new MonthlyEmployerSocialInsuranceResult(
            $inputBase,
            $cappedBase,
            $partTimeDiscountBase,
            $contribution,
            $discount,
            $contribution - $discount,
            $contributionStep,
            $discountStep,
            $ruleset->id,
            $ruleset->canonicalHash,
        );
    }

    private function moneyParameter(PayrollRulesetVersion $ruleset, string $key): int
    {
        $parameter = $ruleset->parameter($key);
        if ($parameter->type !== 'money_minor' || !is_int($parameter->value)) {
            throw new UnexpectedValueException("Payroll ruleset parameter {$key} is not money.");
        }

        return $parameter->value;
    }

    private function rateParameter(PayrollRulesetVersion $ruleset, string $key): DecimalRate
    {
        $parameter = $ruleset->parameter($key);
        if ($parameter->type !== 'decimal_rate' || !is_string($parameter->value)) {
            throw new UnexpectedValueException("Payroll ruleset parameter {$key} is not a rate.");
        }

        return DecimalRate::fromString($parameter->value);
    }

    private function add(int $left, int $right): int
    {
        if ($left > PHP_INT_MAX - $right) {
            throw new OverflowException('Employer social insurance bases exceed the integer range.');
        }

        return $left + $right;
    }
}
