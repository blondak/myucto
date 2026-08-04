<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\SocialInsurance;

use LogicException;
use MyInvoice\Service\Payroll\Calculation\CalculationStep;
use MyInvoice\Service\Payroll\Calculation\DecimalRate;
use MyInvoice\Service\Payroll\Calculation\MonthlyEmployeeSocialInsuranceCalculator;
use MyInvoice\Service\Payroll\Calculation\MonthlyEmployeeSocialInsuranceInput;
use MyInvoice\Service\Payroll\Calculation\MonthlyEmployerSocialInsuranceCalculator;
use MyInvoice\Service\Payroll\Calculation\MonthlyEmployerSocialInsuranceEmployeeInput;
use MyInvoice\Service\Payroll\Calculation\MonthlyEmployerSocialInsuranceInput;
use MyInvoice\Service\Payroll\Calculation\PayrollRounding;
use MyInvoice\Service\Payroll\Calculation\RoundingMode;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetDomain;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetProvider;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetVersion;
use OverflowException;
use UnexpectedValueException;

final class SocialInsuranceMonthCalculator
{
    private readonly SocialAssessmentBaseResolver $assessmentBaseResolver;
    private readonly SocialParticipationResolver $participationResolver;
    private readonly MonthlyEmployeeSocialInsuranceCalculator $employeeCalculator;
    private readonly MonthlyEmployerSocialInsuranceCalculator $employerCalculator;

    public function __construct(
        private readonly PayrollRulesetProvider $rulesets,
        ?SocialAssessmentBaseResolver $assessmentBaseResolver = null,
        ?SocialParticipationResolver $participationResolver = null,
    ) {
        $this->assessmentBaseResolver = $assessmentBaseResolver ??
            new SocialAssessmentBaseResolver();
        $this->participationResolver = $participationResolver ??
            new SocialParticipationResolver();
        $this->employeeCalculator = new MonthlyEmployeeSocialInsuranceCalculator($rulesets);
        $this->employerCalculator = new MonthlyEmployerSocialInsuranceCalculator($rulesets);
    }

    public function calculate(SocialInsuranceMonthInput $input): SocialInsuranceMonthResult
    {
        $ruleset = $this->rulesets->forCalculation(
            PayrollRulesetDomain::SocialInsurance,
            $input->calculationDate,
        );
        $smallScaleThreshold = $this->moneyParameter(
            $ruleset,
            'participation.small_scale.minimum',
        );
        $dppThreshold = $this->moneyParameter(
            $ruleset,
            'participation.dpp.minimum',
        );

        $peopleInputs = $input->people;
        usort(
            $peopleInputs,
            static fn (SocialPersonMonthInput $left, SocialPersonMonthInput $right): int =>
                $left->personId <=> $right->personId,
        );

        $people = [];
        $issues = [];
        foreach ($peopleInputs as $personInput) {
            $person = $this->calculatePerson(
                $input->calculationDate,
                $personInput,
                $smallScaleThreshold,
                $dppThreshold,
            );
            $people[] = $person;
            foreach ($person->issues as $issue) {
                $issues[] = "person:{$person->personId}:{$issue}";
            }
        }
        sort($issues, SORT_STRING);

        if ($issues !== []) {
            return new SocialInsuranceMonthResult(
                $input->calculationDate,
                SocialCalculationStatus::ManualReview,
                null,
                null,
                null,
                null,
                null,
                null,
                null,
                null,
                null,
                $people,
                $issues,
                $ruleset->id,
                $ruleset->canonicalHash,
            );
        }

        $participatingBase = 0;
        $cappedBase = 0;
        $employeeContribution = 0;
        $partTimeDiscountBase = 0;
        $employerInputs = [];
        foreach ($people as $person) {
            $participatingBase = $this->add(
                $participatingBase,
                $person->participatingAssessmentBaseMinorUnits,
            );
            $cappedBase = $this->add($cappedBase, $person->cappedAssessmentBaseMinorUnits);
            $employeeContribution = $this->add(
                $employeeContribution,
                $person->employeeContributionMinorUnits ?? 0,
            );

            $participates = false;
            foreach ($person->relationships as $relationship) {
                $participates = $participates
                    || $relationship->participation->status ===
                        SocialParticipationStatus::Participates;
                if (
                    $relationship->partTimeEmployerDiscount ===
                    SocialDiscountEvidence::Verified
                ) {
                    $partTimeDiscountBase = $this->add(
                        $partTimeDiscountBase,
                        $relationship->cappedAssessmentBaseMinorUnits,
                    );
                }
            }

            $employerInputs[] = new MonthlyEmployerSocialInsuranceEmployeeInput(
                $person->participatingAssessmentBaseMinorUnits,
                $person->yearToDateAssessmentBaseBeforeMonthMinorUnits,
                $participates,
                false,
            );
        }

        $employer = $this->employerCalculator->calculate(
            $input->calculationDate,
            new MonthlyEmployerSocialInsuranceInput($employerInputs),
        );
        if ($employer->cappedAssessmentBaseMinorUnits !== $cappedBase) {
            throw new LogicException(
                'Employee and employer annual-maximum allocations must have the same total.',
            );
        }

        $discountStep = null;
        $discount = 0;
        if ($partTimeDiscountBase > 0) {
            $discountStep = CalculationStep::calculate(
                'monthly-employer-part-time-discount-verified-relationships',
                $partTimeDiscountBase,
                $this->rateParameter($ruleset, 'employer.discount.part_time'),
                RoundingMode::Ceil,
            );
            $discount = min(
                $employer->contributionBeforeDiscountMinorUnits,
                PayrollRounding::ceilToCzk($discountStep->outputMinorUnits),
            );
        }

        return new SocialInsuranceMonthResult(
            $input->calculationDate,
            SocialCalculationStatus::Calculated,
            $participatingBase,
            $cappedBase,
            $employeeContribution,
            $employer->contributionBeforeDiscountMinorUnits,
            $partTimeDiscountBase,
            $discount,
            $employer->contributionBeforeDiscountMinorUnits - $discount,
            $employer->contributionStep,
            $discountStep,
            $people,
            [],
            $ruleset->id,
            $ruleset->canonicalHash,
        );
    }

    private function calculatePerson(
        string $calculationDate,
        SocialPersonMonthInput $input,
        int $smallScaleThreshold,
        int $dppThreshold,
    ): SocialPersonMonthResult {
        $relationships = $input->relationships;
        usort(
            $relationships,
            static fn (
                SocialInsuranceRelationshipInput $left,
                SocialInsuranceRelationshipInput $right,
            ): int => $left->relationshipId <=> $right->relationshipId,
        );
        $facts = array_map(
            fn (SocialInsuranceRelationshipInput $relationship): SocialRelationshipFacts =>
                $this->assessmentBaseResolver->resolve($relationship),
            $relationships,
        );

        if ($input->jurisdiction !== SocialJurisdictionEvidence::CzechRegimeVerified) {
            return $this->nonCzechPersonResult($input, $facts);
        }

        $decisions = $this->participationResolver->resolve(
            $facts,
            $smallScaleThreshold,
            $dppThreshold,
        );
        $issues = [];
        $verifiedPartTimeClaims = 0;
        foreach ($facts as $fact) {
            $relationship = $fact->relationship;
            $decision = $decisions[$relationship->relationshipId];
            if ($decision->status === SocialParticipationStatus::ManualReview) {
                foreach ($decision->reasonCodes as $reason) {
                    $issues[] = "relationship:{$relationship->relationshipId}:{$reason}";
                }
            }
            if ($relationship->partTimeEmployerDiscount === SocialDiscountEvidence::Unverified) {
                $issues[] =
                    "relationship:{$relationship->relationshipId}:part_time_discount_unverified";
            }
            if ($relationship->partTimeEmployerDiscount === SocialDiscountEvidence::Verified) {
                $verifiedPartTimeClaims++;
                if ($relationship->kind !== SocialEmploymentKind::Employment) {
                    $issues[] =
                        "relationship:{$relationship->relationshipId}:part_time_discount_relationship_kind_unsupported";
                }
            }
            if ($relationship->employerRateCategory !== SocialEmployerRateCategory::Ordinary) {
                $issues[] =
                    "relationship:{$relationship->relationshipId}:employer_rate_category_requires_manual_review";
            }
            if ($relationship->agricultureDppEmployeeDiscountRequested) {
                $issues[] =
                    "relationship:{$relationship->relationshipId}:agriculture_dpp_discount_requires_manual_review";
            }
        }
        if ($verifiedPartTimeClaims > 1) {
            $issues[] = 'part_time_discount_may_select_only_one_relationship_per_person';
        }
        if ($input->workingPensionerDiscount === SocialDiscountEvidence::Unverified) {
            $issues[] = 'working_pensioner_discount_unverified';
        }
        $issues = array_values(array_unique($issues));
        sort($issues, SORT_STRING);

        $participatingBase = 0;
        $participates = false;
        foreach ($facts as $fact) {
            $decision = $decisions[$fact->relationship->relationshipId];
            if ($decision->status !== SocialParticipationStatus::Participates) {
                continue;
            }
            $participates = true;
            $participatingBase = $this->add(
                $participatingBase,
                $fact->assessmentBaseMinorUnits,
            );
        }

        if ($issues !== []) {
            return new SocialPersonMonthResult(
                $input->personId,
                SocialCalculationStatus::ManualReview,
                $input->jurisdiction,
                $input->jurisdictionEvidenceReference,
                $input->workingPensionerDiscountEvidenceReference,
                $input->yearToDateAssessmentBaseBeforeMonthMinorUnits,
                $participatingBase,
                0,
                null,
                null,
                null,
                null,
                null,
                $this->relationshipResults($facts, $decisions, []),
                $issues,
            );
        }

        $employee = $this->employeeCalculator->calculate(
            $calculationDate,
            new MonthlyEmployeeSocialInsuranceInput(
                $participatingBase,
                $input->yearToDateAssessmentBaseBeforeMonthMinorUnits,
                $participates,
                $participates
                    && $input->workingPensionerDiscount === SocialDiscountEvidence::Verified,
            ),
        );
        if (
            $employee->cappedAssessmentBaseMinorUnits < $participatingBase
            && $this->participatingRelationshipCount($decisions) > 1
            && !$this->hasUnambiguousMaximumAllocationOrder($facts, $decisions)
        ) {
            $issues = ['annual_maximum_relationship_allocation_order_required'];

            return new SocialPersonMonthResult(
                $input->personId,
                SocialCalculationStatus::ManualReview,
                $input->jurisdiction,
                $input->jurisdictionEvidenceReference,
                $input->workingPensionerDiscountEvidenceReference,
                $input->yearToDateAssessmentBaseBeforeMonthMinorUnits,
                $participatingBase,
                0,
                null,
                null,
                null,
                null,
                null,
                $this->relationshipResults($facts, $decisions, []),
                $issues,
            );
        }
        $allocations = $this->allocateCappedBase(
            $facts,
            $decisions,
            $employee->cappedAssessmentBaseMinorUnits,
        );

        return new SocialPersonMonthResult(
            $input->personId,
            SocialCalculationStatus::Calculated,
            $input->jurisdiction,
            $input->jurisdictionEvidenceReference,
            $input->workingPensionerDiscountEvidenceReference,
            $input->yearToDateAssessmentBaseBeforeMonthMinorUnits,
            $participatingBase,
            $employee->cappedAssessmentBaseMinorUnits,
            $employee->employeeContributionBeforeDiscountMinorUnits,
            $employee->workingPensionerDiscountMinorUnits,
            $employee->employeeContributionMinorUnits,
            $employee->contributionStep,
            $employee->discountStep,
            $this->relationshipResults($facts, $decisions, $allocations),
            [],
        );
    }

    /**
     * @param list<SocialRelationshipFacts> $facts
     */
    private function nonCzechPersonResult(
        SocialPersonMonthInput $input,
        array $facts,
    ): SocialPersonMonthResult {
        $manual = $input->jurisdiction === SocialJurisdictionEvidence::Unverified;
        $reason = $manual
            ? 'social_security_jurisdiction_unverified'
            : 'foreign_social_security_regime_verified';
        $decisions = [];
        foreach ($facts as $fact) {
            $decisions[$fact->relationship->relationshipId] =
                new SocialParticipationDecision(
                    $fact->relationship->relationshipId,
                    $manual
                        ? SocialParticipationStatus::ManualReview
                        : SocialParticipationStatus::DoesNotParticipate,
                    $fact->participationIncomeMinorUnits,
                    $fact->participationIncomeMinorUnits,
                    null,
                    [$reason],
                );
        }

        return new SocialPersonMonthResult(
            $input->personId,
            $manual
                ? SocialCalculationStatus::ManualReview
                : SocialCalculationStatus::Calculated,
            $input->jurisdiction,
            $input->jurisdictionEvidenceReference,
            $input->workingPensionerDiscountEvidenceReference,
            $input->yearToDateAssessmentBaseBeforeMonthMinorUnits,
            0,
            0,
            $manual ? null : 0,
            $manual ? null : 0,
            $manual ? null : 0,
            null,
            null,
            $this->relationshipResults($facts, $decisions, []),
            $manual ? [$reason] : [],
        );
    }

    /**
     * @param list<SocialRelationshipFacts> $facts
     * @param array<string, SocialParticipationDecision> $decisions
     * @return array<string,int>
     */
    private function allocateCappedBase(
        array $facts,
        array $decisions,
        int $cappedBase,
    ): array {
        $remaining = $cappedBase;
        $allocations = [];
        $allocationFacts = $facts;
        usort(
            $allocationFacts,
            static function (
                SocialRelationshipFacts $left,
                SocialRelationshipFacts $right,
            ): int {
                $leftOrder = $left->relationship->annualMaximumAllocationOrder ?? PHP_INT_MAX;
                $rightOrder = $right->relationship->annualMaximumAllocationOrder ?? PHP_INT_MAX;

                return ($leftOrder <=> $rightOrder)
                    ?: ($left->relationship->relationshipId <=>
                        $right->relationship->relationshipId);
            },
        );
        foreach ($allocationFacts as $fact) {
            $relationshipId = $fact->relationship->relationshipId;
            $allocation = 0;
            if (
                $decisions[$relationshipId]->status === SocialParticipationStatus::Participates
                && $remaining > 0
            ) {
                $allocation = min($fact->assessmentBaseMinorUnits, $remaining);
                $remaining -= $allocation;
            }
            $allocations[$relationshipId] = $allocation;
        }
        foreach ($facts as $fact) {
            $allocations[$fact->relationship->relationshipId] ??= 0;
        }
        if ($remaining !== 0) {
            throw new LogicException('Annual maximum allocation did not consume the capped base.');
        }

        return $allocations;
    }

    /**
     * @param list<SocialRelationshipFacts> $facts
     * @param array<string, SocialParticipationDecision> $decisions
     * @param array<string,int> $allocations
     * @return list<SocialRelationshipResult>
     */
    private function relationshipResults(
        array $facts,
        array $decisions,
        array $allocations,
    ): array {
        return array_map(
            static function (SocialRelationshipFacts $fact) use (
                $decisions,
                $allocations,
            ): SocialRelationshipResult {
                $relationship = $fact->relationship;

                return new SocialRelationshipResult(
                    $relationship->relationshipId,
                    $relationship->kind,
                    $decisions[$relationship->relationshipId],
                    $fact->assessmentBaseMinorUnits,
                    $allocations[$relationship->relationshipId] ?? 0,
                    $fact->includedParticipationComponents,
                    $fact->excludedParticipationComponents,
                    $fact->includedAssessmentBaseComponents,
                    $fact->excludedAssessmentBaseComponents,
                    $relationship->partTimeEmployerDiscount,
                    $relationship->employerRateCategory,
                    $relationship->annualMaximumAllocationOrder,
                    $relationship->partTimeEmployerDiscountEvidenceReference,
                );
            },
            $facts,
        );
    }

    /** @param array<string, SocialParticipationDecision> $decisions */
    private function participatingRelationshipCount(array $decisions): int
    {
        return count(array_filter(
            $decisions,
            static fn (SocialParticipationDecision $decision): bool =>
                $decision->status === SocialParticipationStatus::Participates,
        ));
    }

    /**
     * @param list<SocialRelationshipFacts> $facts
     * @param array<string, SocialParticipationDecision> $decisions
     */
    private function hasUnambiguousMaximumAllocationOrder(
        array $facts,
        array $decisions,
    ): bool {
        $orders = [];
        foreach ($facts as $fact) {
            if (
                $decisions[$fact->relationship->relationshipId]->status !==
                SocialParticipationStatus::Participates
            ) {
                continue;
            }
            $order = $fact->relationship->annualMaximumAllocationOrder;
            if ($order === null || in_array($order, $orders, true)) {
                return false;
            }
            $orders[] = $order;
        }

        return true;
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
        if ($left < 0 || $right < 0) {
            throw new LogicException('Social insurance final aggregation expects non-negative values.');
        }
        if ($left > PHP_INT_MAX - $right) {
            throw new OverflowException('Social insurance aggregation exceeds the integer range.');
        }

        return $left + $right;
    }
}
