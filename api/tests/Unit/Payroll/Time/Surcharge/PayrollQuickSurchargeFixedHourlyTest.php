<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Time\Surcharge;

use MyInvoice\Service\Payroll\Ruleset\CzechPayrollRulesets2026;
use MyInvoice\Service\Payroll\Time\Surcharge\PayrollQuickSurchargeCalculator;
use MyInvoice\Service\Payroll\Time\Surcharge\PayrollSurchargeCompensationMode;
use MyInvoice\Service\Payroll\Time\Surcharge\PayrollSurchargeKind;
use MyInvoice\Service\Payroll\Time\Surcharge\PayrollSurchargePolicy;
use MyInvoice\Service\Payroll\Time\Surcharge\PayrollSurchargeRuleset;
use PHPUnit\Framework\TestCase;

/**
 * Pevná částka za hodinu v RYCHLÉM MĚSÍČNÍM VSTUPU (§ 115 až § 118).
 *
 * Proč vlastní sada: rychlé zadání má vlastní aritmetiku nad milihodinami
 * (jmenovatel 1 000 místo 60), takže pevnou částku musí umět samostatně. Kdyby
 * ji uměla jen docházka, počítal by se týž nárok dvěma čísly podle toho, kterou
 * obrazovkou se hodiny zadaly — a to je přesně ta vada, kvůli které rychlé
 * zadání sjednanou zásadu vůbec čte.
 */
final class PayrollQuickSurchargeFixedHourlyTest extends TestCase
{
    private const AVERAGE_HOURLY_MINOR = 50_000;

    /** Osm hodin v milihodinách. */
    private const EIGHT_HOURS_MILLI = 8_000;

    public function testFixedAmountIsMultipliedByTheHoursEntered(): void
    {
        // 42 Kč/h × 8 h = 336 Kč. § 118 nižší sjednání dovoluje, takže se ctí.
        $result = $this->calculate(PayrollSurchargeKind::Weekend, 4_200);

        self::assertSame(33_600, $result['amount_minor']);
        self::assertSame(4_200, $result['source']['agreed_fixed_hourly_minor']);
        self::assertSame(4_200, $result['source']['applied_fixed_hourly_minor']);
    }

    public function testFixedAmountAboveTheStatutoryMinimumIsPaidAsAgreed(): void
    {
        // Zákonné minimum svátku je 100 % z 500 Kč, tedy 500 Kč/h.
        $result = $this->calculate(PayrollSurchargeKind::Holiday, 60_000);

        // 600 Kč/h × 8 h = 4 800 Kč.
        self::assertSame(480_000, $result['amount_minor']);
        self::assertFalse($result['source']['below_statutory']);
    }

    /**
     * § 115 má kogentní „nejméně", takže nižší sjednaná částka se dorovnává.
     * Rychlé zadání se tu musí chovat stejně jako docházka — jinak by se vyplatilo
     * míň podle toho, kterou obrazovkou se hodiny zadaly.
     */
    public function testFixedAmountBelowACogentMinimumIsRaisedAndFlagged(): void
    {
        $result = $this->calculate(PayrollSurchargeKind::Holiday, 30_000);

        // Vyplatí se zákonných 500 Kč/h × 8 h = 4 000 Kč, ne sjednaných 2 400 Kč.
        self::assertSame(400_000, $result['amount_minor']);
        self::assertTrue($result['source']['below_statutory']);
        self::assertSame(30_000, $result['source']['agreed_fixed_hourly_minor']);
        self::assertSame(50_000, $result['source']['applied_fixed_hourly_minor']);
    }

    /** § 116 nižší sjednání dovoluje, takže se vyplácí sjednané, jen se hlásí. */
    public function testNightMayPayLessThanTheStatutoryAmount(): void
    {
        // Zákonné minimum noční je 10 % z 500 Kč, tedy 50 Kč/h.
        $result = $this->calculate(PayrollSurchargeKind::Night, 3_000);

        // 30 Kč/h × 8 h = 240 Kč.
        self::assertSame(24_000, $result['amount_minor']);
        self::assertTrue($result['source']['below_statutory']);
        self::assertSame(3_000, $result['source']['applied_fixed_hourly_minor']);
    }

    /** Dosavadní procentní sjednání se nesmí chovat jinak než dřív. */
    public function testPercentAgreementIsUnchanged(): void
    {
        $policy = PayrollSurchargePolicy::agreed(
            PayrollSurchargeCompensationMode::Surcharge,
            PayrollSurchargeCompensationMode::Surcharge,
            2,
            [PayrollSurchargeKind::Night->value => 2_000],
            $this->ruleset(),
        );
        $result = $this->calculateWith(PayrollSurchargeKind::Night, $policy);

        // 20 % z 500 Kč × 8 h = 800 Kč.
        self::assertSame(80_000, $result['amount_minor']);
        self::assertNull($result['source']['agreed_fixed_hourly_minor']);
        self::assertFalse($result['source']['below_statutory']);
    }

    /** § 117 se počítá z minimální mzdy a příplatek náleží za každý vliv. */
    public function testDifficultEnvironmentMultipliesTheFixedAmountByEachFactor(): void
    {
        $result = $this->calculate(PayrollSurchargeKind::DifficultEnvironment, 2_000);

        // 20 Kč/h × 8 h × 2 vlivy = 320 Kč.
        self::assertSame(32_000, $result['amount_minor']);
        self::assertSame(2, $result['source']['difficulty_factors']);
    }

    /** Formulář musí vědět, že se počítá z částky, ne ze sazby. */
    public function testAvailabilityReportsTheAgreedFixedAmount(): void
    {
        $policy = $this->fixedPolicy(PayrollSurchargeKind::Weekend, 4_200);
        $availability = $this->calculator()->availability(
            PayrollSurchargeKind::Weekend,
            '2026-06-01',
            $policy,
            $this->ruleset(),
            self::AVERAGE_HOURLY_MINOR,
        );

        self::assertTrue($availability['available']);
        self::assertSame(4_200, $availability['agreed_fixed_hourly_minor']);
    }

    /**
     * Zaokrouhluje se jednou z jednoho zlomku. Milihodina je 0,06 minuty, takže
     * neúplné hodiny jsou tu běžné a dvojí zaokrouhlení by se sečetlo.
     */
    public function testRoundingHappensOnceOverTheWholeMonth(): void
    {
        $policy = $this->fixedPolicy(PayrollSurchargeKind::Weekend, 3_333);
        $result = $this->calculator()->calculate(
            PayrollSurchargeKind::Weekend,
            '2026-06-01',
            $policy,
            $this->ruleset(),
            self::AVERAGE_HOURLY_MINOR,
            2_333,
            null,
        );

        // 3 333 × 2 333 / 1 000 = 7 775,9 → 7 776 haléřů.
        self::assertSame(7_776, $result['amount_minor']);
        self::assertSame(1_000, $result['source']['unrounded_denominator']);
    }

    /** @return array{amount_minor:int, source:array<string,mixed>} */
    private function calculate(PayrollSurchargeKind $kind, int $fixedHourlyMinor): array
    {
        return $this->calculateWith($kind, $this->fixedPolicy($kind, $fixedHourlyMinor));
    }

    /** @return array{amount_minor:int, source:array<string,mixed>} */
    private function calculateWith(
        PayrollSurchargeKind $kind,
        PayrollSurchargePolicy $policy,
    ): array {
        return $this->calculator()->calculate(
            $kind,
            '2026-06-01',
            $policy,
            $this->ruleset(),
            self::AVERAGE_HOURLY_MINOR,
            self::EIGHT_HOURS_MILLI,
            null,
        );
    }

    private function fixedPolicy(
        PayrollSurchargeKind $kind,
        int $fixedHourlyMinor,
    ): PayrollSurchargePolicy {
        return PayrollSurchargePolicy::agreed(
            PayrollSurchargeCompensationMode::Surcharge,
            PayrollSurchargeCompensationMode::Surcharge,
            2,
            [],
            $this->ruleset(),
            [$kind->value => $fixedHourlyMinor],
        );
    }

    private function calculator(): PayrollQuickSurchargeCalculator
    {
        return new PayrollQuickSurchargeCalculator(CzechPayrollRulesets2026::provider());
    }

    private function ruleset(): PayrollSurchargeRuleset
    {
        return PayrollSurchargeRuleset::forDate(
            CzechPayrollRulesets2026::provider(),
            '2026-06-01',
        );
    }
}
