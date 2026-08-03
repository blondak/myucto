<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Calculation;

use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetDomain;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetProvider;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetVersion;
use UnexpectedValueException;

final class MonthlyEmployeeSocialInsuranceCalculator
{
    public function __construct(private readonly PayrollRulesetProvider $rulesets) {}

    public function calculate(
        string $calculationDate,
        MonthlyEmployeeSocialInsuranceInput $input,
    ): MonthlyEmployeeSocialInsuranceResult {
        $ruleset = $this->rulesets->forCalculation(
            PayrollRulesetDomain::SocialInsurance,
            $calculationDate,
        );

        if (!$input->participates) {
            return new MonthlyEmployeeSocialInsuranceResult(
                $input->assessmentBaseMinorUnits,
                0,
                0,
                0,
                0,
                null,
                null,
                $ruleset->id,
                $ruleset->canonicalHash,
            );
        }

        $annualMaximum = $this->moneyParameter(
            $ruleset,
            'maximum_assessment_base.yearly',
        );
        $remainingAnnualBase = max(
            0,
            $annualMaximum - min($annualMaximum, $input->yearToDateAssessmentBaseBeforeMonthMinorUnits),
        );
        $cappedBase = min($input->assessmentBaseMinorUnits, $remainingAnnualBase);
        $contributionStep = CalculationStep::calculate(
            'monthly-employee-social-insurance',
            $cappedBase,
            $this->rateParameter($ruleset, 'employee.rate.ordinary'),
            RoundingMode::Ceil,
        );
        $contribution = PayrollRounding::ceilToCzk($contributionStep->outputMinorUnits);

        $discountStep = null;
        $discount = 0;
        if ($input->workingPensionerDiscount) {
            $discountStep = CalculationStep::calculate(
                'monthly-working-pensioner-social-discount',
                $cappedBase,
                $this->rateParameter($ruleset, 'employee.discount.working_pensioner'),
                RoundingMode::Ceil,
            );
            $discount = min(
                $contribution,
                PayrollRounding::ceilToCzk($discountStep->outputMinorUnits),
            );
        }

        return new MonthlyEmployeeSocialInsuranceResult(
            $input->assessmentBaseMinorUnits,
            $cappedBase,
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
}
