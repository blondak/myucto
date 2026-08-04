<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Run;

use MyInvoice\Service\Payroll\HealthInsurance\HealthIncomeAttribution;
use MyInvoice\Service\Payroll\HealthInsurance\HealthInsurerSnapshotStatus;
use MyInvoice\Service\Payroll\HealthInsurance\HealthInsuranceMonthInput;
use MyInvoice\Service\Payroll\HealthInsurance\HealthInsuranceRelationshipInput;
use MyInvoice\Service\Payroll\HealthInsurance\HealthJurisdictionEvidence;
use MyInvoice\Service\Payroll\HealthInsurance\HealthMinimumReductionInterval;
use MyInvoice\Service\Payroll\HealthInsurance\HealthMinimumReductionReason;
use MyInvoice\Service\Payroll\HealthInsurance\HealthMinimumTopUpEmployerSelection;
use MyInvoice\Service\Payroll\HealthInsurance\HealthMinimumTopUpResponsibility;
use MyInvoice\Service\Payroll\HealthInsurance\HealthOtherEmployerBase;
use MyInvoice\Service\Payroll\HealthInsurance\HealthPersonMonthInput;
use MyInvoice\Service\Payroll\HealthInsurance\HealthRelationshipKindMapper;
use MyInvoice\Service\Payroll\IncomeTax\AnnualTaxAccumulatorInput;
use MyInvoice\Service\Payroll\IncomeTax\EmploymentRelationshipKindMapper;
use MyInvoice\Service\Payroll\IncomeTax\EmploymentRelationshipTaxInput;
use MyInvoice\Service\Payroll\IncomeTax\MonthlyEmploymentIncomeTaxInput;
use MyInvoice\Service\Payroll\IncomeTax\OtherWithholdingEligibility;
use MyInvoice\Service\Payroll\IncomeTax\TaxChildClaim;
use MyInvoice\Service\Payroll\IncomeTax\TaxCreditClaim;
use MyInvoice\Service\Payroll\IncomeTax\TaxCreditKind;
use MyInvoice\Service\Payroll\IncomeTax\TaxDeclarationEvidence;
use MyInvoice\Service\Payroll\IncomeTax\TaxDeclarationStatus;
use MyInvoice\Service\Payroll\IncomeTax\TaxEvidenceStatus;
use MyInvoice\Service\Payroll\IncomeTax\TaxResidence;
use MyInvoice\Service\Payroll\IncomeTax\TaxResidenceEvidence;
use MyInvoice\Service\Payroll\SocialInsurance\SocialDiscountEvidence;
use MyInvoice\Service\Payroll\SocialInsurance\SocialIncomeAttribution;
use MyInvoice\Service\Payroll\SocialInsurance\SocialInsuranceMonthInput;
use MyInvoice\Service\Payroll\SocialInsurance\SocialInsuranceRelationshipInput;
use MyInvoice\Service\Payroll\SocialInsurance\SocialJurisdictionEvidence;
use MyInvoice\Service\Payroll\SocialInsurance\SocialPersonMonthInput;
use MyInvoice\Service\Payroll\SocialInsurance\SocialRelationshipKindMapper;

final class PayrollRunStatutoryInputAssembler
{
    /** @var list<PayrollRunStatutoryInputIssue> */
    private array $issues = [];

    private readonly PayrollRunStatutoryComponentMapper $components;
    private readonly SocialRelationshipKindMapper $socialKinds;
    private readonly HealthRelationshipKindMapper $healthKinds;
    private readonly EmploymentRelationshipKindMapper $taxKinds;

    public function __construct(
        ?PayrollRunStatutoryComponentMapper $components = null,
        ?SocialRelationshipKindMapper $socialKinds = null,
        ?HealthRelationshipKindMapper $healthKinds = null,
        ?EmploymentRelationshipKindMapper $taxKinds = null,
    ) {
        $this->components = $components ?? new PayrollRunStatutoryComponentMapper();
        $this->socialKinds = $socialKinds ?? new SocialRelationshipKindMapper();
        $this->healthKinds = $healthKinds ?? new HealthRelationshipKindMapper();
        $this->taxKinds = $taxKinds ?? new EmploymentRelationshipKindMapper();
    }

    /** @param array<string,mixed> $snapshot */
    public function assemble(array $snapshot): PayrollRunStatutoryInputBundle
    {
        $this->issues = [];
        if (($snapshot['schema_version'] ?? null) !== 'payroll-run-input.v2') {
            return $this->invalidSnapshot('unsupported_snapshot_schema');
        }
        $supplierId = $this->positiveInt($snapshot['supplier_id'] ?? null);
        $periodStart = $this->date($snapshot['period_start'] ?? null);
        $periodEnd = $this->date($snapshot['period_end'] ?? null);
        $statutoryPeriod = $this->object($snapshot['statutory_period'] ?? null);
        $taxDate = $this->date($statutoryPeriod['tax_calculation_date'] ?? null);
        $socialDate = $this->date($statutoryPeriod['social_calculation_date'] ?? null);
        $healthDate = $this->date($statutoryPeriod['health_calculation_date'] ?? null);
        $people = $this->list($snapshot['people'] ?? null);
        if ($supplierId === null
            || $periodStart === null
            || $periodEnd === null
            || $taxDate === null
            || $socialDate === null
            || $healthDate === null
            || $people === null
        ) {
            return $this->invalidSnapshot('snapshot_shape_invalid');
        }

        usort(
            $people,
            static fn (mixed $left, mixed $right): int =>
                ((int) ($left['employee']['id'] ?? 0))
                <=> ((int) ($right['employee']['id'] ?? 0)),
        );
        $socialPeople = [];
        $healthPeople = [];
        $incomeTax = [];
        foreach ($people as $person) {
            if (!is_array($person) || array_is_list($person)) {
                $this->issue('snapshot', 'person_shape_invalid');
                continue;
            }
            $employee = $this->object($person['employee'] ?? null);
            $employeeId = $this->positiveInt($employee['id'] ?? null);
            if ($employeeId === null) {
                $this->issue('snapshot', 'employee_reference_invalid');
                continue;
            }
            $personReference = "employee:{$employeeId}";
            $evidence = $this->object($person['statutory_evidence'] ?? null);
            if ($evidence === null
                || ($evidence['schema_version'] ?? null)
                    !== 'payroll-person-statutory-evidence.v1'
                || ($evidence['employee_id'] ?? null) !== $employeeId
                || ($evidence['effective_on'] ?? null) !== $taxDate
            ) {
                foreach (
                    ['social_insurance', 'health_insurance', 'income_tax'] as $domain
                ) {
                    $this->issue(
                        $domain,
                        'statutory_evidence_snapshot_missing_or_mismatched',
                        $personReference,
                    );
                }
                continue;
            }
            $employments = $this->list($person['employments'] ?? null);
            if ($employments === null || $employments === []) {
                foreach (
                    ['social_insurance', 'health_insurance', 'income_tax'] as $domain
                ) {
                    $this->issue(
                        $domain,
                        'employment_relationship_missing',
                        $personReference,
                    );
                }
                continue;
            }
            usort(
                $employments,
                static fn (mixed $left, mixed $right): int =>
                    ((int) ($left['employment']['id'] ?? 0))
                    <=> ((int) ($right['employment']['id'] ?? 0)),
            );

            $before = count($this->issues);
            $social = $this->socialPerson(
                $person,
                $evidence,
                $employments,
                $supplierId,
                $employeeId,
                $personReference,
                $periodStart,
                $periodEnd,
            );
            if ($social !== null
                && !$this->hasDomainIssueSince('social_insurance', $before)
            ) {
                $socialPeople[] = $social;
            }

            $before = count($this->issues);
            $health = $this->healthPerson(
                $evidence,
                $employments,
                $personReference,
                $periodStart,
                $periodEnd,
            );
            if ($health !== null
                && !$this->hasDomainIssueSince('health_insurance', $before)
            ) {
                $healthPeople[] = $health;
            }

            $before = count($this->issues);
            $tax = $this->incomeTaxPerson(
                $person,
                $evidence,
                $employments,
                $supplierId,
                $employeeId,
                $personReference,
                $periodStart,
                $taxDate,
            );
            if ($tax !== null
                && !$this->hasDomainIssueSince('income_tax', $before)
            ) {
                $incomeTax[] = $tax;
            }
        }

        if ($people === []) {
            foreach (
                ['social_insurance', 'health_insurance', 'income_tax'] as $domain
            ) {
                $this->issue($domain, 'person_missing');
            }
        }
        $this->sortAndDeduplicateIssues();

        $socialInput = $this->hasDomainIssue('social_insurance')
            || $socialPeople === []
            ? null
            : new SocialInsuranceMonthInput($socialDate, $socialPeople);
        $healthInput = $this->hasDomainIssue('health_insurance')
            || $healthPeople === []
            ? null
            : new HealthInsuranceMonthInput($healthDate, $healthPeople);
        if ($this->hasDomainIssue('income_tax')) {
            $incomeTax = [];
        }

        return new PayrollRunStatutoryInputBundle(
            $socialInput,
            $healthInput,
            $incomeTax,
            $this->issues,
        );
    }

    /**
     * @param array<string,mixed> $person
     * @param array<string,mixed> $evidence
     * @param list<mixed> $employments
     */
    private function socialPerson(
        array $person,
        array $evidence,
        array $employments,
        int $supplierId,
        int $employeeId,
        string $personReference,
        string $periodStart,
        string $periodEnd,
    ): ?SocialPersonMonthInput {
        $socialEvidence = $this->object($evidence['social'] ?? null);
        $jurisdictionRow = $this->object($socialEvidence['jurisdiction'] ?? null);
        if ($jurisdictionRow === null) {
            $this->issue(
                'social_insurance',
                'social_jurisdiction_evidence_missing',
                $personReference,
            );
            return null;
        }
        $jurisdiction = $this->enum(
            SocialJurisdictionEvidence::class,
            $jurisdictionRow['jurisdiction'] ?? null,
        );
        if (!$jurisdiction instanceof SocialJurisdictionEvidence) {
            $this->issue(
                'social_insurance',
                'social_jurisdiction_evidence_invalid',
                $personReference,
            );
            return null;
        }
        if ($jurisdiction === SocialJurisdictionEvidence::Unverified) {
            $this->issue(
                'social_insurance',
                'social_jurisdiction_evidence_unverified',
                $personReference,
            );
        }
        if ($jurisdiction === SocialJurisdictionEvidence::ForeignRegimeVerified
            && ($jurisdictionRow['a1_status'] ?? null) !== 'verified'
        ) {
            $this->issue(
                'social_insurance',
                'social_a1_evidence_unverified',
                $personReference,
            );
        }
        if ($jurisdiction === SocialJurisdictionEvidence::CzechRegimeVerified
            && ($jurisdictionRow['a1_status'] ?? null) !== 'not_applicable'
        ) {
            $this->issue(
                'social_insurance',
                'social_a1_evidence_conflict',
                $personReference,
            );
        }

        $discountRow = $this->object(
            $socialEvidence['working_pensioner_discount'] ?? null,
        );
        if ($discountRow === null) {
            $this->issue(
                'social_insurance',
                'working_pensioner_discount_evidence_missing',
                $personReference,
            );
            return null;
        }
        $discount = $this->enum(
            SocialDiscountEvidence::class,
            $discountRow['status'] ?? null,
        );
        if (!$discount instanceof SocialDiscountEvidence) {
            $this->issue(
                'social_insurance',
                'working_pensioner_discount_evidence_invalid',
                $personReference,
            );
            return null;
        }
        if ($discount === SocialDiscountEvidence::Unverified) {
            $this->issue(
                'social_insurance',
                'working_pensioner_discount_evidence_unverified',
                $personReference,
            );
        }

        $yearToDate = $this->socialAccumulator(
            $person,
            $supplierId,
            $employeeId,
            $personReference,
            $periodStart,
        );
        $relationships = [];
        foreach ($employments as $employmentSnapshot) {
            $relationship = $this->socialRelationship(
                $employmentSnapshot,
                $personReference,
                $periodStart,
                $periodEnd,
            );
            if ($relationship !== null) {
                $relationships[] = $relationship;
            }
        }
        if ($yearToDate === null || $relationships === []) {
            return null;
        }

        try {
            return new SocialPersonMonthInput(
                $personReference,
                $jurisdiction,
                $yearToDate,
                $relationships,
                $discount,
                $jurisdiction === SocialJurisdictionEvidence::ForeignRegimeVerified
                    ? $this->nullableString(
                        $jurisdictionRow['jurisdiction_evidence_reference'] ?? null,
                    )
                    : null,
                $discount === SocialDiscountEvidence::Verified
                    ? $this->nullableString(
                        $discountRow['evidence_reference'] ?? null,
                    )
                    : null,
            );
        } catch (\InvalidArgumentException) {
            $this->issue(
                'social_insurance',
                'social_evidence_mapping_failed',
                $personReference,
            );
            return null;
        }
    }

    /** @param mixed $snapshot */
    private function socialRelationship(
        mixed $snapshot,
        string $personReference,
        string $periodStart,
        string $periodEnd,
    ): ?SocialInsuranceRelationshipInput {
        if (!is_array($snapshot) || array_is_list($snapshot)) {
            $this->issue(
                'social_insurance',
                'employment_snapshot_invalid',
                $personReference,
            );
            return null;
        }
        $employment = $this->object($snapshot['employment'] ?? null);
        if ($employment === null) {
            $this->issue(
                'social_insurance',
                'employment_snapshot_invalid',
                $personReference,
            );
            return null;
        }
        $employmentId = $this->positiveInt($employment['id'] ?? null);
        if ($employmentId === null) {
            $this->issue(
                'social_insurance',
                'employment_reference_invalid',
                $personReference,
            );
            return null;
        }
        $relationshipReference = "employment:{$employmentId}";
        if (($employment['employee_id'] ?? null)
            !== $this->personId($personReference)
        ) {
            $this->issue(
                'social_insurance',
                'employment_person_mismatch',
                $personReference,
                $relationshipReference,
            );
            return null;
        }
        $term = $this->object($snapshot['term'] ?? null);
        if ($term === null) {
            $this->issue(
                'social_insurance',
                'employment_term_missing',
                $personReference,
                $relationshipReference,
            );
            return null;
        }
        if (($term['social_insurance_participation'] ?? null) !== 'automatic') {
            $this->issue(
                'social_insurance',
                'participation_override_unsupported',
                $personReference,
                $relationshipReference,
            );
        }
        $relationType = $this->nonEmptyString($employment['relation_type'] ?? null);
        if ($relationType === null) {
            $this->issue(
                'social_insurance',
                'relationship_kind_missing',
                $personReference,
                $relationshipReference,
            );
            return null;
        }
        try {
            $mapping = $this->socialKinds->fromRelationType($relationType);
        } catch (\InvalidArgumentException) {
            $this->issue(
                'social_insurance',
                'relationship_kind_unsupported',
                $personReference,
                $relationshipReference,
            );
            return null;
        }
        $dates = $this->employmentDates($employment);
        if ($dates === null) {
            $this->issue(
                'social_insurance',
                'employment_dates_invalid',
                $personReference,
                $relationshipReference,
            );
            return null;
        }
        [$employmentFrom, $employmentTo] = $dates;
        $active = $employmentFrom <= $periodEnd
            && ($employmentTo === null || $employmentTo >= $periodStart);
        $attribution = SocialIncomeAttribution::CurrentEmploymentMonth;
        if (!$active) {
            if ($employmentTo !== null
                && substr($employmentTo, 0, 7) === substr($periodStart, 0, 7)
            ) {
                $attribution =
                    SocialIncomeAttribution::PostTerminationEndMonthVerified;
            } else {
                $this->issue(
                    'social_insurance',
                    'post_termination_income_attribution_unverified',
                    $personReference,
                    $relationshipReference,
                );
                $attribution = SocialIncomeAttribution::Unverified;
            }
        }
        $components = $this->socialComponents(
            $snapshot['inputs'] ?? null,
            $personReference,
            $relationshipReference,
            $periodStart,
        );
        if ($components === []) {
            return null;
        }

        try {
            return new SocialInsuranceRelationshipInput(
                $relationshipReference,
                $mapping->kind,
                $this->nonNegativeInt($employment['monthly_gross_minor'] ?? null),
                $active,
                $attribution,
                $components,
                participationAggregationGroup: $mapping->aggregationGroup,
            );
        } catch (\InvalidArgumentException) {
            $this->issue(
                'social_insurance',
                'relationship_mapping_failed',
                $personReference,
                $relationshipReference,
            );
            return null;
        }
    }

    /**
     * @param array<string,mixed> $evidence
     * @param list<mixed> $employments
     */
    private function healthPerson(
        array $evidence,
        array $employments,
        string $personReference,
        string $periodStart,
        string $periodEnd,
    ): ?HealthPersonMonthInput {
        $healthEvidence = $this->object($evidence['health'] ?? null);
        $coverage = $this->object($healthEvidence['coverage'] ?? null);
        if ($coverage === null) {
            $this->issue(
                'health_insurance',
                'health_coverage_evidence_missing',
                $personReference,
            );
            return null;
        }
        $jurisdiction = $this->enum(
            HealthJurisdictionEvidence::class,
            $coverage['jurisdiction'] ?? null,
        );
        $insurerStatus = $this->enum(
            HealthInsurerSnapshotStatus::class,
            $coverage['insurer_status'] ?? null,
        );
        if (!$jurisdiction instanceof HealthJurisdictionEvidence
            || !$insurerStatus instanceof HealthInsurerSnapshotStatus
        ) {
            $this->issue(
                'health_insurance',
                'health_coverage_evidence_invalid',
                $personReference,
            );
            return null;
        }
        if ($jurisdiction === HealthJurisdictionEvidence::Unverified) {
            $this->issue(
                'health_insurance',
                'health_jurisdiction_evidence_unverified',
                $personReference,
            );
        }
        if ($insurerStatus === HealthInsurerSnapshotStatus::Unverified) {
            $this->issue(
                'health_insurance',
                'health_insurer_evidence_unverified',
                $personReference,
            );
        }
        if (($jurisdiction === HealthJurisdictionEvidence::CzechRegimeVerified
                && $insurerStatus !== HealthInsurerSnapshotStatus::Verified)
            || ($jurisdiction
                    === HealthJurisdictionEvidence::ForeignRegimeVerified
                && $insurerStatus
                    !== HealthInsurerSnapshotStatus::NotApplicable)
        ) {
            $this->issue(
                'health_insurance',
                'health_coverage_evidence_conflict',
                $personReference,
            );
        }

        $monthEvidence = $this->object(
            $healthEvidence['month_evidence'] ?? null,
        );
        if ($monthEvidence === null) {
            $this->issue(
                'health_insurance',
                'health_minimum_month_evidence_missing',
                $personReference,
            );
            return null;
        }
        $responsibility = $this->enum(
            HealthMinimumTopUpResponsibility::class,
            $monthEvidence['top_up_responsibility'] ?? null,
        );
        if (!$responsibility instanceof HealthMinimumTopUpResponsibility) {
            $this->issue(
                'health_insurance',
                'health_minimum_responsibility_invalid',
                $personReference,
            );
            return null;
        }
        if ($responsibility === HealthMinimumTopUpResponsibility::Unverified) {
            $this->issue(
                'health_insurance',
                'health_minimum_responsibility_unverified',
                $personReference,
            );
        }

        $reductions = $this->healthReductions(
            $healthEvidence['minimum_reductions'] ?? null,
            $personReference,
            $periodEnd,
        );
        $otherEmployers = $this->healthOtherEmployers(
            $healthEvidence['other_employer_bases'] ?? null,
            $personReference,
        );
        $relationships = [];
        foreach ($employments as $employmentSnapshot) {
            $relationship = $this->healthRelationship(
                $employmentSnapshot,
                $personReference,
                $periodStart,
                $periodEnd,
            );
            if ($relationship !== null) {
                $relationships[] = $relationship;
            }
        }
        if ($relationships === []) {
            return null;
        }
        $selectedEmployer = $this->nullableString(
            $monthEvidence['selected_top_up_employer_reference'] ?? null,
        );
        $selection = $selectedEmployer === null
            ? HealthMinimumTopUpEmployerSelection::ThisEmployer
            : HealthMinimumTopUpEmployerSelection::OtherEmployer;

        try {
            return new HealthPersonMonthInput(
                $personReference,
                $jurisdiction,
                $jurisdiction === HealthJurisdictionEvidence::ForeignRegimeVerified
                    ? $this->nullableString(
                        $coverage['jurisdiction_evidence_reference'] ?? null,
                    )
                    : null,
                $insurerStatus,
                $this->nullableString($coverage['insurer_code'] ?? null),
                $this->nullableString(
                    $coverage['insurer_evidence_reference'] ?? null,
                ),
                $relationships,
                $reductions,
                $otherEmployers,
                $responsibility,
                $responsibility ===
                    HealthMinimumTopUpResponsibility::EmployerObstacleVerified
                    ? $this->nullableString(
                        $monthEvidence[
                            'top_up_responsibility_evidence_reference'
                        ] ?? null,
                    )
                    : null,
                $this->nullableString(
                    $monthEvidence[
                        'selected_top_up_employer_evidence_reference'
                    ] ?? null,
                ),
                $selection,
            );
        } catch (\InvalidArgumentException) {
            $this->issue(
                'health_insurance',
                'health_evidence_mapping_failed',
                $personReference,
            );
            return null;
        }
    }

    /** @param mixed $snapshot */
    private function healthRelationship(
        mixed $snapshot,
        string $personReference,
        string $periodStart,
        string $periodEnd,
    ): ?HealthInsuranceRelationshipInput {
        if (!is_array($snapshot) || array_is_list($snapshot)) {
            $this->issue(
                'health_insurance',
                'employment_snapshot_invalid',
                $personReference,
            );
            return null;
        }
        $employment = $this->object($snapshot['employment'] ?? null);
        if ($employment === null) {
            $this->issue(
                'health_insurance',
                'employment_snapshot_invalid',
                $personReference,
            );
            return null;
        }
        $employmentId = $this->positiveInt($employment['id'] ?? null);
        if ($employmentId === null) {
            $this->issue(
                'health_insurance',
                'employment_reference_invalid',
                $personReference,
            );
            return null;
        }
        $relationshipReference = "employment:{$employmentId}";
        if (($employment['employee_id'] ?? null)
            !== $this->personId($personReference)
        ) {
            $this->issue(
                'health_insurance',
                'employment_person_mismatch',
                $personReference,
                $relationshipReference,
            );
            return null;
        }
        $term = $this->object($snapshot['term'] ?? null);
        if ($term === null) {
            $this->issue(
                'health_insurance',
                'employment_term_missing',
                $personReference,
                $relationshipReference,
            );
            return null;
        }
        if (($term['health_insurance_participation'] ?? null) !== 'automatic') {
            $this->issue(
                'health_insurance',
                'participation_override_unsupported',
                $personReference,
                $relationshipReference,
            );
        }
        $relationType = $this->nonEmptyString($employment['relation_type'] ?? null);
        $dates = $this->employmentDates($employment);
        if ($relationType === null || $dates === null) {
            $this->issue(
                'health_insurance',
                'relationship_kind_or_dates_invalid',
                $personReference,
                $relationshipReference,
            );
            return null;
        }
        try {
            $kind = $this->healthKinds->fromDatabaseRelationType($relationType);
        } catch (\UnexpectedValueException) {
            $this->issue(
                'health_insurance',
                'relationship_kind_unsupported',
                $personReference,
                $relationshipReference,
            );
            return null;
        }
        [$employmentFrom, $employmentTo] = $dates;
        $active = $employmentFrom <= $periodEnd
            && ($employmentTo === null || $employmentTo >= $periodStart);
        $attribution = HealthIncomeAttribution::CurrentEmploymentMonth;
        if (!$active) {
            if (($kind->value === 'dpp' || $kind->value === 'dpc')
                && $employmentTo !== null
                && substr($employmentTo, 0, 7) === substr($periodStart, 0, 7)
            ) {
                $attribution =
                    HealthIncomeAttribution::PostTerminationEndMonthVerified;
            } elseif (($kind->value !== 'dpp' && $kind->value !== 'dpc')
                && $employmentTo !== null
                && $employmentTo < $periodStart
            ) {
                $attribution =
                    HealthIncomeAttribution::PostTerminationPaymentMonthVerified;
            } else {
                $this->issue(
                    'health_insurance',
                    'post_termination_income_attribution_unverified',
                    $personReference,
                    $relationshipReference,
                );
                $attribution = HealthIncomeAttribution::Unverified;
            }
        }
        $components = $this->healthComponents(
            $snapshot['inputs'] ?? null,
            $personReference,
            $relationshipReference,
            $periodStart,
        );
        if ($components === []) {
            return null;
        }

        try {
            return new HealthInsuranceRelationshipInput(
                $relationshipReference,
                $kind,
                $employmentFrom,
                $employmentTo,
                $attribution,
                $components,
            );
        } catch (\InvalidArgumentException) {
            $this->issue(
                'health_insurance',
                'relationship_mapping_failed',
                $personReference,
                $relationshipReference,
            );
            return null;
        }
    }

    /**
     * @param array<string,mixed> $person
     * @param array<string,mixed> $evidence
     * @param list<mixed> $employments
     */
    private function incomeTaxPerson(
        array $person,
        array $evidence,
        array $employments,
        int $supplierId,
        int $employeeId,
        string $personReference,
        string $periodStart,
        string $calculationDate,
    ): ?MonthlyEmploymentIncomeTaxInput {
        $taxEvidence = $this->object($evidence['income_tax'] ?? null);
        $declarationRow = $this->object($taxEvidence['declaration'] ?? null);
        $residenceRow = $this->object($taxEvidence['residence'] ?? null);
        if ($declarationRow === null) {
            $this->issue(
                'income_tax',
                'tax_declaration_evidence_missing',
                $personReference,
            );
        }
        if ($residenceRow === null) {
            $this->issue(
                'income_tax',
                'tax_residence_evidence_missing',
                $personReference,
            );
        }
        if ($declarationRow === null || $residenceRow === null) {
            return null;
        }
        $declarationStatus = $this->enum(
            TaxDeclarationStatus::class,
            $declarationRow['status'] ?? null,
        );
        $residence = $this->enum(
            TaxResidence::class,
            $residenceRow['residence'] ?? null,
        );
        if (!$declarationStatus instanceof TaxDeclarationStatus
            || !$residence instanceof TaxResidence
        ) {
            $this->issue(
                'income_tax',
                'tax_evidence_invalid',
                $personReference,
            );
            return null;
        }
        if ($declarationStatus === TaxDeclarationStatus::Unverified) {
            $this->issue(
                'income_tax',
                'tax_declaration_evidence_unverified',
                $personReference,
            );
        }
        if ($residence === TaxResidence::Unverified) {
            $this->issue(
                'income_tax',
                'tax_residence_evidence_unverified',
                $personReference,
            );
        }

        $annual = $this->incomeTaxAccumulator(
            $person,
            $supplierId,
            $employeeId,
            $personReference,
            $periodStart,
        );
        $relationships = [];
        foreach ($employments as $employmentSnapshot) {
            $relationship = $this->incomeTaxRelationship(
                $employmentSnapshot,
                $personReference,
                $supplierId,
                $periodStart,
                $declarationStatus,
            );
            if ($relationship !== null) {
                $relationships[] = $relationship;
            }
        }
        $creditClaims = $this->taxCredits(
            $taxEvidence['credit_claims'] ?? null,
            $personReference,
        );
        $childClaims = $this->taxChildren(
            $taxEvidence['child_claims'] ?? null,
            $personReference,
        );
        if ($annual === null || $relationships === []) {
            return null;
        }
        try {
            return new MonthlyEmploymentIncomeTaxInput(
                $calculationDate,
                $personReference,
                $relationships,
                [new TaxDeclarationEvidence(
                    $declarationStatus,
                    $this->requiredString($declarationRow['effective_from'] ?? null),
                    $this->nullableString($declarationRow['effective_to'] ?? null),
                    $declarationStatus === TaxDeclarationStatus::Unverified
                        ? null
                        : $this->nullableString(
                            $declarationRow['evidence_reference'] ?? null,
                        ),
                )],
                new TaxResidenceEvidence(
                    $residence,
                    $this->requiredString($residenceRow['effective_from'] ?? null),
                    $this->nullableString($residenceRow['effective_to'] ?? null),
                    $residence === TaxResidence::Unverified
                        ? null
                        : $this->nullableString(
                            $residenceRow['evidence_reference'] ?? null,
                        ),
                ),
                $creditClaims,
                $childClaims,
                $annual,
                [],
                "supplier:{$supplierId}",
            );
        } catch (\InvalidArgumentException|\UnexpectedValueException) {
            $this->issue(
                'income_tax',
                'tax_evidence_mapping_failed',
                $personReference,
            );
            return null;
        }
    }

    /** @param mixed $snapshot */
    private function incomeTaxRelationship(
        mixed $snapshot,
        string $personReference,
        int $supplierId,
        string $periodStart,
        TaxDeclarationStatus $declarationStatus,
    ): ?EmploymentRelationshipTaxInput {
        if (!is_array($snapshot) || array_is_list($snapshot)) {
            $this->issue(
                'income_tax',
                'employment_snapshot_invalid',
                $personReference,
            );
            return null;
        }
        $employment = $this->object($snapshot['employment'] ?? null);
        if ($employment === null) {
            $this->issue(
                'income_tax',
                'employment_snapshot_invalid',
                $personReference,
            );
            return null;
        }
        $employmentId = $this->positiveInt($employment['id'] ?? null);
        if ($employmentId === null) {
            $this->issue(
                'income_tax',
                'employment_reference_invalid',
                $personReference,
            );
            return null;
        }
        $relationshipReference = "employment:{$employmentId}";
        if (($employment['employee_id'] ?? null)
            !== $this->personId($personReference)
        ) {
            $this->issue(
                'income_tax',
                'employment_person_mismatch',
                $personReference,
                $relationshipReference,
            );
            return null;
        }
        $term = $this->object($snapshot['term'] ?? null);
        if ($term === null) {
            $this->issue(
                'income_tax',
                'employment_term_missing',
                $personReference,
                $relationshipReference,
            );
            return null;
        }
        $signed = $term['tax_declaration_signed'] ?? null;
        $evidenceSigned = $declarationStatus === TaxDeclarationStatus::Signed;
        if (!is_bool($signed) || $signed !== $evidenceSigned) {
            $this->issue(
                'income_tax',
                'tax_declaration_term_conflict',
                $personReference,
                $relationshipReference,
            );
        }
        if (($term['tax_regime'] ?? null) !== 'advance') {
            $this->issue(
                'income_tax',
                'tax_regime_override_unsupported',
                $personReference,
                $relationshipReference,
            );
        }
        $relationType = $this->nonEmptyString($employment['relation_type'] ?? null);
        if ($relationType === null) {
            $this->issue(
                'income_tax',
                'relationship_kind_missing',
                $personReference,
                $relationshipReference,
            );
            return null;
        }
        try {
            $kind = $this->taxKinds->fromDatabaseRelationType($relationType);
        } catch (\UnexpectedValueException) {
            $this->issue(
                'income_tax',
                'relationship_kind_unsupported',
                $personReference,
                $relationshipReference,
            );
            return null;
        }
        $components = $this->taxComponents(
            $snapshot['inputs'] ?? null,
            $personReference,
            $relationshipReference,
            $periodStart,
        );
        if ($components === []) {
            return null;
        }

        return new EmploymentRelationshipTaxInput(
            $relationshipReference,
            "supplier:{$supplierId}",
            $kind,
            $components,
            OtherWithholdingEligibility::Automatic,
        );
    }

    /** @param array<string,mixed> $person */
    private function socialAccumulator(
        array $person,
        int $supplierId,
        int $employeeId,
        string $personReference,
        string $periodStart,
    ): ?int {
        $state = $this->accumulator(
            $person,
            'social_insurance',
            $supplierId,
            $employeeId,
            $personReference,
            $periodStart,
        );
        if ($state === null) {
            return null;
        }
        $value = $this->nonNegativeInt(
            $state['totals']['assessment_base_minor_units'] ?? null,
        );
        if ($value === null) {
            $this->issue(
                'social_insurance',
                'annual_accumulator_invalid',
                $personReference,
            );
            return null;
        }
        return $value;
    }

    /** @param array<string,mixed> $person */
    private function incomeTaxAccumulator(
        array $person,
        int $supplierId,
        int $employeeId,
        string $personReference,
        string $periodStart,
    ): ?AnnualTaxAccumulatorInput {
        $state = $this->accumulator(
            $person,
            'income_tax',
            $supplierId,
            $employeeId,
            $personReference,
            $periodStart,
        );
        if ($state === null) {
            return null;
        }
        $totals = $this->object($state['totals'] ?? null);
        $values = [
            'completed_months' => $this->nonNegativeInt(
                $totals['completed_months'] ?? null,
            ),
            'advance_base_minor_units' => $this->nonNegativeInt(
                $totals['advance_base_minor_units'] ?? null,
            ),
            'withholding_base_minor_units' => $this->nonNegativeInt(
                $totals['withholding_base_minor_units'] ?? null,
            ),
            'advance_tax_minor_units' => $this->nonNegativeInt(
                $totals['advance_tax_minor_units'] ?? null,
            ),
            'withholding_tax_minor_units' => $this->nonNegativeInt(
                $totals['withholding_tax_minor_units'] ?? null,
            ),
            'applied_non_refundable_credits_minor_units' =>
                $this->nonNegativeInt(
                    $totals[
                        'applied_non_refundable_credits_minor_units'
                    ] ?? null,
                ),
            'applied_child_credit_minor_units' => $this->nonNegativeInt(
                $totals['applied_child_credit_minor_units'] ?? null,
            ),
            'tax_bonus_minor_units' => $this->nonNegativeInt(
                $totals['tax_bonus_minor_units'] ?? null,
            ),
            'bonus_qualifying_income_minor_units' => $this->nonNegativeInt(
                $totals['bonus_qualifying_income_minor_units'] ?? null,
            ),
        ];
        if (in_array(null, $values, true)) {
            $this->issue(
                'income_tax',
                'annual_accumulator_invalid',
                $personReference,
            );
            return null;
        }
        try {
            return new AnnualTaxAccumulatorInput(
                (int) substr($periodStart, 0, 4),
                $values['completed_months'],
                $values['advance_base_minor_units'],
                $values['withholding_base_minor_units'],
                $values['advance_tax_minor_units'],
                $values['withholding_tax_minor_units'],
                $values['applied_non_refundable_credits_minor_units'],
                $values['applied_child_credit_minor_units'],
                $values['tax_bonus_minor_units'],
                $values['bonus_qualifying_income_minor_units'],
            );
        } catch (\InvalidArgumentException) {
            $this->issue(
                'income_tax',
                'annual_accumulator_invalid',
                $personReference,
            );
            return null;
        }
    }

    /**
     * @param array<string,mixed> $person
     * @return array<string,mixed>|null
     */
    private function accumulator(
        array $person,
        string $kind,
        int $supplierId,
        int $employeeId,
        string $personReference,
        string $periodStart,
    ): ?array {
        $domain = $kind;
        $accumulators = $this->object(
            $person['statutory_accumulators'] ?? null,
        );
        if (($accumulators['schema_version'] ?? null)
            !== 'payroll-person-statutory-accumulators.v1'
        ) {
            $this->issue(
                $domain,
                'annual_accumulator_missing',
                $personReference,
            );
            return null;
        }
        $wrapper = $this->object($accumulators[$kind] ?? null);
        $state = $this->object($wrapper['state'] ?? null);
        if (($wrapper['status'] ?? null) !== 'verified' || $state === null) {
            $issueCode = $wrapper['issue_code'] ?? null;
            $this->issue(
                $domain,
                is_string($issueCode)
                    && preg_match('/^[a-z][a-z0-9_]*$/D', $issueCode) === 1
                    ? $issueCode
                    : 'annual_accumulator_missing',
                $personReference,
            );
            return null;
        }
        if (($state['schema_version'] ?? null)
                !== 'payroll-statutory-accumulator-state.v1'
            || ($state['calculation_kind'] ?? null) !== $kind
            || ($state['supplier_id'] ?? null) !== $supplierId
            || ($state['employee_id'] ?? null) !== $employeeId
            || ($state['year'] ?? null) !== (int) substr($periodStart, 0, 4)
            || ($state['before_period_start'] ?? null) !== $periodStart
            || $this->object($state['totals'] ?? null) === null
        ) {
            $this->issue(
                $domain,
                'annual_accumulator_invalid',
                $personReference,
            );
            return null;
        }
        return $state;
    }

    /**
     * @return list<\MyInvoice\Service\Payroll\SocialInsurance\SocialAssessmentComponent>
     */
    private function socialComponents(
        mixed $raw,
        string $personReference,
        string $relationshipReference,
        string $periodStart,
    ): array {
        $inputs = $this->componentInputs(
            $raw,
            'social_insurance',
            $personReference,
            $relationshipReference,
        );
        $result = [];
        foreach ($inputs as $input) {
            if (!$this->assertCurrentNonNegativeComponent(
                $input,
                'social_insurance',
                $personReference,
                $relationshipReference,
                $periodStart,
            )) {
                continue;
            }
            $component = $this->object($input['component'] ?? null);
            if (in_array(
                'manual_review',
                [
                    $component['social_participation_treatment'] ?? null,
                    $component['social_treatment'] ?? null,
                ],
                true,
            )) {
                $this->issue(
                    'social_insurance',
                    'component_treatment_unverified',
                    $personReference,
                    $relationshipReference,
                );
                continue;
            }
            try {
                $result[] = $this->components->social($input);
            } catch (\InvalidArgumentException|\ValueError|\UnexpectedValueException) {
                $this->issue(
                    'social_insurance',
                    'component_mapping_failed',
                    $personReference,
                    $relationshipReference,
                );
            }
        }
        return $result;
    }

    /**
     * @return list<\MyInvoice\Service\Payroll\HealthInsurance\HealthAssessmentComponent>
     */
    private function healthComponents(
        mixed $raw,
        string $personReference,
        string $relationshipReference,
        string $periodStart,
    ): array {
        $inputs = $this->componentInputs(
            $raw,
            'health_insurance',
            $personReference,
            $relationshipReference,
        );
        $result = [];
        foreach ($inputs as $input) {
            if (!$this->assertCurrentNonNegativeComponent(
                $input,
                'health_insurance',
                $personReference,
                $relationshipReference,
                $periodStart,
            )) {
                continue;
            }
            $component = $this->object($input['component'] ?? null);
            if (in_array(
                'manual_review',
                [
                    $component['health_participation_treatment'] ?? null,
                    $component['health_treatment'] ?? null,
                ],
                true,
            )) {
                $this->issue(
                    'health_insurance',
                    'component_treatment_unverified',
                    $personReference,
                    $relationshipReference,
                );
                continue;
            }
            try {
                $result[] = $this->components->health($input, $periodStart);
            } catch (\InvalidArgumentException|\ValueError|\UnexpectedValueException) {
                $this->issue(
                    'health_insurance',
                    'component_mapping_failed',
                    $personReference,
                    $relationshipReference,
                );
            }
        }
        return $result;
    }

    /**
     * @return list<\MyInvoice\Service\Payroll\IncomeTax\IncomeTaxComponent>
     */
    private function taxComponents(
        mixed $raw,
        string $personReference,
        string $relationshipReference,
        string $periodStart,
    ): array {
        $inputs = $this->componentInputs(
            $raw,
            'income_tax',
            $personReference,
            $relationshipReference,
        );
        $result = [];
        foreach ($inputs as $input) {
            $component = $this->object($input['component'] ?? null);
            $treatment = $component['tax_treatment'] ?? null;
            $usable = true;
            if ($treatment === 'exempt') {
                $this->issue(
                    'income_tax',
                    'tax_component_exemption_evidence_missing',
                    $personReference,
                    $relationshipReference,
                );
                $usable = false;
            }
            if ($treatment === 'manual_review') {
                $this->issue(
                    'income_tax',
                    'component_treatment_unverified',
                    $personReference,
                    $relationshipReference,
                );
                $usable = false;
            }
            if (!$this->assertCurrentNonNegativeComponent(
                $input,
                'income_tax',
                $personReference,
                $relationshipReference,
                $periodStart,
            )) {
                $usable = false;
            }
            if (!$usable) {
                continue;
            }
            try {
                $result[] = $this->components->incomeTax($input, $periodStart);
            } catch (\InvalidArgumentException|\ValueError|\UnexpectedValueException) {
                $this->issue(
                    'income_tax',
                    'component_mapping_failed',
                    $personReference,
                    $relationshipReference,
                );
            }
        }
        return $result;
    }

    /** @return list<array<string,mixed>> */
    private function componentInputs(
        mixed $raw,
        string $domain,
        string $personReference,
        string $relationshipReference,
    ): array {
        $inputs = $this->list($raw);
        if ($inputs === null || $inputs === []) {
            $this->issue(
                $domain,
                'payroll_component_missing',
                $personReference,
                $relationshipReference,
            );
            return [];
        }
        $result = [];
        foreach ($inputs as $input) {
            if (!is_array($input) || array_is_list($input)) {
                $this->issue(
                    $domain,
                    'payroll_component_invalid',
                    $personReference,
                    $relationshipReference,
                );
                continue;
            }
            $result[] = $input;
        }
        return $result;
    }

    /** @param array<string,mixed> $input */
    private function assertCurrentNonNegativeComponent(
        array $input,
        string $domain,
        string $personReference,
        string $relationshipReference,
        string $periodStart,
    ): bool {
        $valid = true;
        $sourcePeriod = $input['source_period_start'] ?? null;
        if ($sourcePeriod !== null && $sourcePeriod !== $periodStart) {
            $this->issue(
                $domain,
                'prior_period_component_requires_revision',
                $personReference,
                $relationshipReference,
            );
            $valid = false;
        }
        $amount = $this->integer($input['amount_minor'] ?? null);
        if ($amount === null) {
            $this->issue(
                $domain,
                'component_amount_invalid',
                $personReference,
                $relationshipReference,
            );
            return false;
        }
        if ($amount < 0) {
            $this->issue(
                $domain,
                'negative_component_requires_revision',
                $personReference,
                $relationshipReference,
            );
            $valid = false;
        }
        return $valid;
    }

    /** @return list<HealthMinimumReductionInterval> */
    private function healthReductions(
        mixed $raw,
        string $personReference,
        string $periodEnd,
    ): array {
        $rows = $this->list($raw);
        if ($rows === null) {
            $this->issue(
                'health_insurance',
                'health_minimum_reductions_invalid',
                $personReference,
            );
            return [];
        }
        $result = [];
        foreach ($rows as $row) {
            if (!is_array($row) || array_is_list($row)) {
                $this->issue(
                    'health_insurance',
                    'health_minimum_reduction_invalid',
                    $personReference,
                );
                continue;
            }
            $reason = $this->enum(
                HealthMinimumReductionReason::class,
                $row['reason'] ?? null,
            );
            if (!$reason instanceof HealthMinimumReductionReason
                || $reason === HealthMinimumReductionReason::Unverified
            ) {
                $this->issue(
                    'health_insurance',
                    'health_minimum_reduction_unverified',
                    $personReference,
                );
                continue;
            }
            try {
                $result[] = new HealthMinimumReductionInterval(
                    $this->requiredString($row['effective_from'] ?? null),
                    $this->requiredString(
                        $row['effective_to'] ?? $periodEnd,
                    ),
                    $reason,
                    $this->nullableString($row['evidence_reference'] ?? null),
                );
            } catch (\InvalidArgumentException|\UnexpectedValueException) {
                $this->issue(
                    'health_insurance',
                    'health_minimum_reduction_invalid',
                    $personReference,
                );
            }
        }
        return $result;
    }

    /** @return list<HealthOtherEmployerBase> */
    private function healthOtherEmployers(
        mixed $raw,
        string $personReference,
    ): array {
        $rows = $this->list($raw);
        if ($rows === null) {
            $this->issue(
                'health_insurance',
                'health_other_employer_evidence_invalid',
                $personReference,
            );
            return [];
        }
        $result = [];
        foreach ($rows as $row) {
            if (!is_array($row) || array_is_list($row)) {
                $this->issue(
                    'health_insurance',
                    'health_other_employer_evidence_invalid',
                    $personReference,
                );
                continue;
            }
            try {
                $result[] = new HealthOtherEmployerBase(
                    $this->requiredString($row['employer_reference'] ?? null),
                    $this->requiredNonNegativeInt(
                        $row['assessment_base_minor_units'] ?? null,
                    ),
                    $this->requiredString($row['employment_from'] ?? null),
                    $this->nullableString($row['employment_to'] ?? null),
                    $this->requiredString($row['evidence_reference'] ?? null),
                );
            } catch (\InvalidArgumentException|\UnexpectedValueException) {
                $this->issue(
                    'health_insurance',
                    'health_other_employer_evidence_invalid',
                    $personReference,
                );
            }
        }
        return $result;
    }

    /** @return list<TaxCreditClaim> */
    private function taxCredits(mixed $raw, string $personReference): array
    {
        $rows = $this->list($raw);
        if ($rows === null) {
            $this->issue(
                'income_tax',
                'tax_credit_evidence_invalid',
                $personReference,
            );
            return [];
        }
        $result = [];
        foreach ($rows as $row) {
            if (!is_array($row) || array_is_list($row)) {
                $this->issue(
                    'income_tax',
                    'tax_credit_evidence_invalid',
                    $personReference,
                );
                continue;
            }
            $status = $this->enum(
                TaxEvidenceStatus::class,
                $row['evidence_status'] ?? null,
            );
            $kind = $this->enum(TaxCreditKind::class, $row['credit_kind'] ?? null);
            if (!$status instanceof TaxEvidenceStatus
                || !$kind instanceof TaxCreditKind
                || $status === TaxEvidenceStatus::Unverified
            ) {
                $this->issue(
                    'income_tax',
                    'tax_credit_evidence_unverified',
                    $personReference,
                );
                continue;
            }
            try {
                $result[] = new TaxCreditClaim(
                    $kind,
                    $this->requiredString($row['effective_from'] ?? null),
                    $this->nullableString($row['effective_to'] ?? null),
                    $status,
                    $this->nullableString($row['evidence_reference'] ?? null),
                );
            } catch (\InvalidArgumentException|\UnexpectedValueException) {
                $this->issue(
                    'income_tax',
                    'tax_credit_evidence_invalid',
                    $personReference,
                );
            }
        }
        return $result;
    }

    /** @return list<TaxChildClaim> */
    private function taxChildren(mixed $raw, string $personReference): array
    {
        $rows = $this->list($raw);
        if ($rows === null) {
            $this->issue(
                'income_tax',
                'tax_child_evidence_invalid',
                $personReference,
            );
            return [];
        }
        $result = [];
        foreach ($rows as $row) {
            if (!is_array($row) || array_is_list($row)) {
                $this->issue(
                    'income_tax',
                    'tax_child_evidence_invalid',
                    $personReference,
                );
                continue;
            }
            $status = $this->enum(
                TaxEvidenceStatus::class,
                $row['evidence_status'] ?? null,
            );
            if (!$status instanceof TaxEvidenceStatus
                || $status === TaxEvidenceStatus::Unverified
            ) {
                $this->issue(
                    'income_tax',
                    'tax_child_evidence_unverified',
                    $personReference,
                );
                continue;
            }
            try {
                $result[] = new TaxChildClaim(
                    $this->requiredString($row['child_reference'] ?? null),
                    $this->requiredPositiveInt($row['child_order'] ?? null),
                    $this->requiredBool($row['ztp_p'] ?? null),
                    $this->requiredString($row['effective_from'] ?? null),
                    $this->nullableString($row['effective_to'] ?? null),
                    $status,
                    $this->requiredBool(
                        $row['shared_household_confirmed'] ?? null,
                    ),
                    $this->requiredBool(
                        $row['other_claimant_excluded'] ?? null,
                    ),
                    $this->nullableString($row['evidence_reference'] ?? null),
                );
            } catch (\InvalidArgumentException|\UnexpectedValueException) {
                $this->issue(
                    'income_tax',
                    'tax_child_evidence_invalid',
                    $personReference,
                );
            }
        }
        return $result;
    }

    /**
     * @param array<string,mixed> $employment
     * @return array{string,?string}|null
     */
    private function employmentDates(array $employment): ?array
    {
        $from = $this->date(
            $employment['actual_start_date']
                ?? $employment['start_date']
                ?? null,
        );
        $to = $employment['end_date'] ?? null;
        if ($from === null
            || ($to !== null && $this->date($to) === null)
            || (is_string($to) && $to < $from)
        ) {
            return null;
        }
        return [$from, is_string($to) ? $to : null];
    }

    private function issue(
        string $domain,
        string $code,
        ?string $personReference = null,
        ?string $relationshipReference = null,
    ): void {
        $this->issues[] = new PayrollRunStatutoryInputIssue(
            $domain,
            $code,
            $personReference,
            $relationshipReference,
        );
    }

    private function hasDomainIssue(string $domain): bool
    {
        foreach ($this->issues as $issue) {
            if ($issue->domain === $domain || $issue->domain === 'snapshot') {
                return true;
            }
        }
        return false;
    }

    private function hasDomainIssueSince(string $domain, int $offset): bool
    {
        foreach (array_slice($this->issues, $offset) as $issue) {
            if ($issue->domain === $domain || $issue->domain === 'snapshot') {
                return true;
            }
        }
        return false;
    }

    private function sortAndDeduplicateIssues(): void
    {
        $unique = [];
        foreach ($this->issues as $issue) {
            $unique[$issue->sortKey()] = $issue;
        }
        ksort($unique, SORT_STRING);
        $this->issues = array_values($unique);
    }

    private function invalidSnapshot(string $code): PayrollRunStatutoryInputBundle
    {
        $this->issue('snapshot', $code);
        return new PayrollRunStatutoryInputBundle(
            null,
            null,
            [],
            $this->issues,
        );
    }

    /** @return array<string,mixed>|null */
    private function object(mixed $value): ?array
    {
        return is_array($value) && !array_is_list($value) ? $value : null;
    }

    /** @return list<mixed>|null */
    private function list(mixed $value): ?array
    {
        return is_array($value) && array_is_list($value) ? $value : null;
    }

    private function integer(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^-?\d+$/D', $value) === 1) {
            return (int) $value;
        }
        return null;
    }

    private function positiveInt(mixed $value): ?int
    {
        $integer = $this->integer($value);
        return $integer !== null && $integer > 0 ? $integer : null;
    }

    private function nonNegativeInt(mixed $value): ?int
    {
        $integer = $this->integer($value);
        return $integer !== null && $integer >= 0 ? $integer : null;
    }

    private function requiredPositiveInt(mixed $value): int
    {
        return $this->positiveInt($value)
            ?? throw new \UnexpectedValueException('Hodnota musí být kladná.');
    }

    private function requiredNonNegativeInt(mixed $value): int
    {
        return $this->nonNegativeInt($value)
            ?? throw new \UnexpectedValueException('Hodnota musí být nezáporná.');
    }

    private function nonEmptyString(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    private function nullableString(mixed $value): ?string
    {
        return $value === null ? null : $this->nonEmptyString($value);
    }

    private function requiredString(mixed $value): string
    {
        return $this->nonEmptyString($value)
            ?? throw new \UnexpectedValueException('Hodnota musí být text.');
    }

    private function requiredBool(mixed $value): bool
    {
        return is_bool($value)
            ? $value
            : throw new \UnexpectedValueException('Hodnota musí být boolean.');
    }

    private function personId(string $personReference): int
    {
        return (int) substr($personReference, strlen('employee:'));
    }

    private function date(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date !== false && $date->format('Y-m-d') === $value
            ? $value
            : null;
    }

    /**
     * @template T of \BackedEnum
     * @param class-string<T> $enum
     * @return T|null
     */
    private function enum(string $enum, mixed $value): ?\BackedEnum
    {
        if (!is_string($value)) {
            return null;
        }
        try {
            return $enum::from($value);
        } catch (\ValueError) {
            return null;
        }
    }
}
