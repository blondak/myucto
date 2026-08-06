<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\IncomeTax;

use MyInvoice\Service\Payroll\IncomeTax\EmploymentRelationshipKind;
use MyInvoice\Service\Payroll\IncomeTax\EmploymentRelationshipTaxInput;
use MyInvoice\Service\Payroll\IncomeTax\IncomeTaxComponent;
use MyInvoice\Service\Payroll\IncomeTax\MonthlyEmploymentIncomeTaxCalculator;
use MyInvoice\Service\Payroll\IncomeTax\MonthlyEmploymentIncomeTaxInput;
use MyInvoice\Service\Payroll\IncomeTax\OtherWithholdingEligibility;
use MyInvoice\Service\Payroll\IncomeTax\TaxCalculationStatus;
use MyInvoice\Service\Payroll\IncomeTax\TaxCreditClaim;
use MyInvoice\Service\Payroll\IncomeTax\TaxCreditKind;
use MyInvoice\Service\Payroll\IncomeTax\TaxDeclarationEvidence;
use MyInvoice\Service\Payroll\IncomeTax\TaxDeclarationStatus;
use MyInvoice\Service\Payroll\IncomeTax\TaxEvidenceStatus;
use MyInvoice\Service\Payroll\IncomeTax\TaxRegime;
use MyInvoice\Service\Payroll\IncomeTax\TaxResidence;
use MyInvoice\Service\Payroll\IncomeTax\TaxResidenceEvidence;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetException;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetProvider;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetRegistry;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetVersion;
use MyInvoice\Service\Payroll\Ruleset\PayrollRuleValue;
use PHPUnit\Framework\TestCase;

/**
 * MZ-02-W08 — akceptace: administrátorská změna daňového parametru se projeví
 * ve výpočtu.
 *
 * Do MZ-02-W08 tenhle scénář skončil výjimkou „Income tax ruleset parameter …
 * does not match the domain policy." — `EmploymentIncomeTaxPolicy2026` držela
 * druhou kopii hodnot a porovnávala ruleset proti kódu. Administrace rulesetů
 * (MZ-02-W07) tím byla u daně mrtvá.
 *
 * Override se skládá {@see PayrollRulesetRegistry::merge()}, což je tatáž cesta,
 * jakou registry čte řádek uložený administrační službou — jen bez databáze,
 * aby test zůstal deterministický na všech platformách.
 */
final class EmploymentIncomeTaxRulesetOverrideTest extends TestCase
{
    private const RULESET_ID = 'cz-payroll-2026.income-tax.v1';

    public function testAdministratorRateAndCreditOverrideChangesTheCalculatedAdvanceTax(): void
    {
        $baseline = $this->calculate($this->calculator([]), 4_000_000);
        self::assertSame(TaxCalculationStatus::Calculated, $baseline->status);
        self::assertSame(600_000, $baseline->advanceTax?->taxBeforeCreditsMinorUnits);
        self::assertSame(257_000, $baseline->advanceTax?->nonRefundableCreditsMinorUnits);
        self::assertSame(343_000, $baseline->advanceTax?->taxAfterCreditsMinorUnits);

        $overridden = $this->calculate(
            $this->calculator([
                'advance.low_rate' => ['type' => 'decimal_rate', 'value' => '0.16'],
                'credit.taxpayer.monthly' => ['type' => 'money_minor', 'value' => 300_000],
            ]),
            4_000_000,
        );

        self::assertSame(TaxCalculationStatus::Calculated, $overridden->status);
        self::assertSame(640_000, $overridden->advanceTax?->taxBeforeCreditsMinorUnits);
        self::assertSame(300_000, $overridden->advanceTax?->nonRefundableCreditsMinorUnits);
        self::assertSame(340_000, $overridden->advanceTax?->taxAfterCreditsMinorUnits);
        self::assertNotSame($baseline->rulesetHash, $overridden->rulesetHash);
    }

    public function testAdministratorOverrideMovesTheWithholdingBoundaryAndRate(): void
    {
        $calculator = $this->calculator([
            'dpp.withholding.maximum' => ['type' => 'money_minor', 'value' => 2_000_000],
            'withholding.rate' => ['type' => 'decimal_rate', 'value' => '0.2'],
        ]);

        $result = $calculator->calculate(new MonthlyEmploymentIncomeTaxInput(
            calculationDate: '2026-08-31',
            employeeReference: 'synthetic-employee',
            relationships: [new EmploymentRelationshipTaxInput(
                'dpp',
                'synthetic-payer',
                EmploymentRelationshipKind::Dpp,
                [new IncomeTaxComponent('synthetic-income', 1_500_000)],
                OtherWithholdingEligibility::Automatic,
            )],
            declarations: [$this->declaration(TaxDeclarationStatus::NotSigned)],
            residence: $this->residence(TaxResidence::CzechResident),
        ));

        // Vestavěná hranice 1 199 900 by tenhle vztah poslala do zálohy;
        // s administrátorskou hranicí 2 000 000 zůstává srážkový a daní se 20 %.
        self::assertSame(TaxCalculationStatus::Calculated, $result->status);
        self::assertSame(TaxRegime::Withholding, $result->relationships[0]->regime);
        self::assertSame(1_500_000, $result->withholdingBaseMinorUnits);
        self::assertSame(300_000, $result->withholdingTaxMinorUnits);
    }

    public function testAdministratorOverrideOfTheChildCreditChangesTheTaxBonus(): void
    {
        $calculator = $this->calculator([
            'credit.child.first.monthly' => ['type' => 'money_minor', 'value' => 200_000],
        ]);

        $result = $calculator->calculate(new MonthlyEmploymentIncomeTaxInput(
            calculationDate: '2026-08-31',
            employeeReference: 'synthetic-employee',
            relationships: [$this->relationship(1_120_000)],
            declarations: [$this->declaration(TaxDeclarationStatus::Signed)],
            residence: $this->residence(TaxResidence::CzechResident),
            creditClaims: [$this->credit()],
            childClaims: [new \MyInvoice\Service\Payroll\IncomeTax\TaxChildClaim(
                'synthetic-child',
                1,
                false,
                '2026-01-01',
                null,
                TaxEvidenceStatus::Verified,
                true,
                true,
                'synthetic-child-evidence',
            )],
        ));

        self::assertSame(TaxCalculationStatus::Calculated, $result->status);
        self::assertSame(200_000, $result->claimedChildCreditMinorUnits);
        self::assertSame(200_000, $result->advanceTax?->taxBonusMinorUnits);
    }

    public function testMissingRequiredParameterStopsTheCalculationWithAnActionableMessage(): void
    {
        $calculator = new MonthlyEmploymentIncomeTaxCalculator(
            new PayrollRulesetProvider([
                $this->withoutParameter('credit.ztp_p.monthly'),
            ]),
        );

        $this->expectException(PayrollRulesetException::class);
        $this->expectExceptionMessage('credit.ztp_p.monthly (chybí)');
        $this->expectExceptionMessage('doplň je v administraci mzdových rulesetů');

        $this->calculate($calculator, 4_000_000);
    }

    public function testParameterMarkedForManualReviewIsNeverReplacedByACodeDefault(): void
    {
        $calculator = $this->calculator([
            'credit.taxpayer.monthly' => [
                'type' => 'manual_review',
                'value' => 'Sleva na poplatníka pro 2027 zatím není potvrzená.',
            ],
        ]);

        $this->expectException(PayrollRulesetException::class);
        $this->expectExceptionMessage('credit.taxpayer.monthly (vyžaduje ruční kontrolu');

        $this->calculate($calculator, 4_000_000);
    }

    public function testParameterOfTheWrongTypeStopsTheCalculation(): void
    {
        $calculator = $this->calculator([
            'advance.high_threshold.monthly' => ['type' => 'integer', 'value' => 14_690_100],
        ]);

        $this->expectException(PayrollRulesetException::class);
        $this->expectExceptionMessage('advance.high_threshold.monthly (má typ integer');

        $this->calculate($calculator, 4_000_000);
    }

    /** @param array<string, array<string, mixed>> $parameters */
    private function calculator(array $parameters): MonthlyEmploymentIncomeTaxCalculator
    {
        return new MonthlyEmploymentIncomeTaxCalculator(
            new PayrollRulesetProvider([$this->overridden($parameters)]),
        );
    }

    /**
     * Sloučení defaultu z kódu s DB overridem přesně tak, jak ho po uložení
     * administrační službou čte {@see PayrollRulesetRegistry}.
     *
     * @param array<string, array<string, mixed>> $parameters
     */
    private function overridden(array $parameters): PayrollRulesetVersion
    {
        $default = null;
        foreach (PayrollRulesetRegistry::defaults()->versions() as $version) {
            if ($version->id === self::RULESET_ID) {
                $default = $version;
            }
        }
        self::assertInstanceOf(PayrollRulesetVersion::class, $default);

        return PayrollRulesetRegistry::merge($default, [
            'ruleset_id' => self::RULESET_ID,
            'lifecycle' => 'active',
            'reason' => 'Syntetická administrátorská změna pro deterministický test.',
            'created_by' => 900_001,
            'updated_by' => 900_001,
            'reviewed_by' => 900_001,
            'reviewed_at' => '2026-08-04 00:00:00',
            'approved_by' => 900_002,
            'approved_at' => '2026-08-05 00:00:00',
            'data' => json_encode(
                ['parameters' => $parameters],
                JSON_THROW_ON_ERROR,
            ),
        ]);
    }

    private function withoutParameter(string $key): PayrollRulesetVersion
    {
        $version = $this->overridden([]);
        $parameters = $version->parameters;
        self::assertArrayHasKey($key, $parameters);
        unset($parameters[$key]);
        /** @var array<string, PayrollRuleValue> $parameters */

        return new PayrollRulesetVersion(
            $version->id,
            $version->version,
            $version->domain,
            $version->effectiveFrom,
            $version->effectiveTo,
            $version->lifecycle,
            $version->capability,
            $version->sources,
            $parameters,
            $version->approval,
            $version->technicalReview,
        );
    }

    private function calculate(
        MonthlyEmploymentIncomeTaxCalculator $calculator,
        int $amountMinorUnits,
    ): \MyInvoice\Service\Payroll\IncomeTax\MonthlyEmploymentIncomeTaxResult {
        return $calculator->calculate(new MonthlyEmploymentIncomeTaxInput(
            calculationDate: '2026-08-31',
            employeeReference: 'synthetic-employee',
            relationships: [$this->relationship($amountMinorUnits)],
            declarations: [$this->declaration(TaxDeclarationStatus::Signed)],
            residence: $this->residence(TaxResidence::CzechResident),
            creditClaims: [$this->credit()],
        ));
    }

    private function relationship(int $amountMinorUnits): EmploymentRelationshipTaxInput
    {
        return new EmploymentRelationshipTaxInput(
            'employment',
            'synthetic-payer',
            EmploymentRelationshipKind::Employment,
            [new IncomeTaxComponent('synthetic-income', $amountMinorUnits)],
            OtherWithholdingEligibility::Automatic,
        );
    }

    private function declaration(TaxDeclarationStatus $status): TaxDeclarationEvidence
    {
        return new TaxDeclarationEvidence(
            $status,
            '2026-01-01',
            null,
            'synthetic-declaration-evidence',
        );
    }

    private function residence(TaxResidence $residence): TaxResidenceEvidence
    {
        return new TaxResidenceEvidence(
            $residence,
            '2026-01-01',
            null,
            'synthetic-residence-evidence',
        );
    }

    private function credit(): TaxCreditClaim
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
