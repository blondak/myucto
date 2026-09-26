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
        // Adresář je `Fixtures/Payroll` s velkým P. Malé písmeno projde na Windows,
        // ale na Linuxu (a tedy na CI) vrátí file_get_contents rovnou false.
        $json = file_get_contents(
            dirname(__DIR__, 3) . '/Fixtures/Payroll/income-tax-2026-golden.json',
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
                $this->relationship('dpc-a', EmploymentRelationshipKind::Dpc, 300_000),
                $this->relationship('dpc-b', EmploymentRelationshipKind::Dpc, 200_000),
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

    /**
     * Nad rozhodnou částkou se odměna nerezidentního člena orgánu daní zálohou.
     *
     * Od 1. 1. 2026 pro ni neplatí zvláštní sazba daně podle § 36 odst. 1:
     * zákon č. 360/2025 Sb. (čl. VI body 24 a 25, účinnost podle čl. XXXIV
     * k 1. 1. 2026) vyňal odměnu člena orgánu — FYZICKÉ OSOBY z § 22 odst. 1
     * písm. g) bodu 6 do nového bodu 15 a § 36 odst. 1 písm. a) bod 1 dál
     * vyjmenovává jen „body 1, 2, 6, 12 až 14“.
     */
    public function testNonresidentStatutoryBodyRemunerationUsesAdvanceTaxIn2026(): void
    {
        $result = $this->calculator()->calculate(new MonthlyEmploymentIncomeTaxInput(
            calculationDate: '2026-08-31',
            employeeReference: 'synthetic-employee',
            relationships: [$this->relationship(
                'director',
                EmploymentRelationshipKind::StatutoryBody,
                3_000_000,
            )],
            declarations: [$this->unsignedDeclaration()],
            residence: $this->nonResidence(),
        ));

        self::assertSame(TaxCalculationStatus::Calculated, $result->status);
        self::assertSame(TaxRegime::Advance, $result->relationships[0]->regime);
        self::assertSame(0, $result->withholdingTaxMinorUnits);
        self::assertSame(450_000, $result->advanceTax?->taxAfterCreditsMinorUnits);
    }

    /**
     * Pod rozhodnou částkou a bez prohlášení poplatníka se nerezidentní člen
     * orgánu daní SRÁŽKOU podle § 6 odst. 4 písm. b) ZDP, sazbou 15 % podle
     * § 36 odst. 2 písm. m) — přesně jako rezident.
     *
     * § 6 odst. 4 žádnou podmínku daňové rezidence nemá a zvláštní sazba 35 %
     * (§ 36 odst. 1 písm. c)) se váže na „příjmy uvedené v písmenech a) a b)“,
     * takže na tenhle příjem nedopadá. Cílem novely bylo právě sjednocení
     * postupu s jednateli — rezidenty (tisková zpráva GFŘ „Daňové novinky pro
     * rok 2026“ z 5. 1. 2026).
     *
     * Do 9/2026 výpočet tuhle kombinaci odmítal jako „rozpor zařazení“ —
     * zbytek pravidla platného do 31. 12. 2025, kdy odměna nerezidentního člena
     * orgánu šla vždy zvláštní sazbou podle § 36 odst. 1 a § 6 odst. 4 na ni
     * nedopadal.
     */
    public function testNonresidentStatutoryBodyUsesSection6Paragraph4LikeAResident(): void
    {
        $result = $this->calculator()->calculate(new MonthlyEmploymentIncomeTaxInput(
            calculationDate: '2026-08-31',
            employeeReference: 'synthetic-employee',
            relationships: [$this->relationship(
                'director',
                EmploymentRelationshipKind::StatutoryBody,
                300_000,
            )],
            declarations: [$this->unsignedDeclaration()],
            residence: $this->nonResidence(),
        ));

        self::assertSame(TaxCalculationStatus::Calculated, $result->status, implode(',', $result->issues));
        self::assertSame(TaxRegime::Withholding, $result->relationships[0]->regime);
        self::assertSame(45_000, $result->withholdingTaxMinorUnits);
        self::assertSame(0, $result->advanceTax?->taxAfterCreditsMinorUnits);
    }

    private function nonResidence(): TaxResidenceEvidence
    {
        return new TaxResidenceEvidence(
            TaxResidence::NonResident,
            '2026-01-01',
            null,
            'synthetic-residence-evidence',
        );
    }

    /**
     * DPČ bez prohlášení se sráží, kdykoli úhrn v měsíci nedosáhne rozhodné
     * částky, ať je sjednaná odměna jakákoli. Test se dřív jmenoval
     * „DpcDoesNotBecomeWithholdingFromPaidAmountAlone“ a držel opak: bez
     * prohlášení plátce ruční posouzení, s prohlášením „zakládá účast“ záloha
     * i ze 4 000 Kč. § 6 odst. 4 písm. b) ZDP ale rozhoduje právě a jen
     * vyplacenou částkou.
     */
    public function testDpcBecomesWithholdingFromPaidAmountAlone(): void
    {
        $below = $this->calculator()->calculate(new MonthlyEmploymentIncomeTaxInput(
            calculationDate: '2026-08-31',
            employeeReference: 'synthetic-employee',
            relationships: [
                $this->relationship('dpc', EmploymentRelationshipKind::Dpc, 400_000),
            ],
            declarations: [$this->unsignedDeclaration()],
            residence: $this->czechResidence(),
        ));

        self::assertSame(TaxCalculationStatus::Calculated, $below->status, implode(',', $below->issues));
        self::assertSame(TaxRegime::Withholding, $below->relationships[0]->regime);
        self::assertSame(60_000, $below->withholdingTaxMinorUnits);

        $above = $this->calculator()->calculate(new MonthlyEmploymentIncomeTaxInput(
            calculationDate: '2026-08-31',
            employeeReference: 'synthetic-employee',
            relationships: [
                $this->relationship('dpc', EmploymentRelationshipKind::Dpc, 500_000),
            ],
            declarations: [$this->unsignedDeclaration()],
            residence: $this->czechResidence(),
        ));
        self::assertSame(TaxCalculationStatus::Calculated, $above->status);
        self::assertSame(TaxRegime::Advance, $above->relationships[0]->regime);
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

    /**
     * § 35c odst. 1 a 9: první dítě domácnosti uplatňuje partner, zaměstnanec
     * uplatňuje druhé. Dítě „N" drží pořadí 1, takže mezera nevzniká a druhé
     * dítě dostane sazbu druhého dítěte (1 860 Kč), ne prvního.
     */
    public function testChildClaimedByOtherFillsTheOrderGapAndKeepsSecondChildRate(): void
    {
        $result = $this->calculator()->calculate($this->childInput([
            $this->childClaimedByOther('child-a', 1),
            $this->child('child-b', 2, false),
        ]));

        self::assertSame(TaxCalculationStatus::Calculated, $result->status);
        self::assertSame([], $result->issues);
        self::assertSame(186_000, $result->claimedChildCreditMinorUnits);
    }

    /** Bez dítěte „N" zůstává samotné druhé dítě mezerou v pořadí. */
    public function testSecondChildWithoutClaimedByOtherStillHasOrderGap(): void
    {
        $result = $this->calculator()->calculate($this->childInput([
            $this->child('child-b', 2, false),
        ]));

        self::assertSame(TaxCalculationStatus::ManualReview, $result->status);
        self::assertContains('tax-child-order-gap', $result->issues);
    }

    /**
     * Zaměstnanec, který uvádí jen děti „N", zvýhodnění neuplatňuje vůbec —
     * mzda se kvůli tomu nesmí zastavit.
     */
    public function testOnlyChildrenClaimedByOtherYieldNoCreditAndNoIssue(): void
    {
        $result = $this->calculator()->calculate($this->childInput([
            $this->childClaimedByOther('child-a', 1),
        ]));

        self::assertSame(TaxCalculationStatus::Calculated, $result->status);
        self::assertSame([], $result->issues);
        self::assertSame(0, $result->claimedChildCreditMinorUnits);
    }

    /** Dítě „N" drží pořadí — nikdo jiný v domácnosti ho mít nesmí. */
    public function testChildClaimedByOtherCannotShareOrderWithClaimedChild(): void
    {
        $result = $this->calculator()->calculate($this->childInput([
            $this->childClaimedByOther('child-a', 1),
            $this->child('child-b', 1, false),
        ]));

        self::assertSame(TaxCalculationStatus::ManualReview, $result->status);
        self::assertContains('tax-child-order-conflict', $result->issues);
    }

    /** @param list<TaxChildClaim> $children */
    private function childInput(array $children): MonthlyEmploymentIncomeTaxInput
    {
        return new MonthlyEmploymentIncomeTaxInput(
            calculationDate: '2026-08-31',
            employeeReference: 'synthetic-employee',
            relationships: [
                $this->relationship('employment', EmploymentRelationshipKind::Employment, 4_000_000),
            ],
            declarations: [$this->signedDeclaration()],
            residence: $this->czechResidence(),
            childClaims: $children,
        );
    }

    private function childClaimedByOther(string $reference, int $order): TaxChildClaim
    {
        return new TaxChildClaim(
            $reference,
            $order,
            false,
            '2026-01-01',
            null,
            TaxEvidenceStatus::Verified,
            true,
            false,
            'synthetic-child-evidence',
            creditClaimed: false,
        );
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

    /**
     * Odměna jednatele bez podepsaného prohlášení: § 6 odst. 4 písm. b) ZDP ji
     * pod rozhodnou částkou (4 500 Kč pro rok 2026) daní srážkou 15 %.
     */
    public function testStatutoryBodyBelowDecisiveAmountIsTaxedByWithholding(): void
    {
        $result = $this->calculator()->calculate(new MonthlyEmploymentIncomeTaxInput(
            calculationDate: '2026-08-31',
            employeeReference: 'synthetic-employee',
            relationships: [$this->relationship(
                'director',
                EmploymentRelationshipKind::StatutoryBody,
                440_000,
            )],
            declarations: [$this->unsignedDeclaration()],
            residence: $this->czechResidence(),
        ));

        self::assertSame(TaxCalculationStatus::Calculated, $result->status);
        self::assertSame([], $result->issues);
        self::assertSame(TaxRegime::Withholding, $result->relationships[0]->regime);
        self::assertSame(440_000, $result->withholdingBaseMinorUnits);
        self::assertSame(66_000, $result->withholdingTaxMinorUnits);
        self::assertSame(0, $result->advanceTax?->taxableIncomeMinorUnits);
    }

    /**
     * Jednatel s odměnou PŘESNĚ na rozhodné částce. Test § 6 odst. 4 ZDP je
     * ostrý („nedosahující"), takže 4 500 Kč se daní zálohou — ne srážkou.
     */
    public function testStatutoryBodyExactlyAtDecisiveAmountFallsBackToAdvance(): void
    {
        $result = $this->calculator()->calculate(new MonthlyEmploymentIncomeTaxInput(
            calculationDate: '2026-08-31',
            employeeReference: 'synthetic-employee',
            relationships: [$this->relationship(
                'director',
                EmploymentRelationshipKind::StatutoryBody,
                450_000,
            )],
            declarations: [$this->unsignedDeclaration()],
            residence: $this->czechResidence(),
        ));

        self::assertSame(TaxCalculationStatus::Calculated, $result->status);
        self::assertSame([], $result->issues);
        self::assertSame(TaxRegime::Advance, $result->relationships[0]->regime);
        self::assertSame(0, $result->withholdingBaseMinorUnits);
        self::assertSame(450_000, $result->advanceTax?->taxableIncomeMinorUnits);
    }

    /**
     * Každý druh vztahu se bez prohlášení zařadí sám, bez prohlášení plátce
     * a bez ručního posouzení: DPP podle písm. a), ostatní podle písm. b).
     *
     * Dřív tu test hlídal opak — že DPČ, jednatel a společník bez „prohlášení
     * plátce o účasti na nemocenském pojištění“ skončí v ručním posouzení
     * a pracovní poměr vždy zálohou. § 6 odst. 4 písm. b) ZDP se ale na účast
     * ani na sjednanou odměnu neptá, jen na skutečný úhrn příjmů od plátce
     * v měsíci; sestavovač vstupů proto sloupec s prohlášením plátce nečte.
     */
    public function testEveryRelationshipKindIsClassifiedWithoutPayerStatement(): void
    {
        foreach (EmploymentRelationshipKind::cases() as $kind) {
            $result = $this->calculator()->calculate(new MonthlyEmploymentIncomeTaxInput(
                calculationDate: '2026-08-31',
                employeeReference: 'synthetic-employee',
                relationships: [$this->relationship('vztah', $kind, 300_000)],
                declarations: [$this->unsignedDeclaration()],
                residence: $this->czechResidence(),
            ));

            self::assertSame(TaxCalculationStatus::Calculated, $result->status, $kind->value . ': ' . implode(',', $result->issues));
            self::assertSame(TaxRegime::Withholding, $result->relationships[0]->regime, $kind->value);
            self::assertSame($kind->withholdingGroup(), $result->relationships[0]->withholdingGroup, $kind->value);
            self::assertSame(45_000, $result->withholdingTaxMinorUnits, $kind->value);
        }
    }

    /** Dodaná sada je účinná rovnou — není co aktivovat ani co schvalovat. */
    private function calculator(): MonthlyEmploymentIncomeTaxCalculator
    {
        return new MonthlyEmploymentIncomeTaxCalculator(
            new PayrollRulesetProvider([
                CzechPayrollRulesets2026::provider()
                    ->forDate(PayrollRulesetDomain::IncomeTax, '2026-08-31'),
            ]),
        );
    }

    private function relationship(
        string $reference,
        EmploymentRelationshipKind $kind,
        int $amountMinorUnits,
    ): EmploymentRelationshipTaxInput {
        return new EmploymentRelationshipTaxInput(
            $reference,
            'synthetic-payer',
            $kind,
            [new IncomeTaxComponent('synthetic-income', $amountMinorUnits)],
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
