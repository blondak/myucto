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
                [],
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
        $categoryBases = [];
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
                /*
                 * § 5a odst. 1 dělí vyměřovací základ zaměstnavatele podle
                 * kategorie ZAMĚSTNANCE, ale rozhodující je vztah: jeden
                 * člověk může mít u téhož zaměstnavatele rizikový i běžný
                 * vztah a každý spadne pod jiné písmeno. Sčítá se proto
                 * vztahový podíl základu po ročním maximu — přesně ta částka,
                 * kterou podání vykazuje pod 10478/10479/10480.
                 */
                $categoryValue = $relationship->employerRateCategory->value;
                $categoryBases[$categoryValue] = $this->add(
                    $categoryBases[$categoryValue] ?? 0,
                    $relationship->cappedAssessmentBaseMinorUnits,
                );
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
        [$employerCategories, $employerBeforeDiscount] = $this->employerCategoryResults(
            $ruleset,
            $categoryBases,
            $cappedBase,
        );
        /*
         * Souběh kategorií mění firemní částku, jedna kategorie ne. Když je
         * v měsíci jen běžná sazba, MUSÍ kategoriální výpočet vyjít na haléř
         * stejně jako souhrnný kalkulátor zaměstnavatele — jinak se dvě cesty
         * k témuž číslu rozešly a nikdo by si toho nevšiml.
         */
        $onlyOrdinary = array_filter(
            $employerCategories,
            static fn (SocialEmployerCategoryResult $category): bool =>
                $category->category !== SocialEmployerRateCategory::Ordinary,
        ) === [];
        if ($onlyOrdinary && $employerBeforeDiscount !== $employer->contributionBeforeDiscountMinorUnits) {
            throw new LogicException(
                'Ordinary-only employer contribution must match the aggregate calculator.',
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
            /*
             * § 7c odst. 1: slevu zaměstnavatel odečte „z částky pojistného
             * stanoveného podle § 5a a § 7 odst. 1 písm. a) až c)" — tedy
             * z celkového pojistného za všechny kategorie, ne uvnitř jedné.
             */
            $discount = min(
                $employerBeforeDiscount,
                PayrollRounding::ceilToCzk($discountStep->outputMinorUnits),
            );
        }

        return new SocialInsuranceMonthResult(
            $input->calculationDate,
            SocialCalculationStatus::Calculated,
            $participatingBase,
            $cappedBase,
            $employeeContribution,
            $employerBeforeDiscount,
            $partTimeDiscountBase,
            $discount,
            $employerBeforeDiscount - $discount,
            count($employerCategories) === 1 ? $employerCategories[0]->contributionStep : null,
            $discountStep,
            $employerCategories,
            $people,
            [],
            $ruleset->id,
            $ruleset->canonicalHash,
        );
    }

    /**
     * Vyměřovací základy a pojistné zaměstnavatele po kategoriích § 5a odst. 1.
     *
     * Zaokrouhluje se PO KATEGORII, ne až ze součtu: § 7 odst. 1 dává každému
     * ze tří základů vlastní sazbu a § 7 odst. 3 pak zaokrouhluje pojistné
     * nahoru na celé koruny. Sečíst pojistné a zaokrouhlit jednou by u firmy
     * se dvěma kategoriemi dalo jinou částku, než jakou ČSSZ počítá
     * v kontrolách 8, 10 a 167 nad JMHZ (10024 ze 10023, 10026 ze 10025,
     * 10484 ze 10483, a teprve 10027 je jejich součet).
     *
     * Dílčí základ se tu ZÁMĚRNĚ nezaokrouhluje. § 5d sice zaokrouhluje nahoru
     * vyměřovací základy podle § 5 až 5c včetně § 5a, jenže modul ho neuplatňuje
     * ani o řád výš — základ zaměstnance podle § 5 taky zůstává v haléřích.
     * Zaokrouhlit ho jen tady by rozdělení kategorií tiše změnilo i firmy, které
     * mají jedinou kategorii, a rozešlo by ho se souhrnným kalkulátorem.
     * § 5d patří na obě místa najednou, ne na jedno.
     *
     * @param array<string,int> $categoryBases
     * @return array{0:list<SocialEmployerCategoryResult>,1:int}
     */
    private function employerCategoryResults(
        PayrollRulesetVersion $ruleset,
        array $categoryBases,
        int $cappedBase,
    ): array {
        if (array_sum($categoryBases) !== $cappedBase) {
            throw new LogicException(
                'Employer rate categories must partition the capped assessment base.',
            );
        }
        $statutory = array_map(
            static fn (SocialEmployerRateCategory $category): string => $category->value,
            SocialEmployerRateCategory::statutoryOrder(),
        );
        if (array_diff(array_keys($categoryBases), $statutory) !== []) {
            throw new LogicException(
                'A calculated month cannot carry an unverified employer rate category.',
            );
        }
        $categories = [];
        $total = 0;
        foreach (SocialEmployerRateCategory::statutoryOrder() as $category) {
            if (!array_key_exists($category->value, $categoryBases)) {
                continue;
            }
            $base = $categoryBases[$category->value];
            $step = CalculationStep::calculate(
                "monthly-employer-social-insurance-{$category->value}",
                $base,
                $this->rateParameter($ruleset, $category->rateParameter()),
                RoundingMode::Ceil,
            );
            $contribution = PayrollRounding::ceilToCzk($step->outputMinorUnits);
            $total = $this->add($total, $contribution);
            $categories[] = new SocialEmployerCategoryResult(
                $category,
                $base,
                $contribution,
                $step,
            );
        }

        return [$categories, $total];
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
            /*
             * Doložená kategorie § 5a odst. 1 se počítá vlastní sazbou; ruční
             * posouzení si vynutí jen NEDOLOŽENÉ zařazení. Dřív blokovalo běh
             * každé písmeno mimo a) — jenže tím se nikdy neodvedlo správně,
             * a hlavně: zařazení, které do vstupu nikdy nedoteklo, se tvářilo
             * jako běžná sazba a odvedlo o 3 procentní body míň.
             */
            if ($relationship->employerRateCategory === SocialEmployerRateCategory::Unverified) {
                $issues[] =
                    "relationship:{$relationship->relationshipId}:employer_rate_category_unverified";
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
                    $relationship->employerRateCategoryEvidenceReference,
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
