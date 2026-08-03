<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Component;

use MyInvoice\Service\Payroll\Calculation\Money;
use MyInvoice\Service\Payroll\Component\PayrollComponentDefinition;
use MyInvoice\Service\Payroll\Component\PayrollComponentFrequency;
use MyInvoice\Service\Payroll\Component\PayrollComponentInclusion;
use MyInvoice\Service\Payroll\Component\PayrollComponentKind;
use MyInvoice\Service\Payroll\Component\PayrollComponentTaxTreatment;
use MyInvoice\Service\Payroll\Component\PayrollComponentValueKind;
use PHPUnit\Framework\TestCase;

final class PayrollComponentDefinitionTest extends TestCase
{
    public function testMonetaryComponentKeepsIndependentClassifications(): void
    {
        $definition = $this->definition(
            tax: PayrollComponentTaxTreatment::EXEMPT,
            social: PayrollComponentInclusion::EXCLUDED,
            health: PayrollComponentInclusion::EXCLUDED,
            average: PayrollComponentInclusion::INCLUDED,
            enforcement: PayrollComponentInclusion::EXCLUDED,
            jmhz: PayrollComponentInclusion::INCLUDED,
        );

        $impact = $definition->impact(new Money(12_345));

        self::assertSame(12_345, $impact->cashPayable->minorUnits);
        self::assertSame(0, $impact->taxBase->minorUnits);
        self::assertSame(0, $impact->socialBase->minorUnits);
        self::assertSame(0, $impact->healthBase->minorUnits);
        self::assertSame(12_345, $impact->averageEarningBase->minorUnits);
        self::assertSame(0, $impact->enforcementBase->minorUnits);
        self::assertSame(12_345, $impact->jmhzAmount->minorUnits);
    }

    public function testNonMonetaryBenefitNeverInflatesCashPayout(): void
    {
        $definition = $this->definition(
            valueKind: PayrollComponentValueKind::NON_MONETARY,
        );

        $impact = $definition->impact(new Money(80_000));

        self::assertSame(0, $impact->cashPayable->minorUnits);
        self::assertSame(80_000, $impact->taxBase->minorUnits);
        self::assertSame(80_000, $impact->socialBase->minorUnits);
        self::assertSame(80_000, $impact->healthBase->minorUnits);
    }

    public function testNegativeCorrectionPreservesItsDirectionInEveryIncludedBase(): void
    {
        $impact = $this->definition()->impact(new Money(-500));

        self::assertSame(-500, $impact->cashPayable->minorUnits);
        self::assertSame(-500, $impact->taxBase->minorUnits);
        self::assertSame(-500, $impact->socialBase->minorUnits);
        self::assertSame(-500, $impact->healthBase->minorUnits);
    }

    public function testManualReviewCannotSilentlyEnterCalculation(): void
    {
        foreach ([
            $this->definition(tax: PayrollComponentTaxTreatment::MANUAL_REVIEW),
            $this->definition(social: PayrollComponentInclusion::MANUAL_REVIEW),
            $this->definition(jmhz: PayrollComponentInclusion::MANUAL_REVIEW),
        ] as $definition) {
            try {
                $definition->impact(new Money(100));
                self::fail('Neuzavřená klasifikace musí blokovat výpočet.');
            } catch (\DomainException $e) {
                self::assertStringContainsString('ruční posouzení', $e->getMessage());
            }
        }
    }

    public function testAnnualLimitIsAllowedOnlyForBenefit(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->definition(
            kind: PayrollComponentKind::BONUS,
            annualLimitMinor: 10_000,
        );
    }

    private function definition(
        PayrollComponentKind $kind = PayrollComponentKind::BENEFIT_MEAL,
        PayrollComponentValueKind $valueKind = PayrollComponentValueKind::MONETARY,
        PayrollComponentTaxTreatment $tax = PayrollComponentTaxTreatment::INCLUDED,
        PayrollComponentInclusion $social = PayrollComponentInclusion::INCLUDED,
        PayrollComponentInclusion $health = PayrollComponentInclusion::INCLUDED,
        PayrollComponentInclusion $average = PayrollComponentInclusion::INCLUDED,
        PayrollComponentInclusion $enforcement = PayrollComponentInclusion::INCLUDED,
        PayrollComponentInclusion $jmhz = PayrollComponentInclusion::INCLUDED,
        ?int $annualLimitMinor = null,
    ): PayrollComponentDefinition {
        return new PayrollComponentDefinition(
            code: 'SYNTHETIC',
            name: 'Syntetická mzdová složka',
            kind: $kind,
            valueKind: $valueKind,
            frequency: PayrollComponentFrequency::ONE_OFF,
            taxTreatment: $tax,
            socialTreatment: $social,
            healthTreatment: $health,
            averageEarningTreatment: $average,
            enforcementTreatment: $enforcement,
            jmhzTreatment: $jmhz,
            statisticsTreatment: PayrollComponentInclusion::INCLUDED,
            annualLimitMinor: $annualLimitMinor,
        );
    }
}
