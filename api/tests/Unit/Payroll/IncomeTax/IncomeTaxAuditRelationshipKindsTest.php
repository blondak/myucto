<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\IncomeTax;

use MyInvoice\Service\Payroll\IncomeTax\EmploymentRelationshipKind;
use MyInvoice\Service\Payroll\IncomeTax\EmploymentRelationshipTaxInput;
use MyInvoice\Service\Payroll\IncomeTax\IncomeTaxComponent;
use MyInvoice\Service\Payroll\IncomeTax\MonthlyEmploymentIncomeTaxCalculator;
use MyInvoice\Service\Payroll\IncomeTax\MonthlyEmploymentIncomeTaxInput;
use MyInvoice\Service\Payroll\IncomeTax\TaxCalculationStatus;
use MyInvoice\Service\Payroll\IncomeTax\TaxCreditClaim;
use MyInvoice\Service\Payroll\IncomeTax\TaxCreditKind;
use MyInvoice\Service\Payroll\IncomeTax\TaxDeclarationEvidence;
use MyInvoice\Service\Payroll\IncomeTax\TaxDeclarationStatus;
use MyInvoice\Service\Payroll\IncomeTax\TaxEvidenceStatus;
use MyInvoice\Service\Payroll\IncomeTax\TaxRegime;
use MyInvoice\Service\Payroll\IncomeTax\TaxResidence;
use MyInvoice\Service\Payroll\IncomeTax\TaxResidenceEvidence;
use MyInvoice\Service\Payroll\Ruleset\CzechPayrollRulesets2026;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetDomain;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * AUDIT MZDOVÉHO MODULU (private/MZDY-AUDIT.md) — daňový režim podle druhu
 * vztahu, ručně spočítaný podle ZDP (rok 2026: rozhodná částka DPP 12 000 Kč,
 * ostatní 4 500 Kč, sleva na poplatníka 2 570 Kč/měs., 23 % nad 146 901 Kč).
 */
final class IncomeTaxAuditRelationshipKindsTest extends TestCase
{
    /**
     * @return iterable<string,array{EmploymentRelationshipKind,int,TaxRegime,int,int}>
     *   kind, hrubý příjem (haléře), režim, srážková daň, záloha po slevách
     */
    public static function unsignedCases(): iterable
    {
        // § 6 odst. 4 písm. a): DPP 10 000 < 12 000 → 15 % srážka ze základu 10 000 = 1 500.
        yield 'DPP 10 000 Kč bez prohlášení' => [
            EmploymentRelationshipKind::Dpp,
            1_000_000, TaxRegime::Withholding, 150_000, 0,
        ];
        // Přesně na rozhodné částce už účast vzniká → záloha 15 % z 12 000 = 1 800.
        yield 'DPP 12 000 Kč bez prohlášení (hranice)' => [
            EmploymentRelationshipKind::Dpp,
            1_200_000, TaxRegime::Advance, 0, 180_000,
        ];
        // 11 999,50: základ se zaokrouhlí dolů na 11 999, daň 1 799,85 → dolů 1 799.
        yield 'DPP 11 999,50 Kč bez prohlášení (dvojí zaokrouhlení dolů)' => [
            EmploymentRelationshipKind::Dpp,
            1_199_950, TaxRegime::Withholding, 179_900, 0,
        ];
        // § 6 odst. 4 písm. b): DPČ 4 000 < 4 500 → srážka 600.
        yield 'DPČ 4 000 Kč bez prohlášení' => [
            EmploymentRelationshipKind::Dpc,
            400_000, TaxRegime::Withholding, 60_000, 0,
        ];
        // DPČ 4 500 = rozhodná částka → záloha 675.
        yield 'DPČ 4 500 Kč bez prohlášení (hranice)' => [
            EmploymentRelationshipKind::Dpc,
            450_000, TaxRegime::Advance, 0, 67_500,
        ];
        // Jednatel 3 000 Kč bez prohlášení → srážka 450.
        yield 'jednatel 3 000 Kč bez prohlášení' => [
            EmploymentRelationshipKind::StatutoryBody,
            300_000, TaxRegime::Withholding, 45_000, 0,
        ];
        // Jednatel 30 000 Kč bez prohlášení → záloha 4 500 (bez slevy).
        yield 'jednatel 30 000 Kč bez prohlášení' => [
            EmploymentRelationshipKind::StatutoryBody,
            3_000_000, TaxRegime::Advance, 0, 450_000,
        ];
        // Zaměstnání malého rozsahu 4 000 Kč bez prohlášení → srážka 600.
        yield 'zaměstnání malého rozsahu 4 000 Kč bez prohlášení' => [
            EmploymentRelationshipKind::SmallScaleEmployment,
            400_000, TaxRegime::Withholding, 60_000, 0,
        ];
        // OPRAVENO: dřív tu stálo „pracovní poměr se vždy daní zálohou, i 4 000 Kč“
        // a test tu chybu držel. § 6 odst. 4 písm. b) ZDP se ale na druh vztahu
        // neptá, jen na úhrn příjmů od plátce v měsíci pod rozhodnou částkou
        // (2026: 4 500 Kč). Pracovní poměr 4 000 Kč bez prohlášení → srážka 600.
        yield 'pracovní poměr 4 000 Kč bez prohlášení' => [
            EmploymentRelationshipKind::Employment,
            400_000, TaxRegime::Withholding, 60_000, 0,
        ];
        yield 'pracovní poměr 4 500 Kč bez prohlášení (hranice)' => [
            EmploymentRelationshipKind::Employment,
            450_000, TaxRegime::Advance, 0, 67_500,
        ];
    }

    #[DataProvider('unsignedCases')]
    public function testRegimeAndAmountsWithoutDeclaration(
        EmploymentRelationshipKind $kind,
        int $grossMinor,
        TaxRegime $regime,
        int $withholdingTaxMinor,
        int $advanceTaxMinor,
    ): void {
        $result = $this->calculator()->calculate(new MonthlyEmploymentIncomeTaxInput(
            calculationDate: '2026-08-31',
            employeeReference: 'synthetic-employee',
            relationships: [$this->relationship('vztah', $kind, $grossMinor)],
            declarations: [$this->declaration(TaxDeclarationStatus::NotSigned)],
            residence: $this->residence(TaxResidence::CzechResident),
        ));

        self::assertSame(TaxCalculationStatus::Calculated, $result->status, implode(',', $result->issues));
        self::assertSame($regime, $result->relationships[0]->regime);
        self::assertSame($withholdingTaxMinor, $result->withholdingTaxMinorUnits);
        self::assertSame($advanceTaxMinor, $result->advanceTax?->taxAfterCreditsMinorUnits);
    }

    /**
     * Prohlášení podepsané, nízký příjem: záloha 1 800 < sleva 2 570. Aplikace
     * správně srazí 0, ale eviduje claimed 2 570 vs. applied 1 800 — a JMHZ
     * resolver na tom rozdílu blokuje (viz JmhzAuditRelationshipMatrixTest).
     */
    public function testLowIncomeWithDeclarationAppliesOnlyPartOfTheTaxpayerCredit(): void
    {
        $result = $this->calculator()->calculate(new MonthlyEmploymentIncomeTaxInput(
            calculationDate: '2026-08-31',
            employeeReference: 'synthetic-employee',
            relationships: [$this->relationship('hpp', EmploymentRelationshipKind::Employment, 1_200_000)],
            declarations: [$this->declaration(TaxDeclarationStatus::Signed)],
            residence: $this->residence(TaxResidence::CzechResident),
            creditClaims: [$this->taxpayerCredit()],
        ));

        self::assertSame(TaxCalculationStatus::Calculated, $result->status);
        self::assertSame(180_000, $result->advanceTax?->taxBeforeCreditsMinorUnits);
        self::assertSame(0, $result->advanceTax?->taxAfterCreditsMinorUnits);
        self::assertSame(257_000, $result->claimedNonRefundableCreditsMinorUnits);
        self::assertSame(180_000, $result->appliedNonRefundableCreditsMinorUnits);
    }

    /**
     * Jednatel 30 000 Kč s prohlášením: 15 % z 30 000 = 4 500 − 2 570 = 1 930.
     * DPP 8 000 Kč s prohlášením: 1 200 − 2 570 → 0.
     */
    public function testSignedDeclarationForcesAdvanceRegimeForEveryKind(): void
    {
        foreach ([
            [EmploymentRelationshipKind::StatutoryBody, 3_000_000, 193_000],
            [EmploymentRelationshipKind::Dpp, 800_000, 0],
            [EmploymentRelationshipKind::Dpc, 400_000, 0],
        ] as [$kind, $gross, $expectedAdvance]) {
            $result = $this->calculator()->calculate(new MonthlyEmploymentIncomeTaxInput(
                calculationDate: '2026-08-31',
                employeeReference: 'synthetic-employee',
                relationships: [$this->relationship('vztah', $kind, $gross)],
                declarations: [$this->declaration(TaxDeclarationStatus::Signed)],
                residence: $this->residence(TaxResidence::CzechResident),
                creditClaims: [$this->taxpayerCredit()],
            ));

            self::assertSame(TaxCalculationStatus::Calculated, $result->status, $kind->value . ': ' . implode(',', $result->issues));
            self::assertSame(TaxRegime::Advance, $result->relationships[0]->regime, $kind->value);
            self::assertSame(0, $result->withholdingTaxMinorUnits, $kind->value);
            self::assertSame($expectedAdvance, $result->advanceTax?->taxAfterCreditsMinorUnits, $kind->value);
        }
    }

    /**
     * 150 000 Kč hrubého s prohlášením: 146 901 × 15 % = 22 035,15;
     * 3 099 × 23 % = 712,77; součet 22 747,92 → nahoru 22 748; − 2 570 = 20 178.
     */
    public function testHighRateBandIsAppliedAboveTheMonthlyThreshold(): void
    {
        $result = $this->calculator()->calculate(new MonthlyEmploymentIncomeTaxInput(
            calculationDate: '2026-08-31',
            employeeReference: 'synthetic-employee',
            relationships: [$this->relationship('hpp', EmploymentRelationshipKind::Employment, 15_000_000)],
            declarations: [$this->declaration(TaxDeclarationStatus::Signed)],
            residence: $this->residence(TaxResidence::CzechResident),
            creditClaims: [$this->taxpayerCredit()],
        ));

        self::assertSame(2_274_800, $result->advanceTax?->taxBeforeCreditsMinorUnits);
        self::assertSame(2_017_800, $result->advanceTax?->taxAfterCreditsMinorUnits);
    }

    /**
     * Odměna nerezidentního člena orgánu — fyzické osoby se od 1. 1. 2026
     * nesráží podle § 36 odst. 1 (zák. č. 360/2025 Sb., čl. VI body 24 a 25),
     * posuzuje se jako u rezidenta: 30 000 Kč rozhodnou částku § 6 odst. 4
     * písm. b) dosahuje, takže záloha.
     */
    public function testNonResidentStatutoryBodyIsCurrentlyTaxedByAdvanceNotSection36Withholding(): void
    {
        $result = $this->calculator()->calculate(new MonthlyEmploymentIncomeTaxInput(
            calculationDate: '2026-08-31',
            employeeReference: 'synthetic-employee',
            relationships: [$this->relationship(
                'jednatel',
                EmploymentRelationshipKind::StatutoryBody,
                3_000_000,
            )],
            declarations: [$this->declaration(TaxDeclarationStatus::NotSigned)],
            residence: $this->residence(TaxResidence::NonResident),
        ));

        self::assertSame(TaxCalculationStatus::Calculated, $result->status, implode(',', $result->issues));
        self::assertSame(TaxRegime::Advance, $result->relationships[0]->regime);
        self::assertSame(0, $result->withholdingTaxMinorUnits);
        self::assertSame(450_000, $result->advanceTax?->taxAfterCreditsMinorUnits);
    }

    /**
     * Dvě DPP u téhož plátce bez prohlášení: 7 000 + 6 000 = 13 000 ≥ 12 000,
     * srážka nepřipadá v úvahu (úhrn), obě zálohou: 15 % z 13 000 = 1 950.
     */
    public function testMultipleDppAtOnePayerAreAggregatedForTheThreshold(): void
    {
        $result = $this->calculator()->calculate(new MonthlyEmploymentIncomeTaxInput(
            calculationDate: '2026-08-31',
            employeeReference: 'synthetic-employee',
            relationships: [
                $this->relationship('dpp-1', EmploymentRelationshipKind::Dpp, 700_000),
                $this->relationship('dpp-2', EmploymentRelationshipKind::Dpp, 600_000),
            ],
            declarations: [$this->declaration(TaxDeclarationStatus::NotSigned)],
            residence: $this->residence(TaxResidence::CzechResident),
        ));

        self::assertSame(TaxCalculationStatus::Calculated, $result->status);
        self::assertSame(TaxRegime::Advance, $result->relationships[0]->regime);
        self::assertSame(TaxRegime::Advance, $result->relationships[1]->regime);
        self::assertSame(195_000, $result->advanceTax?->taxAfterCreditsMinorUnits);
    }

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

    private function declaration(TaxDeclarationStatus $status): TaxDeclarationEvidence
    {
        return new TaxDeclarationEvidence($status, '2026-01-01', null, 'synthetic-declaration-evidence');
    }

    private function residence(TaxResidence $residence): TaxResidenceEvidence
    {
        return new TaxResidenceEvidence($residence, '2026-01-01', null, 'synthetic-residence-evidence');
    }

    private function taxpayerCredit(): TaxCreditClaim
    {
        return new TaxCreditClaim(
            TaxCreditKind::Taxpayer,
            '2026-01-01',
            null,
            TaxEvidenceStatus::Verified,
            'synthetic-credit-evidence',
        );
    }
}
