<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\IncomeTax;

use MyInvoice\Service\Payroll\IncomeTax\EmploymentRelationshipKind;
use MyInvoice\Service\Payroll\IncomeTax\EmploymentRelationshipTaxInput;
use MyInvoice\Service\Payroll\IncomeTax\IncomeTaxComponent;
use MyInvoice\Service\Payroll\IncomeTax\MonthlyEmploymentIncomeTaxCalculator;
use MyInvoice\Service\Payroll\IncomeTax\MonthlyEmploymentIncomeTaxInput;
use MyInvoice\Service\Payroll\IncomeTax\MonthlyEmploymentIncomeTaxResult;
use MyInvoice\Service\Payroll\IncomeTax\TaxCalculationStatus;
use MyInvoice\Service\Payroll\IncomeTax\TaxDeclarationEvidence;
use MyInvoice\Service\Payroll\IncomeTax\TaxDeclarationStatus;
use MyInvoice\Service\Payroll\IncomeTax\TaxRegime;
use MyInvoice\Service\Payroll\IncomeTax\TaxResidence;
use MyInvoice\Service\Payroll\IncomeTax\TaxResidenceEvidence;
use MyInvoice\Service\Payroll\Ruleset\CzechPayrollRulesets2026;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetDomain;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Samostatný základ daně podle § 6 odst. 4 ZDP (znění od 1. 1. 2025):
 *
 *   „… pokud zaměstnanec u tohoto plátce daně neučinil prohlášení k dani …
 *   a jedná-li se o příjmy podle odstavce 1
 *   a) plynoucí na základě dohody o provedení práce, jejichž úhrnná výše
 *      u téhož plátce daně nedosáhne za kalendářní měsíc částku rozhodnou pro
 *      účast zaměstnanců činných na základě dohody o provedení práce na
 *      nemocenském pojištění, nebo
 *   b) v úhrnné výši nedosahující u téhož plátce daně za kalendářní měsíc
 *      rozhodné částky pro účast zaměstnanců na nemocenském pojištění.“
 *
 * Písmeno b) nerozlišuje druh vztahu ani sjednanou mzdu. Pracovní poměr, DPČ
 * i odměna jednatele se bez prohlášení srazí, kdykoli skutečný úhrn příjmů od
 * téhož plátce v měsíci nedosáhne rozhodné částky (2026: 4 500 Kč). Sazba
 * 15 % podle § 36 odst. 2 písm. m), základ i daň zaokrouhlené dolů na celé
 * koruny (§ 36 odst. 3).
 *
 * Souběh s DPP u téhož plátce podle pokynu GFŘ D-59 (k § 6 odst. 4 a § 38h):
 * příjem z DPP do její rozhodné částky se srazí podle písm. a) „bez ohledu na
 * tyto další příjmy“; když úhrn z DPP rozhodnou částku překročí, jdou do
 * zálohy „všechny příjmy plynoucí poplatníkovi od téhož plátce daně“.
 */
final class SeparateTaxBaseSection6Paragraph4Test extends TestCase
{
    /**
     * @return iterable<string,array{EmploymentRelationshipKind,int,TaxRegime,int,int}>
     *   druh vztahu, příjem (haléře), režim, srážková daň, záloha
     */
    public static function singleRelationshipWithoutDeclaration(): iterable
    {
        // 2 397 × 15 % = 359,55 → dolů 359 (záloha by byla 15 % z 2 400 = 360).
        yield 'pracovní poměr 2 397 Kč' => [
            EmploymentRelationshipKind::Employment, 239_700, TaxRegime::Withholding, 35_900, 0,
        ];
        // 2 483 × 15 % = 372,45 → dolů 372 (záloha by byla 15 % z 2 500 = 375).
        yield 'pracovní poměr 2 483 Kč' => [
            EmploymentRelationshipKind::Employment, 248_300, TaxRegime::Withholding, 37_200, 0,
        ];
        // 4 499 × 15 % = 674,85 → dolů 674.
        yield 'pracovní poměr 4 499 Kč (těsně pod hranicí)' => [
            EmploymentRelationshipKind::Employment, 449_900, TaxRegime::Withholding, 67_400, 0,
        ];
        // „Nedosahující“ je ostře menší: 4 499,99 hranice nedosahuje, základ
        // se zaokrouhlí na 4 499 Kč.
        yield 'pracovní poměr 4 499,99 Kč' => [
            EmploymentRelationshipKind::Employment, 449_999, TaxRegime::Withholding, 67_400, 0,
        ];
        // Přesně rozhodná částka už ji „dosahuje“ → záloha 15 % z 4 500 = 675.
        yield 'pracovní poměr 4 500 Kč (hranice)' => [
            EmploymentRelationshipKind::Employment, 450_000, TaxRegime::Advance, 0, 67_500,
        ];
        yield 'DPČ 3 000 Kč' => [
            EmploymentRelationshipKind::Dpc, 300_000, TaxRegime::Withholding, 45_000, 0,
        ];
        yield 'jednatel 3 000 Kč' => [
            EmploymentRelationshipKind::StatutoryBody, 300_000, TaxRegime::Withholding, 45_000, 0,
        ];
        yield 'společník s. r. o. 3 000 Kč' => [
            EmploymentRelationshipKind::ManagingPartnerDependent, 300_000, TaxRegime::Withholding, 45_000, 0,
        ];
        yield 'DPČ 4 500 Kč (hranice)' => [
            EmploymentRelationshipKind::Dpc, 450_000, TaxRegime::Advance, 0, 67_500,
        ];
        // Písm. a) má vlastní hranici (2026: 12 000 Kč).
        yield 'DPP 11 999 Kč' => [
            EmploymentRelationshipKind::Dpp, 1_199_900, TaxRegime::Withholding, 179_900, 0,
        ];
        yield 'DPP 12 000 Kč (hranice)' => [
            EmploymentRelationshipKind::Dpp, 1_200_000, TaxRegime::Advance, 0, 180_000,
        ];
    }

    #[DataProvider('singleRelationshipWithoutDeclaration')]
    public function testSingleRelationshipWithoutDeclaration(
        EmploymentRelationshipKind $kind,
        int $incomeMinor,
        TaxRegime $regime,
        int $withholdingTaxMinor,
        int $advanceTaxMinor,
    ): void {
        $result = $this->calculate(
            TaxDeclarationStatus::NotSigned,
            $this->relationship('vztah', $kind, $incomeMinor),
        );

        self::assertSame(TaxCalculationStatus::Calculated, $result->status, implode(',', $result->issues));
        self::assertSame($regime, $result->relationships[0]->regime);
        self::assertSame($withholdingTaxMinor, $result->withholdingTaxMinorUnits);
        self::assertSame($advanceTaxMinor, $result->advanceTax?->taxAfterCreditsMinorUnits);
    }

    public function testSignedDeclarationAlwaysUsesAdvanceTax(): void
    {
        $result = $this->calculate(
            TaxDeclarationStatus::Signed,
            $this->relationship('hpp', EmploymentRelationshipKind::Employment, 239_700),
            $this->relationship('dpp', EmploymentRelationshipKind::Dpp, 200_000),
        );

        self::assertSame(TaxCalculationStatus::Calculated, $result->status, implode(',', $result->issues));
        self::assertSame(
            [TaxRegime::Advance, TaxRegime::Advance],
            $this->regimes($result),
        );
        self::assertSame(0, $result->withholdingTaxMinorUnits);
        self::assertSame(439_700, $result->advanceTax?->taxableIncomeMinorUnits);
    }

    /**
     * Pracovní poměr 3 000 + DPČ 2 000 u téhož plátce: úhrn podle písm. b)
     * je 5 000 ≥ 4 500, takže se nesrazí ani z jednoho a záloha jde z úhrnu:
     * 15 % z 5 000 = 750.
     */
    public function testConcurrentRelationshipsAreSummedForLetterB(): void
    {
        $result = $this->calculate(
            TaxDeclarationStatus::NotSigned,
            $this->relationship('hpp', EmploymentRelationshipKind::Employment, 300_000),
            $this->relationship('dpc', EmploymentRelationshipKind::Dpc, 200_000),
        );

        self::assertSame(TaxCalculationStatus::Calculated, $result->status, implode(',', $result->issues));
        self::assertSame([TaxRegime::Advance, TaxRegime::Advance], $this->regimes($result));
        self::assertSame(500_000, $result->advanceTax?->taxableIncomeMinorUnits);
        self::assertSame(75_000, $result->advanceTax?->taxAfterCreditsMinorUnits);
        self::assertSame(0, $result->withholdingTaxMinorUnits);
    }

    /**
     * Pracovní poměr 3 000 + DPČ 1 000: úhrn 4 000 < 4 500 → jeden samostatný
     * základ 4 000, srážka 600.
     */
    public function testConcurrentRelationshipsBelowThresholdShareOneWithholdingBase(): void
    {
        $result = $this->calculate(
            TaxDeclarationStatus::NotSigned,
            $this->relationship('hpp', EmploymentRelationshipKind::Employment, 300_000),
            $this->relationship('dpc', EmploymentRelationshipKind::Dpc, 100_000),
        );

        self::assertSame(TaxCalculationStatus::Calculated, $result->status, implode(',', $result->issues));
        self::assertSame([TaxRegime::Withholding, TaxRegime::Withholding], $this->regimes($result));
        self::assertSame(400_000, $result->withholdingBaseMinorUnits);
        self::assertSame(60_000, $result->withholdingTaxMinorUnits);
        self::assertCount(1, $result->withholdingGroups);
        self::assertSame('other', $result->withholdingGroups[0]->group);
    }

    /**
     * Pracovní poměr 3 000 + DPP 2 000 u téhož plátce. DPP do rozhodné částky
     * se podle GFŘ D-59 srazí podle písm. a) „bez ohledu na tyto další příjmy“,
     * takže do úhrnu písm. b) nevstupuje; ten je 3 000 < 4 500. Dva samostatné
     * základy: DPP 2 000 → 300, pracovní poměr 3 000 → 450.
     */
    public function testDppWithinItsLimitIsWithheldSeparatelyFromEmployment(): void
    {
        $result = $this->calculate(
            TaxDeclarationStatus::NotSigned,
            $this->relationship('hpp', EmploymentRelationshipKind::Employment, 300_000),
            $this->relationship('dpp', EmploymentRelationshipKind::Dpp, 200_000),
        );

        self::assertSame(TaxCalculationStatus::Calculated, $result->status, implode(',', $result->issues));
        self::assertSame([TaxRegime::Withholding, TaxRegime::Withholding], $this->regimes($result));
        self::assertSame('other', $result->relationships[0]->withholdingGroup);
        self::assertSame('dpp', $result->relationships[1]->withholdingGroup);
        self::assertSame(75_000, $result->withholdingTaxMinorUnits);
        self::assertSame(0, $result->advanceTax?->taxableIncomeMinorUnits);
    }

    /**
     * Pracovní poměr 5 000 + DPP 2 000: DPP se srazí podle písm. a), pracovní
     * poměr sám rozhodnou částku dosahuje → záloha z 5 000.
     */
    public function testDppWithinItsLimitIsWithheldEvenNextToAdvanceTaxedEmployment(): void
    {
        $result = $this->calculate(
            TaxDeclarationStatus::NotSigned,
            $this->relationship('hpp', EmploymentRelationshipKind::Employment, 500_000),
            $this->relationship('dpp', EmploymentRelationshipKind::Dpp, 200_000),
        );

        self::assertSame(TaxCalculationStatus::Calculated, $result->status, implode(',', $result->issues));
        self::assertSame([TaxRegime::Advance, TaxRegime::Withholding], $this->regimes($result));
        self::assertSame(500_000, $result->advanceTax?->taxableIncomeMinorUnits);
        self::assertSame(30_000, $result->withholdingTaxMinorUnits);
    }

    /**
     * DPP 13 000 + zaměstnání malého rozsahu 1 000: DPP rozhodnou částku
     * písm. a) překročila, takže podle GFŘ D-59 „budou všechny příjmy plynoucí
     * poplatníkovi od téhož plátce daně zahrnuty do jednoho základu pro výpočet
     * zálohy“. Úhrn písm. b) je 14 000, tedy i ten 1 000 jde do zálohy.
     */
    public function testDppAboveItsLimitPullsOtherIncomeIntoAdvanceTax(): void
    {
        $result = $this->calculate(
            TaxDeclarationStatus::NotSigned,
            $this->relationship('dpp', EmploymentRelationshipKind::Dpp, 1_300_000),
            $this->relationship('small', EmploymentRelationshipKind::SmallScaleEmployment, 100_000),
        );

        self::assertSame(TaxCalculationStatus::Calculated, $result->status, implode(',', $result->issues));
        self::assertSame([TaxRegime::Advance, TaxRegime::Advance], $this->regimes($result));
        self::assertSame(1_400_000, $result->advanceTax?->taxableIncomeMinorUnits);
        self::assertSame(0, $result->withholdingTaxMinorUnits);
    }

    private function calculate(
        TaxDeclarationStatus $declaration,
        EmploymentRelationshipTaxInput ...$relationships,
    ): MonthlyEmploymentIncomeTaxResult {
        return (new MonthlyEmploymentIncomeTaxCalculator(
            new PayrollRulesetProvider([
                CzechPayrollRulesets2026::provider()
                    ->forDate(PayrollRulesetDomain::IncomeTax, '2026-08-31'),
            ]),
        ))->calculate(new MonthlyEmploymentIncomeTaxInput(
            calculationDate: '2026-08-31',
            employeeReference: 'synthetic-employee',
            relationships: array_values($relationships),
            declarations: [new TaxDeclarationEvidence(
                $declaration,
                '2026-01-01',
                null,
                'synthetic-declaration-evidence',
            )],
            residence: new TaxResidenceEvidence(
                TaxResidence::CzechResident,
                '2026-01-01',
                null,
                'synthetic-residence-evidence',
            ),
        ));
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

    /** @return list<TaxRegime> */
    private function regimes(MonthlyEmploymentIncomeTaxResult $result): array
    {
        return array_map(
            static fn ($relationship): TaxRegime => $relationship->regime,
            $result->relationships,
        );
    }
}
