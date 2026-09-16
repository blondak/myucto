<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Time\Surcharge;

use MyInvoice\Service\Payroll\Ruleset\CzechPayrollRulesets2026;
use MyInvoice\Service\Payroll\Time\Surcharge\PayrollSurchargeCompensationMode;
use MyInvoice\Service\Payroll\Time\Surcharge\PayrollSurchargeKind;
use MyInvoice\Service\Payroll\Time\Surcharge\PayrollSurchargePolicy;
use MyInvoice\Service\Payroll\Time\Surcharge\PayrollSurchargeRuleset;
use PHPUnit\Framework\TestCase;

/**
 * Firemní výchozí sazby příplatků a jejich dědění (migrace 1846).
 *
 * Zásada na vztahu přebíjí firemní výchozí, firemní výchozí přebíjí zákonné
 * minimum. Odkud sazba přišla, musí být na zásadě poznat — bez toho účetní
 * u příplatku nezjistí důvod.
 */
final class PayrollEmployerSurchargeDefaultTest extends TestCase
{
    public function testEmployerDefaultCarriesItsOwnSource(): void
    {
        $policy = PayrollSurchargePolicy::employerDefault(
            [PayrollSurchargeKind::Overtime->value => 3_000],
            [],
            $this->ruleset(),
        );

        self::assertSame(PayrollSurchargePolicy::SOURCE_EMPLOYER, $policy->source);
        self::assertSame(3_000, $policy->agreedRateBasisPoints(PayrollSurchargeKind::Overtime));
        self::assertTrue($policy->hasAnyAgreedRate());
    }

    /** Sjednání na vztahu je proti firemnímu výchozímu stavu výjimka — a vyhrává. */
    public function testEmploymentAgreementOverridesTheEmployerDefault(): void
    {
        $employer = PayrollSurchargePolicy::employerDefault(
            [PayrollSurchargeKind::Overtime->value => 3_000],
            [],
            $this->ruleset(),
        );
        $employment = PayrollSurchargePolicy::agreed(
            PayrollSurchargeCompensationMode::Surcharge,
            PayrollSurchargeCompensationMode::Surcharge,
            null,
            [PayrollSurchargeKind::Overtime->value => 4_000],
            $this->ruleset(),
        );

        self::assertSame(3_000, $employer->agreedRateBasisPoints(PayrollSurchargeKind::Overtime));
        self::assertSame(4_000, $employment->agreedRateBasisPoints(PayrollSurchargeKind::Overtime));
        self::assertSame(PayrollSurchargePolicy::SOURCE_EMPLOYMENT, $employment->source);
    }

    /** Firemní výchozí sazba jde zadat i pevnou částkou na hodinu. */
    public function testEmployerDefaultSupportsFixedHourlyAmount(): void
    {
        $policy = PayrollSurchargePolicy::employerDefault(
            [],
            [PayrollSurchargeKind::Weekend->value => 4_200],
            $this->ruleset(),
        );

        self::assertSame(4_200, $policy->agreedFixedHourlyMinor(PayrollSurchargeKind::Weekend));
        self::assertNull($policy->agreedRateBasisPoints(PayrollSurchargeKind::Weekend));
    }

    /** Procento a pevná částka se vylučují i na firemní úrovni. */
    public function testEmployerDefaultRefusesBothFormsForOneKind(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/buď procentem, nebo pevnou částkou/');

        PayrollSurchargePolicy::employerDefault(
            [PayrollSurchargeKind::Weekend->value => 2_000],
            [PayrollSurchargeKind::Weekend->value => 4_200],
            $this->ruleset(),
        );
    }

    /**
     * Kogentní podlaha platí i pro firemní výchozí sazbu: § 114 nedovolí
     * sjednat míň než 25 %, ať se to zadá kdekoli.
     */
    public function testEmployerDefaultCannotUndercutACogentMinimum(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/nesmí být nižší/');

        PayrollSurchargePolicy::employerDefault(
            [PayrollSurchargeKind::Overtime->value => 2_499],
            [],
            $this->ruleset(),
        );
    }

    /**
     * Prázdná firemní zásada NENÍ sjednání: volající ji podle `hasAnyAgreedRate()`
     * pozná a dosadí zákonný výchozí stav, aby se na pásce neobjevila „firemní
     * zásada" tam, kde ve skutečnosti platí zákon.
     */
    public function testEmptyEmployerDefaultIsRecognisableAsNothingAgreed(): void
    {
        $policy = PayrollSurchargePolicy::employerDefault([], [], $this->ruleset());

        self::assertFalse($policy->hasAnyAgreedRate());
    }

    /**
     * Režimy odměnění zůstávají zákonné. Jestli se za přesčas dává příplatek
     * nebo náhradní volno (§ 114 odst. 3, § 115 odst. 1), se sjednává
     * s konkrétním člověkem — plošně to firma nastavit nemůže.
     */
    public function testEmployerDefaultKeepsStatutoryCompensationModes(): void
    {
        $policy = PayrollSurchargePolicy::employerDefault(
            [PayrollSurchargeKind::Night->value => 1_500],
            [],
            $this->ruleset(),
        );
        $statutory = PayrollSurchargePolicy::statutoryDefault();

        self::assertSame(
            $statutory->mode(PayrollSurchargeKind::Overtime),
            $policy->mode(PayrollSurchargeKind::Overtime),
        );
        self::assertSame(
            $statutory->mode(PayrollSurchargeKind::Holiday),
            $policy->mode(PayrollSurchargeKind::Holiday),
        );
    }

    /** Dosavadní sjednání na vztahu se chová přesně jako dřív. */
    public function testEmploymentAgreementIsUnchangedByTheNewLevel(): void
    {
        $policy = PayrollSurchargePolicy::agreed(
            PayrollSurchargeCompensationMode::Surcharge,
            PayrollSurchargeCompensationMode::Surcharge,
            2,
            [PayrollSurchargeKind::Night->value => 2_000],
            $this->ruleset(),
        );

        self::assertSame(PayrollSurchargePolicy::SOURCE_EMPLOYMENT, $policy->source);
        self::assertFalse($policy->isStatutoryDefault);
        self::assertSame(2, $policy->difficultEnvironmentFactors);
        self::assertSame(
            '0.2',
            $policy->effectiveRate(PayrollSurchargeKind::Night, $this->ruleset())['rate']
                ->toCanonicalString(),
        );
    }

    /** Zákonný výchozí stav nese svůj vlastní zdroj. */
    public function testStatutoryDefaultIsMarkedAsStatutory(): void
    {
        $policy = PayrollSurchargePolicy::statutoryDefault();

        self::assertSame(PayrollSurchargePolicy::SOURCE_STATUTORY, $policy->source);
        self::assertFalse($policy->hasAnyAgreedRate());
    }

    private function ruleset(): PayrollSurchargeRuleset
    {
        return PayrollSurchargeRuleset::forDate(
            CzechPayrollRulesets2026::provider(),
            '2026-06-01',
        );
    }
}
