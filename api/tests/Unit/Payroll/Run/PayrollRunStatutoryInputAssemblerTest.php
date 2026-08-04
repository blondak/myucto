<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Run;

use MyInvoice\Service\Payroll\HealthInsurance\HealthMinimumTopUpEmployerSelection;
use MyInvoice\Service\Payroll\Run\PayrollRunStatutoryInputAssembler;
use PHPUnit\Framework\TestCase;

final class PayrollRunStatutoryInputAssemblerTest extends TestCase
{
    public function testBuildsCanonicalInputsFromCompleteVersionTwoSnapshot(): void
    {
        $bundle = (new PayrollRunStatutoryInputAssembler())->assemble(
            $this->completeSnapshot(),
        );

        self::assertSame([], $bundle->issues);
        self::assertNotNull($bundle->socialInsurance);
        self::assertNotNull($bundle->healthInsurance);
        self::assertCount(1, $bundle->incomeTax);

        $socialPerson = $bundle->socialInsurance->people[0];
        self::assertSame('employee:42', $socialPerson->personId);
        self::assertSame(12_300_000, $socialPerson->yearToDateAssessmentBaseBeforeMonthMinorUnits);
        self::assertSame(
            'employment:84',
            $socialPerson->relationships[0]->relationshipId,
        );
        self::assertSame(
            'input.420.mzda_mesicni',
            $socialPerson->relationships[0]->components[0]->code,
        );

        $healthPerson = $bundle->healthInsurance->people[0];
        self::assertSame('employee:42', $healthPerson->personId);
        self::assertSame('111', $healthPerson->insurerCode);
        self::assertSame(
            HealthMinimumTopUpEmployerSelection::ThisEmployer,
            $healthPerson->topUpEmployerSelection,
        );

        $tax = $bundle->incomeTax[0];
        self::assertSame('employee:42', $tax->employeeReference);
        self::assertSame('supplier:7', $tax->payerReference);
        self::assertSame('employment:84', $tax->relationships[0]->relationshipReference);
        self::assertSame(5, $tax->annualAccumulator?->completedMonths);
    }

    public function testMissingAnnualAccumulatorsBlockInputsInsteadOfInventingZero(): void
    {
        $snapshot = $this->completeSnapshot();
        unset($snapshot['people'][0]['statutory_accumulators']);

        $bundle = (new PayrollRunStatutoryInputAssembler())->assemble($snapshot);

        self::assertNull($bundle->socialInsurance);
        self::assertNotNull($bundle->healthInsurance);
        self::assertSame([], $bundle->incomeTax);
        self::assertSame([
            [
                'domain' => 'income_tax',
                'code' => 'annual_accumulator_missing',
                'person_reference' => 'employee:42',
                'relationship_reference' => null,
            ],
            [
                'domain' => 'social_insurance',
                'code' => 'annual_accumulator_missing',
                'person_reference' => 'employee:42',
                'relationship_reference' => null,
            ],
        ], array_map(
            static fn ($issue): array => $issue->toArray(),
            $bundle->issues,
        ));
    }

    public function testUnverifiedOverridesAndCorrectionsReturnDeterministicScopedIssues(): void
    {
        $snapshot = $this->completeSnapshot();
        $person = &$snapshot['people'][0];
        $person['statutory_evidence']['social']['jurisdiction'] = [
            'id' => 5,
            'effective_from' => '2026-01-01',
            'effective_to' => null,
            'row_version' => 1,
            'jurisdiction' => 'foreign_regime_verified',
            'foreign_country_code' => 'DE',
            'jurisdiction_evidence_reference' => 'document:foreign-regime',
            'a1_status' => 'unverified',
            'a1_certificate_reference' => null,
            'a1_valid_until' => null,
        ];
        $person['employments'][0]['term']['social_insurance_participation'] =
            'included';
        $person['employments'][0]['inputs'][0]['source_period_start'] =
            '2026-05-01';
        $person['employments'][0]['inputs'][0]['component']['tax_treatment'] =
            'exempt';
        unset($person);

        $bundle = (new PayrollRunStatutoryInputAssembler())->assemble($snapshot);

        self::assertNull($bundle->socialInsurance);
        self::assertNull($bundle->healthInsurance);
        self::assertSame([], $bundle->incomeTax);
        self::assertSame([
            'health_insurance|prior_period_component_requires_revision|employee:42|employment:84',
            'income_tax|prior_period_component_requires_revision|employee:42|employment:84',
            'income_tax|tax_component_exemption_evidence_missing|employee:42|employment:84',
            'social_insurance|participation_override_unsupported|employee:42|employment:84',
            'social_insurance|prior_period_component_requires_revision|employee:42|employment:84',
            'social_insurance|social_a1_evidence_unverified|employee:42|',
        ], array_map(
            static fn ($issue): string => implode('|', [
                $issue->domain,
                $issue->code,
                $issue->personReference,
                $issue->relationshipReference,
            ]),
            $bundle->issues,
        ));
    }

    public function testContradictoryTaxDeclarationEvidenceBlocksOnlyTaxDomain(): void
    {
        $snapshot = $this->completeSnapshot();
        $snapshot['people'][0]['employments'][0]['term']['tax_declaration_signed'] =
            false;

        $bundle = (new PayrollRunStatutoryInputAssembler())->assemble($snapshot);

        self::assertNotNull($bundle->socialInsurance);
        self::assertNotNull($bundle->healthInsurance);
        self::assertSame([], $bundle->incomeTax);
        self::assertSame(
            'tax_declaration_term_conflict',
            $bundle->issues[0]->code,
        );
        self::assertSame(
            'employment:84',
            $bundle->issues[0]->relationshipReference,
        );
    }

    public function testUnverifiedAndCrossTenantAccumulatorStatesFailClosed(): void
    {
        $snapshot = $this->completeSnapshot();
        $snapshot['people'][0]['statutory_accumulators']['social_insurance'] = [
            'status' => 'unverified',
            'issue_code' => 'annual_accumulator_opening_missing',
            'state' => null,
        ];
        $snapshot['people'][0]['statutory_accumulators']['income_tax']['state']
            ['supplier_id'] = 8;

        $bundle = (new PayrollRunStatutoryInputAssembler())->assemble($snapshot);

        self::assertNull($bundle->socialInsurance);
        self::assertSame([], $bundle->incomeTax);
        self::assertSame([
            'income_tax|annual_accumulator_invalid',
            'social_insurance|annual_accumulator_opening_missing',
        ], array_map(
            static fn ($issue): string => "{$issue->domain}|{$issue->code}",
            $bundle->issues,
        ));
    }

    /** @return array<string,mixed> */
    private function completeSnapshot(): array
    {
        return [
            'schema_version' => 'payroll-run-input.v2',
            'supplier_id' => 7,
            'period_start' => '2026-06-01',
            'period_end' => '2026-06-30',
            'payment_date' => '2026-07-15',
            'statutory_period' => [
                'period_start' => '2026-06-01',
                'period_end' => '2026-06-30',
                'payment_date' => '2026-07-15',
                'tax_calculation_date' => '2026-06-30',
                'social_calculation_date' => '2026-06-30',
                'health_calculation_date' => '2026-06-30',
            ],
            'people' => [[
                'employee' => [
                    'id' => 42,
                    'full_name' => 'Testovací Zaměstnanec',
                ],
                'statutory_accumulators' => [
                    'schema_version' =>
                        'payroll-person-statutory-accumulators.v1',
                    'social_insurance' => [
                        'status' => 'verified',
                        'issue_code' => null,
                        'state' => [
                            'schema_version' =>
                                'payroll-statutory-accumulator-state.v1',
                            'supplier_id' => 7,
                            'employee_id' => 42,
                            'calculation_kind' => 'social_insurance',
                            'year' => 2026,
                            'before_period_start' => '2026-06-01',
                            'totals' => [
                                'assessment_base_minor_units' => 12_300_000,
                            ],
                        ],
                    ],
                    'income_tax' => [
                        'status' => 'verified',
                        'issue_code' => null,
                        'state' => [
                            'schema_version' =>
                                'payroll-statutory-accumulator-state.v1',
                            'supplier_id' => 7,
                            'employee_id' => 42,
                            'calculation_kind' => 'income_tax',
                            'year' => 2026,
                            'before_period_start' => '2026-06-01',
                            'totals' => [
                                'completed_months' => 5,
                                'advance_base_minor_units' => 12_300_000,
                                'withholding_base_minor_units' => 0,
                                'advance_tax_minor_units' => 1_845_000,
                                'withholding_tax_minor_units' => 0,
                                'applied_non_refundable_credits_minor_units' =>
                                    154_200,
                                'applied_child_credit_minor_units' => 0,
                                'tax_bonus_minor_units' => 0,
                                'bonus_qualifying_income_minor_units' =>
                                    12_300_000,
                            ],
                        ],
                    ],
                ],
                'statutory_evidence' => $this->completeEvidence(),
                'employments' => [[
                    'employment' => [
                        'id' => 84,
                        'employee_id' => 42,
                        'relation_type' => 'employment',
                        'start_date' => '2025-01-01',
                        'actual_start_date' => '2025-01-02',
                        'end_date' => null,
                        'monthly_gross_minor' => 4_500_000,
                    ],
                    'term' => [
                        'id' => 99,
                        'effective_from' => '2025-01-01',
                        'effective_to' => null,
                        'social_insurance_participation' => 'automatic',
                        'health_insurance_participation' => 'automatic',
                        'tax_regime' => 'advance',
                        'tax_declaration_signed' => true,
                    ],
                    'inputs' => [[
                        'id' => 420,
                        'amount_minor' => 4_500_000,
                        'source_period_start' => null,
                        'component' => [
                            'code' => 'MZDA_MESICNI',
                            'tax_treatment' => 'included',
                            'social_participation_treatment' => 'included',
                            'social_treatment' => 'included',
                            'health_participation_treatment' => 'included',
                            'health_treatment' => 'included',
                        ],
                    ]],
                ]],
            ]],
        ];
    }

    /** @return array<string,mixed> */
    private function completeEvidence(): array
    {
        return [
            'schema_version' => 'payroll-person-statutory-evidence.v1',
            'employee_id' => 42,
            'effective_on' => '2026-06-30',
            'health' => [
                'coverage' => [
                    'id' => 1,
                    'effective_from' => '2026-01-01',
                    'effective_to' => null,
                    'row_version' => 1,
                    'jurisdiction' => 'czech_regime_verified',
                    'foreign_country_code' => null,
                    'jurisdiction_evidence_reference' => null,
                    'insurer_status' => 'verified',
                    'insurer_code' => '111',
                    'insurer_evidence_reference' => 'document:health-insurer',
                ],
                'minimum_reductions' => [],
                'month_evidence' => [
                    'id' => 2,
                    'period_start' => '2026-06-01',
                    'row_version' => 1,
                    'top_up_responsibility' => 'employee',
                    'top_up_responsibility_evidence_reference' => null,
                    'selected_top_up_employer_reference' => null,
                    'selected_top_up_employer_evidence_reference' => null,
                ],
                'other_employer_bases' => [],
            ],
            'income_tax' => [
                'declaration' => [
                    'id' => 3,
                    'effective_from' => '2026-01-01',
                    'effective_to' => null,
                    'row_version' => 1,
                    'status' => 'signed',
                    'evidence_reference' => 'document:tax-declaration',
                ],
                'residence' => [
                    'id' => 4,
                    'effective_from' => '2026-01-01',
                    'effective_to' => null,
                    'row_version' => 1,
                    'residence' => 'czech-resident',
                    'country_code' => 'CZ',
                    'evidence_reference' => 'document:tax-residence',
                ],
                'credit_claims' => [[
                    'id' => 5,
                    'effective_from' => '2026-01-01',
                    'effective_to' => null,
                    'row_version' => 1,
                    'credit_kind' => 'taxpayer',
                    'evidence_status' => 'verified',
                    'evidence_reference' => 'document:taxpayer-credit',
                ]],
                'child_claims' => [],
            ],
            'social' => [
                'jurisdiction' => [
                    'id' => 6,
                    'effective_from' => '2026-01-01',
                    'effective_to' => null,
                    'row_version' => 1,
                    'jurisdiction' => 'czech_regime_verified',
                    'foreign_country_code' => null,
                    'jurisdiction_evidence_reference' => null,
                    'a1_status' => 'not_applicable',
                    'a1_certificate_reference' => null,
                    'a1_valid_until' => null,
                ],
                'working_pensioner_discount' => [
                    'id' => 7,
                    'effective_from' => '2026-01-01',
                    'effective_to' => null,
                    'row_version' => 1,
                    'status' => 'not_claimed',
                    'evidence_reference' => null,
                ],
            ],
        ];
    }
}
