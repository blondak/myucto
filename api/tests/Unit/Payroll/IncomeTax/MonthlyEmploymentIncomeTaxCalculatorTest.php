<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\IncomeTax;

use MyInvoice\Service\Payroll\IncomeTax\AnnualTaxAccumulatorInput;
use MyInvoice\Service\Payroll\IncomeTax\EmploymentIncomeTaxPolicy2026;
use MyInvoice\Service\Payroll\IncomeTax\EmploymentRelationshipKind;
use MyInvoice\Service\Payroll\IncomeTax\EmploymentRelationshipTaxInput;
use MyInvoice\Service\Payroll\IncomeTax\ExternalEmployerTaxCertificate;
use MyInvoice\Service\Payroll\IncomeTax\IncomeTaxComponent;
use MyInvoice\Service\Payroll\IncomeTax\IncomeTaxComponentTreatment;
use MyInvoice\Service\Payroll\IncomeTax\MonthlyEmploymentIncomeTaxCalculator;
use MyInvoice\Service\Payroll\IncomeTax\MonthlyEmploymentIncomeTaxInput;
use MyInvoice\Service\Payroll\IncomeTax\OtherWithholdingEligibility;
use MyInvoice\Service\Payroll\IncomeTax\TaxCalculationStatus;
use MyInvoice\Service\Payroll\IncomeTax\TaxChildClaim;
use MyInvoice\Service\Payroll\IncomeTax\TaxCreditClaim;
use MyInvoice\Service\Payroll\IncomeTax\TaxCreditKind;
use MyInvoice\Service\Payroll\IncomeTax\TaxCorrectionTreatment;
use MyInvoice\Service\Payroll\IncomeTax\TaxDeclarationEvidence;
use MyInvoice\Service\Payroll\IncomeTax\TaxDeclarationStatus;
use MyInvoice\Service\Payroll\IncomeTax\TaxEvidenceStatus;
use MyInvoice\Service\Payroll\IncomeTax\TaxResidence;
use MyInvoice\Service\Payroll\IncomeTax\TaxResidenceEvidence;
use MyInvoice\Service\Payroll\IncomeTax\TaxRegime;
use MyInvoice\Service\Payroll\Ruleset\CzechPayrollRulesets2026;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetDomain;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetLifecycle;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetProvider;
use MyInvoice\Service\Payroll\Ruleset\RulesetApproval;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MonthlyEmploymentIncomeTaxCalculatorTest extends TestCase
{
    /** @return iterable<string,array{array<string,mixed>}> */
    public static function goldenCases(): iterable
    {
        $json = file_get_contents(
            dirname(__DIR__, 3) . '/Fixtures/payroll/income-tax-2026-golden.json',
        );
        self::assertIsString($json);
        $cases = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($cases);

        foreach ($cases as $case) {
            self::assertIsArray($case);
            yield (string) $case['id'] => [$case];
        }
    }

    /** @param array<string,mixed> $case */
    #[DataProvider('goldenCases')]
    public function testSyntheticGoldenCases(array $case): void
    {
        $relationships = [];
        foreach ($case['relationships'] as $relationship) {
            $relationships[] = new EmploymentRelationshipTaxInput(
                (string) $relationship['reference'],
                'synthetic-payer',
                EmploymentRelationshipKind::from((string) $relationship['kind']),
                [new IncomeTaxComponent('synthetic-income', (int) $relationship['amount_minor'])],
                isset($relationship['other_withholding_eligible'])
                    ? OtherWithholdingEligibility::EligibleVerified
                    : OtherWithholdingEligibility::Automatic,
                isset($relationship['other_withholding_eligible'])
                    ? 'synthetic-classification-evidence'
                    : null,
            );
        }

        $result = $this->calculator()->calculate(new MonthlyEmploymentIncomeTaxInput(
            calculationDate: '2026-08-31',
            employeeReference: 'synthetic-employee',
            relationships: $relationships,
            declarations: [new TaxDeclarationEvidence(
                TaxDeclarationStatus::from((string) $case['declaration']),
                '2026-01-01',
                null,
                'synthetic-declaration-evidence',
            )],
            residence: new TaxResidenceEvidence(
                TaxResidence::from((string) $case['residence']),
                '2026-01-01',
                null,
                'synthetic-residence-evidence',
            ),
        ));

        self::assertSame(TaxCalculationStatus::Calculated, $result->status);
        self::assertSame('synthetic-payer', $result->payerReference);
        self::assertSame($case['advance_base_minor'], $result->advanceTax?->taxableIncomeMinorUnits);
        self::assertSame($case['advance_tax_minor'], $result->advanceTax?->taxAfterCreditsMinorUnits);
        self::assertSame($case['withholding_base_minor'], $result->withholdingBaseMinorUnits);
        self::assertSame($case['withholding_tax_minor'], $result->withholdingTaxMinorUnits);
        self::assertSame($case['relationship_regimes'], array_map(
            static fn ($relationship): string => $relationship->regime->value,
            $result->relationships,
        ));
    }

    public function testSignedDeclarationAggregatesAllRelationshipsAndAppliesVerifiedClaims(): void
    {
        $result = $this->calculator()->calculate(new MonthlyEmploymentIncomeTaxInput(
            calculationDate: '2026-08-31',
            employeeReference: 'synthetic-employee',
            relationships: [
                $this->relationship('employment', EmploymentRelationshipKind::Employment, 3_500_000),
                $this->relationship('dpp', EmploymentRelationshipKind::Dpp, 900_000),
                $this->relationship('director', EmploymentRelationshipKind::StatutoryBody, 390_000),
            ],
            declarations: [$this->signedDeclaration()],
            residence: $this->czechResidence(),
            creditClaims: [
                $this->credit(TaxCreditKind::Taxpayer),
                $this->credit(TaxCreditKind::DisabilityBasic),
                $this->credit(TaxCreditKind::ZtpP),
            ],
            childClaims: [
                $this->child('child-a', 1, true),
                $this->child('child-b', 2, false),
            ],
        ));

        self::assertSame(TaxCalculationStatus::Calculated, $result->status);
        self::assertSame(4_790_000, $result->advanceTax?->taxableIncomeMinorUnits);
        self::assertSame(718_500, $result->advanceTax?->taxBeforeCreditsMinorUnits);
        self::assertSame(412_500, $result->appliedNonRefundableCreditsMinorUnits);
        self::assertSame(439_400, $result->claimedChildCreditMinorUnits);
        self::assertSame(0, $result->advanceTax?->taxAfterCreditsMinorUnits);
        self::assertSame(133_400, $result->advanceTax?->taxBonusMinorUnits);
        self::assertSame(
            [TaxRegime::Advance, TaxRegime::Advance, TaxRegime::Advance],
            array_map(static fn ($item): TaxRegime => $item->regime, $result->relationships),
        );
    }

    public function testUnsignedThresholdsAggregateByPayerAndGroup(): void
    {
        $result = $this->calculator()->calculate(new MonthlyEmploymentIncomeTaxInput(
            calculationDate: '2026-08-31',
            employeeReference: 'synthetic-employee',
            relationships: [
                $this->relationship('dpp-a', EmploymentRelationshipKind::Dpp, 600_000),
                $this->relationship('dpp-b', EmploymentRelationshipKind::Dpp, 600_000),
                $this->relationship(
                    'dpc-a',
                    EmploymentRelationshipKind::Dpc,
                    300_000,
                    OtherWithholdingEligibility::EligibleVerified,
                ),
                $this->relationship(
                    'dpc-b',
                    EmploymentRelationshipKind::Dpc,
                    200_000,
                    OtherWithholdingEligibility::EligibleVerified,
                ),
            ],
            declarations: [$this->unsignedDeclaration()],
            residence: $this->czechResidence(),
        ));

        self::assertSame(TaxCalculationStatus::Calculated, $result->status);
        self::assertSame(1_700_000, $result->advanceTax?->taxableIncomeMinorUnits);
        self::assertSame(255_000, $result->advanceTax?->taxAfterCreditsMinorUnits);
        self::assertSame(0, $result->withholdingTaxMinorUnits);
        self::assertSame(
            [TaxRegime::Advance, TaxRegime::Advance, TaxRegime::Advance, TaxRegime::Advance],
            array_map(static fn ($item): TaxRegime => $item->regime, $result->relationships),
        );
    }

    public function testNonresidentStatutoryBodyRemunerationUsesAdvanceTaxIn2026(): void
    {
        $result = $this->calculator()->calculate(new MonthlyEmploymentIncomeTaxInput(
            calculationDate: '2026-08-31',
            employeeReference: 'synthetic-employee',
            relationships: [
                $this->relationship('director', EmploymentRelationshipKind::StatutoryBody, 400_000),
            ],
            declarations: [$this->unsignedDeclaration()],
            residence: new TaxResidenceEvidence(
                TaxResidence::NonResident,
                '2026-01-01',
                null,
                'synthetic-residence-evidence',
            ),
        ));

        self::assertSame(TaxCalculationStatus::Calculated, $result->status);
        self::assertSame(TaxRegime::Advance, $result->relationships[0]->regime);
        self::assertSame(60_000, $result->advanceTax?->taxAfterCreditsMinorUnits);
    }

    public function testDpcDoesNotBecomeWithholdingFromPaidAmountAlone(): void
    {
        $result = $this->calculator()->calculate(new MonthlyEmploymentIncomeTaxInput(
            calculationDate: '2026-08-31',
            employeeReference: 'synthetic-employee',
            relationships: [
                $this->relationship('dpc', EmploymentRelationshipKind::Dpc, 400_000),
            ],
            declarations: [$this->unsignedDeclaration()],
            residence: $this->czechResidence(),
        ));

        self::assertSame(TaxCalculationStatus::ManualReview, $result->status);
        self::assertContains('other-withholding-eligibility-unverified', $result->issues);

        $verified = $this->calculator()->calculate(new MonthlyEmploymentIncomeTaxInput(
            calculationDate: '2026-08-31',
            employeeReference: 'synthetic-employee',
            relationships: [
                $this->relationship(
                    'dpc',
                    EmploymentRelationshipKind::Dpc,
                    400_000,
                    OtherWithholdingEligibility::IneligibleVerified,
                ),
            ],
            declarations: [$this->unsignedDeclaration()],
            residence: $this->czechResidence(),
        ));
        self::assertSame(TaxCalculationStatus::Calculated, $verified->status);
        self::assertSame(TaxRegime::Advance, $verified->relationships[0]->regime);
    }

    public function testContradictoryDppClassificationFailsClosed(): void
    {
        $result = $this->calculator()->calculate(new MonthlyEmploymentIncomeTaxInput(
            calculationDate: '2026-08-31',
            employeeReference: 'synthetic-employee',
            relationships: [
                $this->relationship(
                    'dpp',
                    EmploymentRelationshipKind::Dpp,
                    400_000,
                    OtherWithholdingEligibility::EligibleVerified,
                ),
            ],
            declarations: [$this->unsignedDeclaration()],
            residence: $this->czechResidence(),
        ));

        self::assertSame(TaxCalculationStatus::ManualReview, $result->status);
        self::assertContains('relationship-tax-classification-conflict', $result->issues);
    }

    public function testDuplicateRelationshipReferenceCannotBeCountedTwice(): void
    {
        $result = $this->calculator()->calculate(new MonthlyEmploymentIncomeTaxInput(
            calculationDate: '2026-08-31',
            employeeReference: 'synthetic-employee',
            relationships: [
                $this->relationship('employment', EmploymentRelationshipKind::Employment, 1_000_000),
                $this->relationship('employment', EmploymentRelationshipKind::Employment, 1_000_000),
            ],
            declarations: [$this->unsignedDeclaration()],
            residence: $this->czechResidence(),
        ));

        self::assertSame(TaxCalculationStatus::ManualReview, $result->status);
        self::assertContains('duplicate-employment-relationship-reference', $result->issues);
    }

    public function testIncompleteOrConflictingEvidenceFailsClosed(): void
    {
        $input = new MonthlyEmploymentIncomeTaxInput(
            calculationDate: '2026-08-31',
            employeeReference: 'synthetic-employee',
            relationships: [
                $this->relationship('employment', EmploymentRelationshipKind::Employment, 4_000_000),
            ],
            declarations: [
                $this->signedDeclaration(),
                $this->unsignedDeclaration(),
            ],
            residence: new TaxResidenceEvidence(TaxResidence::Unverified),
            creditClaims: [
                new TaxCreditClaim(
                    TaxCreditKind::Taxpayer,
                    '2026-01-01',
                    null,
                    TaxEvidenceStatus::Unverified,
                ),
            ],
        );

        $result = $this->calculator()->calculate($input);

        self::assertSame(TaxCalculationStatus::ManualReview, $result->status);
        self::assertNull($result->advanceTax);
        self::assertContains('tax-declaration-conflict', $result->issues);
        self::assertContains('tax-residence-unverified', $result->issues);
        self::assertContains('tax-credit-evidence-unverified', $result->issues);
    }

    public function testUnverifiedExemptionAndStaleResidenceFailClosed(): void
    {
        $result = $this->calculator()->calculate(new MonthlyEmploymentIncomeTaxInput(
            calculationDate: '2026-08-31',
            employeeReference: 'synthetic-employee',
            relationships: [new EmploymentRelationshipTaxInput(
                'employment',
                'synthetic-payer',
                EmploymentRelationshipKind::Employment,
                [
                    new IncomeTaxComponent('salary', 2_000_000),
                    new IncomeTaxComponent(
                        'benefit',
                        100_000,
                        IncomeTaxComponentTreatment::Exempt,
                    ),
                ],
            )],
            declarations: [$this->unsignedDeclaration()],
            residence: new TaxResidenceEvidence(
                TaxResidence::CzechResident,
                '2025-01-01',
                '2025-12-31',
                'synthetic-stale-residence-evidence',
            ),
        ));

        self::assertSame(TaxCalculationStatus::ManualReview, $result->status);
        self::assertContains(
            'income-component-exemption-evidence-unverified',
            $result->issues,
        );
        self::assertContains('tax-residence-evidence-not-effective', $result->issues);
    }

    public function testCurrentMonthCorrectionIsNettedButPriorPeriodCorrectionBlocks(): void
    {
        $current = $this->calculator()->calculate(new MonthlyEmploymentIncomeTaxInput(
            calculationDate: '2026-08-31',
            employeeReference: 'synthetic-employee',
            relationships: [new EmploymentRelationshipTaxInput(
                'employment',
                'synthetic-payer',
                EmploymentRelationshipKind::Employment,
                [
                    new IncomeTaxComponent('salary', 2_000_000),
                    new IncomeTaxComponent('current-correction', -100_000),
                ],
            )],
            declarations: [$this->unsignedDeclaration()],
            residence: $this->czechResidence(),
        ));
        self::assertSame(TaxCalculationStatus::Calculated, $current->status);
        self::assertSame(1_900_000, $current->advanceTax?->taxableIncomeMinorUnits);

        $prior = $this->calculator()->calculate(new MonthlyEmploymentIncomeTaxInput(
            calculationDate: '2026-08-31',
            employeeReference: 'synthetic-employee',
            relationships: [new EmploymentRelationshipTaxInput(
                'employment',
                'synthetic-payer',
                EmploymentRelationshipKind::Employment,
                [
                    new IncomeTaxComponent(
                        'prior-correction',
                        -100_000,
                        correctionTreatment: TaxCorrectionTreatment::PriorPeriodRevision,
                    ),
                ],
            )],
            declarations: [$this->unsignedDeclaration()],
            residence: $this->czechResidence(),
        ));
        self::assertSame(TaxCalculationStatus::ManualReview, $prior->status);
        self::assertContains(
            'prior-period-tax-correction-requires-revision',
            $prior->issues,
        );
    }

    public function testChildOrderGapAndConcurrentClaimFailClosed(): void
    {
        $result = $this->calculator()->calculate(new MonthlyEmploymentIncomeTaxInput(
            calculationDate: '2026-08-31',
            employeeReference: 'synthetic-employee',
            relationships: [
                $this->relationship('employment', EmploymentRelationshipKind::Employment, 4_000_000),
            ],
            declarations: [$this->signedDeclaration()],
            residence: $this->czechResidence(),
            childClaims: [new TaxChildClaim(
                'synthetic-child',
                2,
                false,
                '2026-01-01',
                null,
                TaxEvidenceStatus::Verified,
                true,
                false,
                'synthetic-child-evidence',
            )],
        ));

        self::assertSame(TaxCalculationStatus::ManualReview, $result->status);
        self::assertContains('tax-child-order-gap', $result->issues);
        self::assertContains('tax-child-concurrent-claim-unresolved', $result->issues);
    }

    public function testAnnualAccumulatorNeverSilentlyAddsExternalCertificate(): void
    {
        $result = $this->calculator()->calculate(new MonthlyEmploymentIncomeTaxInput(
            calculationDate: '2026-08-31',
            employeeReference: 'synthetic-employee',
            relationships: [
                $this->relationship('employment', EmploymentRelationshipKind::Employment, 4_790_000),
            ],
            declarations: [$this->signedDeclaration()],
            residence: $this->czechResidence(),
            creditClaims: [$this->credit(TaxCreditKind::Taxpayer)],
            annualAccumulator: new AnnualTaxAccumulatorInput(
                year: 2026,
                completedMonths: 7,
                advanceBaseMinorUnits: 28_000_000,
                withholdingBaseMinorUnits: 0,
                advanceTaxMinorUnits: 1_200_000,
                withholdingTaxMinorUnits: 0,
                appliedNonRefundableCreditsMinorUnits: 1_799_000,
                appliedChildCreditMinorUnits: 0,
                taxBonusMinorUnits: 0,
                bonusQualifyingIncomeMinorUnits: 28_000_000,
            ),
            externalCertificates: [
                new ExternalEmployerTaxCertificate(
                    'synthetic-certificate',
                    5_000_000,
                    750_000,
                    TaxEvidenceStatus::Verified,
                    'synthetic-reviewed-document',
                ),
            ],
        ));

        self::assertSame(32_790_000, $result->annualAccumulator->advanceBaseMinorUnits);
        self::assertSame(1_661_500, $result->annualAccumulator->advanceTaxMinorUnits);
        self::assertFalse($result->annualAccumulator->externalCertificatesIncluded);
        self::assertFalse($result->annualAccumulator->annualSettlementReady);
        self::assertTrue($result->annualAccumulator->annualBonusIncomeThresholdMet);
        self::assertCount(1, $result->annualAccumulator->externalCertificates);
        self::assertSame(
            EmploymentIncomeTaxPolicy2026::ID,
            $result->policyId,
        );
        self::assertSame(64, strlen($result->policyHash));
        self::assertSame(64, strlen($result->rulesetHash));
    }

    private function calculator(): MonthlyEmploymentIncomeTaxCalculator
    {
        $provider = CzechPayrollRulesets2026::provider();
        $reviewed = $provider->forDate(PayrollRulesetDomain::IncomeTax, '2026-08-31');
        $approval = new RulesetApproval(
            'synthetic-independent-reviewer',
            '2026-08-02',
            'synthetic-independent-approver',
            '2026-08-03',
            'Synthetic approval used only by deterministic unit tests.',
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

        return new MonthlyEmploymentIncomeTaxCalculator(
            new PayrollRulesetProvider([$active]),
            EmploymentIncomeTaxPolicy2026::create(),
        );
    }

    private function relationship(
        string $reference,
        EmploymentRelationshipKind $kind,
        int $amountMinorUnits,
        OtherWithholdingEligibility $eligibility = OtherWithholdingEligibility::Automatic,
    ): EmploymentRelationshipTaxInput {
        return new EmploymentRelationshipTaxInput(
            $reference,
            'synthetic-payer',
            $kind,
            [new IncomeTaxComponent('synthetic-income', $amountMinorUnits)],
            $eligibility,
            $eligibility === OtherWithholdingEligibility::Automatic
                ? null
                : 'synthetic-classification-evidence',
        );
    }

    private function signedDeclaration(): TaxDeclarationEvidence
    {
        return new TaxDeclarationEvidence(
            TaxDeclarationStatus::Signed,
            '2026-01-01',
            null,
            'synthetic-declaration-evidence',
        );
    }

    private function unsignedDeclaration(): TaxDeclarationEvidence
    {
        return new TaxDeclarationEvidence(
            TaxDeclarationStatus::NotSigned,
            '2026-01-01',
            null,
            'synthetic-declaration-evidence',
        );
    }

    private function czechResidence(): TaxResidenceEvidence
    {
        return new TaxResidenceEvidence(
            TaxResidence::CzechResident,
            '2026-01-01',
            null,
            'synthetic-residence-evidence',
        );
    }

    private function credit(TaxCreditKind $kind): TaxCreditClaim
    {
        return new TaxCreditClaim(
            $kind,
            '2026-01-01',
            null,
            TaxEvidenceStatus::Verified,
            'synthetic-credit-evidence',
        );
    }

    private function child(string $reference, int $order, bool $ztpP): TaxChildClaim
    {
        return new TaxChildClaim(
            $reference,
            $order,
            $ztpP,
            '2026-01-01',
            null,
            TaxEvidenceStatus::Verified,
            true,
            true,
            'synthetic-child-evidence',
        );
    }
}
