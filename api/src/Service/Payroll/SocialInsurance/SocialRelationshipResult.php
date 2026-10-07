<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\SocialInsurance;

use JsonSerializable;
use MyInvoice\Service\Payroll\Calculation\CalculationStep;

final readonly class SocialRelationshipResult implements JsonSerializable
{
    /**
     * @param list<string> $includedParticipationComponents
     * @param list<string> $excludedParticipationComponents
     * @param list<string> $includedAssessmentBaseComponents
     * @param list<string> $excludedAssessmentBaseComponents
     */
    public function __construct(
        public string $relationshipId,
        public SocialEmploymentKind $kind,
        public SocialParticipationDecision $participation,
        public int $assessmentBaseMinorUnits,
        public int $cappedAssessmentBaseMinorUnits,
        public array $includedParticipationComponents,
        public array $excludedParticipationComponents,
        public array $includedAssessmentBaseComponents,
        public array $excludedAssessmentBaseComponents,
        public SocialDiscountEvidence $partTimeEmployerDiscount,
        public SocialEmployerRateCategory $employerRateCategory,
        public ?int $annualMaximumAllocationOrder,
        public ?string $partTimeEmployerDiscountEvidenceReference,
        public ?string $employerRateCategoryEvidenceReference = null,
        public ?SocialPartTimeDiscountReason $partTimeEmployerDiscountReason = null,
        public ?SocialPartTimeDiscountOutcome $partTimeEmployerDiscountOutcome = null,
        public ?int $agreedWeeklyWorkingMillihours = null,
        public ?int $employeeContributionBeforeDiscountMinorUnits = null,
        public ?int $workingPensionerDiscountMinorUnits = null,
        public ?CalculationStep $employeeContributionStep = null,
        public ?CalculationStep $employeeDiscountStep = null,
        public ?int $partTimeDiscountWeeklyWorkingMillihoursTotal = null,
    ) {}

    /** @return array<string,mixed> */
    public function jsonSerialize(): array
    {
        return [
            'relationship_id' => $this->relationshipId,
            'kind' => $this->kind->value,
            'participation' => $this->participation->jsonSerialize(),
            'assessment_base_minor_units' => $this->assessmentBaseMinorUnits,
            'capped_assessment_base_minor_units' => $this->cappedAssessmentBaseMinorUnits,
            'included_participation_components' => $this->includedParticipationComponents,
            'excluded_participation_components' => $this->excludedParticipationComponents,
            'included_assessment_base_components' => $this->includedAssessmentBaseComponents,
            'excluded_assessment_base_components' => $this->excludedAssessmentBaseComponents,
            'part_time_employer_discount' => $this->partTimeEmployerDiscount->value,
            'employer_rate_category' => $this->employerRateCategory->value,
            'annual_maximum_allocation_order' => $this->annualMaximumAllocationOrder,
            'part_time_employer_discount_evidence_reference' =>
                $this->partTimeEmployerDiscountEvidenceReference,
            'employer_rate_category_evidence_reference' =>
                $this->employerRateCategoryEvidenceReference,
            /*
             * Důvod podle § 7a odst. 1 a výsledek posouzení § 7a odst. 3 jsou
             * dvě různé věci: „nárok doložen" a „sleva náleží". Uplatní se jen
             * `applied`; ostatní hodnoty popisují, KTERÁ mez slevu vyloučila,
             * aby tichá nula nevypadala jako chyba výpočtu.
             */
            'part_time_employer_discount_reason' =>
                $this->partTimeEmployerDiscountReason?->value,
            'part_time_employer_discount_outcome' =>
                $this->partTimeEmployerDiscountOutcome?->value,
            /*
             * Sjednaná týdenní pracovní doba je vstup posouzení § 7a odst. 2,
             * ale zároveň JEDINÝ zdroj položky 10373 měsíčního hlášení. Bez ní
             * ve výsledku by se rozsah kratší doby musel při podání dopočítat
             * z jiného pramene, a to je přesně ten odhad, který kontrola 45
             * ČSSZ odhalí až na protokolu.
             */
            'agreed_weekly_working_millihours' => $this->agreedWeeklyWorkingMillihours,
            /*
             * Rozsah kratší pracovní doby (10373) je týdenní doba ze VŠECH
             * pracovních poměrů osoby u zaměstnavatele dohromady, včetně těch,
             * ze kterých se sleva neuplatňuje (datový slovník JMHZ 1.4.1.6,
             * Pokyny k vyplnění MH kap. 3.6.9). Je to týž úhrn, nad kterým
             * posouzení § 7a odst. 2 rozhodlo, a nese ho jen vztah, ze kterého
             * se sleva uplatňuje.
             */
            ...($this->partTimeDiscountWeeklyWorkingMillihoursTotal === null
                ? []
                : ['part_time_discount_weekly_working_millihours_total' =>
                    $this->partTimeDiscountWeeklyWorkingMillihoursTotal]),
            /*
             * Pojistné zaměstnance a sleva pracujícího důchodce TOHOTO vztahu.
             * Měsíční hlášení je vykazuje po formulářích (10370, 10491) a
             * kontrola 118 ČSSZ chce na každém formuláři 7,1 % z jeho 10477
             * zaokrouhleno nahoru, takže se zaokrouhlují po vztazích a pojistné
             * osoby je jejich součet. `null` = výsledek osoby není vypočtený.
             */
            'employee_contribution_before_discount_minor_units' =>
                $this->employeeContributionBeforeDiscountMinorUnits,
            'working_pensioner_discount_minor_units' => $this->workingPensionerDiscountMinorUnits,
            'employee_contribution_minor_units' =>
                $this->employeeContributionBeforeDiscountMinorUnits === null
                    ? null
                    : $this->employeeContributionBeforeDiscountMinorUnits
                        - ($this->workingPensionerDiscountMinorUnits ?? 0),
            'employee_contribution_step' => $this->employeeContributionStep?->jsonSerialize(),
            'employee_discount_step' => $this->employeeDiscountStep?->jsonSerialize(),
        ];
    }
}
