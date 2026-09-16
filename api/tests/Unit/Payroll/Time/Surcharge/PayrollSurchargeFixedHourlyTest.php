<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Time\Surcharge;

use InvalidArgumentException;
use MyInvoice\Service\Payroll\Ruleset\CzechPayrollRulesets2026;
use MyInvoice\Service\Payroll\Time\Surcharge\PayrollSurchargeCalculator;
use MyInvoice\Service\Payroll\Time\Surcharge\PayrollSurchargeCompensationMode;
use MyInvoice\Service\Payroll\Time\Surcharge\PayrollSurchargeKind;
use MyInvoice\Service\Payroll\Time\Surcharge\PayrollSurchargePolicy;
use MyInvoice\Service\Payroll\Time\Surcharge\PayrollSurchargeResult;
use MyInvoice\Service\Payroll\Time\Surcharge\PayrollSurchargeRuleset;
use MyInvoice\Service\Payroll\Time\Surcharge\PayrollSurchargeSegment;
use PHPUnit\Framework\TestCase;

/**
 * Příplatek sjednaný PEVNOU ČÁSTKOU na hodinu (§ 114 až § 118, migrace 1845).
 *
 * Hodinový průměr je 500 Kč (50 000 haléřů), aby zákonná minima vycházela
 * zpaměti: přesčas 125 Kč/h, noční a víkend 50 Kč/h.
 */
final class PayrollSurchargeFixedHourlyTest extends TestCase
{
    private const AVERAGE_HOURLY_MINOR = 50_000;

    /** Sjednaná pevná částka se násobí odpracovanými hodinami, nic víc. */
    public function testFixedHourlyAmountIsMultipliedByTheHoursWorked(): void
    {
        $result = $this->calculate(
            [PayrollSurchargeKind::Weekend->value => 4_200],
            [new PayrollSurchargeSegment(PayrollSurchargeKind::Weekend, '2026-06-06', 480)],
        );

        // 42 Kč/h × 8 h = 336 Kč. § 118 nižší sjednání dovoluje, takže se ctí.
        self::assertSame(33_600, $result->amountFor(PayrollSurchargeKind::Weekend));
        $line = $result->lineFor(PayrollSurchargeKind::Weekend);
        self::assertNotNull($line);
        self::assertSame(4_200, $line->agreedFixedHourlyMinor);
        self::assertSame(4_200, $line->appliedFixedHourlyMinor);
    }

    /**
     * Sjednaná částka NAD zákonným minimem se použije beze změny a nic se
     * nehlásí — je to prostě lepší podmínka, než jakou žádá zákon.
     */
    public function testFixedAmountAboveTheStatutoryMinimumIsPaidAsAgreed(): void
    {
        // Zákonné minimum přesčasu je 25 % z 500 Kč, tedy 125 Kč/h.
        $result = $this->calculate(
            [PayrollSurchargeKind::Overtime->value => 15_000],
            [new PayrollSurchargeSegment(PayrollSurchargeKind::Overtime, '2026-06-15', 480)],
        );

        // 150 Kč/h × 8 h = 1 200 Kč.
        self::assertSame(120_000, $result->amountFor(PayrollSurchargeKind::Overtime));
        $line = $result->lineFor(PayrollSurchargeKind::Overtime);
        self::assertNotNull($line);
        self::assertFalse($line->belowStatutory);
        self::assertSame(15_000, $line->appliedFixedHourlyMinor);
    }

    /**
     * Sjednaná částka POD kogentním minimem se nesmí vyplatit.
     *
     * Tohle je jádro celé úpravy: u § 114 je „nejméně" kogentní, takže nižší
     * sjednání je v tom rozsahu neplatné a vyplatit ho by byl nedoplatek.
     * Výpočet proto dosadí zákonné minimum a řádek to nese jako nález — tiché
     * dorovnání by účtárnu nechalo v domnění, že sjednání je v pořádku.
     */
    public function testFixedAmountBelowACogentMinimumIsRaisedAndFlagged(): void
    {
        // Klient platí přesčas 75 Kč/h, ale zákonné minimum je 125 Kč/h.
        $result = $this->calculate(
            [PayrollSurchargeKind::Overtime->value => 7_500],
            [new PayrollSurchargeSegment(PayrollSurchargeKind::Overtime, '2026-06-15', 480)],
        );

        // Vyplatí se 125 Kč/h × 8 h = 1 000 Kč, ne sjednaných 600 Kč.
        self::assertSame(100_000, $result->amountFor(PayrollSurchargeKind::Overtime));
        $line = $result->lineFor(PayrollSurchargeKind::Overtime);
        self::assertNotNull($line);
        self::assertTrue($line->belowStatutory);
        self::assertSame(7_500, $line->agreedFixedHourlyMinor);
        self::assertSame(12_500, $line->appliedFixedHourlyMinor);
    }

    /**
     * U § 116 a § 118 se nižší částka sjednat SMÍ, takže se vyplácí sjednaná —
     * ale příznak zůstává, aby účtárna viděla, že je pod zákonnou úrovní.
     */
    public function testNightMayPayLessThanTheStatutoryAmountButStillReportsIt(): void
    {
        // Zákonné minimum noční je 10 % z 500 Kč, tedy 50 Kč/h.
        $result = $this->calculate(
            [PayrollSurchargeKind::Night->value => 3_000],
            [new PayrollSurchargeSegment(PayrollSurchargeKind::Night, '2026-06-15', 480)],
        );

        // 30 Kč/h × 8 h = 240 Kč — sjednané číslo, ne zákonné.
        self::assertSame(24_000, $result->amountFor(PayrollSurchargeKind::Night));
        $line = $result->lineFor(PayrollSurchargeKind::Night);
        self::assertNotNull($line);
        self::assertTrue($line->belowStatutory);
        self::assertSame(3_000, $line->appliedFixedHourlyMinor);
    }

    /**
     * Zaokrouhluje se JEDNOU za měsíc z jednoho zlomku, stejně jako u procenta.
     * Dvacet zápisů po sedmi minutách musí dát totéž co jeden na 140 minut.
     */
    public function testRoundingHappensOnceOverTheWholeMonth(): void
    {
        $segments = [];
        for ($day = 1; $day <= 20; $day++) {
            $segments[] = new PayrollSurchargeSegment(
                PayrollSurchargeKind::Weekend,
                sprintf('2026-06-%02d', $day),
                7,
            );
        }
        $agreed = [PayrollSurchargeKind::Weekend->value => 3_333];

        $split = $this->calculate($agreed, $segments);
        $single = $this->calculate(
            $agreed,
            [new PayrollSurchargeSegment(PayrollSurchargeKind::Weekend, '2026-06-01', 140)],
        );

        // 3 333 × 140 / 60 = 7 777 haléřů.
        self::assertSame(7_777, $split->amountFor(PayrollSurchargeKind::Weekend));
        self::assertSame(
            $single->amountFor(PayrollSurchargeKind::Weekend),
            $split->amountFor(PayrollSurchargeKind::Weekend),
        );
    }

    /** § 117 stojí na minimální mzdě, takže i podlaha pevné částky je z ní. */
    public function testDifficultEnvironmentMeasuresTheFixedAmountAgainstTheMinimumWage(): void
    {
        // Základní sazba minimální mzdy 2026 je 134,40 Kč/h, minimum tedy 13,44 Kč/h.
        $result = $this->calculate(
            [PayrollSurchargeKind::DifficultEnvironment->value => 2_000],
            [new PayrollSurchargeSegment(
                PayrollSurchargeKind::DifficultEnvironment,
                '2026-06-15',
                480,
            )],
        );

        // 20 Kč/h × 8 h = 160 Kč; sjednané je nad minimem, takže se nedorovnává.
        self::assertSame(16_000, $result->amountFor(PayrollSurchargeKind::DifficultEnvironment));
        self::assertFalse(
            $result->lineFor(PayrollSurchargeKind::DifficultEnvironment)?->belowStatutory,
        );
    }

    /**
     * Přepnutí z procenta na pevnou částku je NOVÁ VERZE zásady, ne přepis.
     *
     * Verze se sjednáním v procentech musí po přepnutí dál počítat procentem,
     * jinak by se zpětně změnila už hotová mzda. Test to drží tak, že tutéž
     * dobu spočítá podle obou verzí a čeká dvě různé částky.
     */
    public function testSwitchingFromPercentToFixedAppliesOnlyFromTheNewVersion(): void
    {
        $segments = [
            new PayrollSurchargeSegment(PayrollSurchargeKind::Weekend, '2026-06-06', 480),
        ];

        // Stará verze: 20 % z 500 Kč = 100 Kč/h × 8 h = 800 Kč.
        $byPercent = $this->calculateWith(
            PayrollSurchargePolicy::agreed(
                PayrollSurchargeCompensationMode::Surcharge,
                PayrollSurchargeCompensationMode::Surcharge,
                null,
                [PayrollSurchargeKind::Weekend->value => 2_000],
                $this->ruleset(),
            ),
            $segments,
        );
        self::assertSame(80_000, $byPercent->amountFor(PayrollSurchargeKind::Weekend));
        self::assertNull(
            $byPercent->lineFor(PayrollSurchargeKind::Weekend)?->agreedFixedHourlyMinor,
        );

        // Nová verze: 42 Kč/h × 8 h = 336 Kč.
        $byFixed = $this->calculate(
            [PayrollSurchargeKind::Weekend->value => 4_200],
            $segments,
        );
        self::assertSame(33_600, $byFixed->amountFor(PayrollSurchargeKind::Weekend));
    }

    /** Sjednat obojí u téhož druhu nejde — výpočet by si musel vybrat. */
    public function testPercentAndFixedAmountCannotBeAgreedForTheSameKind(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/buď procentem, nebo pevnou částkou/');

        PayrollSurchargePolicy::agreed(
            PayrollSurchargeCompensationMode::Surcharge,
            PayrollSurchargeCompensationMode::Surcharge,
            null,
            [PayrollSurchargeKind::Weekend->value => 2_000],
            $this->ruleset(),
            [PayrollSurchargeKind::Weekend->value => 4_200],
        );
    }

    /**
     * Pevná částka u JEDNOHO druhu nesmí sáhnout na ostatní — § 114 procentem
     * a § 118 pevnou částkou je běžná kombinace.
     */
    public function testKindsKeepTheirOwnFormOfAgreement(): void
    {
        $policy = PayrollSurchargePolicy::agreed(
            PayrollSurchargeCompensationMode::Surcharge,
            PayrollSurchargeCompensationMode::Surcharge,
            null,
            [PayrollSurchargeKind::Overtime->value => 3_000],
            $this->ruleset(),
            [PayrollSurchargeKind::Weekend->value => 4_200],
        );

        self::assertSame(3_000, $policy->agreedRateBasisPoints(PayrollSurchargeKind::Overtime));
        self::assertNull($policy->agreedFixedHourlyMinor(PayrollSurchargeKind::Overtime));
        self::assertSame(4_200, $policy->agreedFixedHourlyMinor(PayrollSurchargeKind::Weekend));
        self::assertNull($policy->agreedRateBasisPoints(PayrollSurchargeKind::Weekend));
    }

    /** Dosavadní procentní sjednání se nesmí chovat jinak než dřív. */
    public function testPolicyWithoutFixedAmountsIsUnchanged(): void
    {
        $policy = PayrollSurchargePolicy::agreed(
            PayrollSurchargeCompensationMode::Surcharge,
            PayrollSurchargeCompensationMode::Surcharge,
            null,
            [PayrollSurchargeKind::Night->value => 2_000],
            $this->ruleset(),
        );

        foreach (PayrollSurchargeKind::all() as $kind) {
            self::assertNull($policy->agreedFixedHourlyMinor($kind), $kind->value);
        }
        $result = $this->calculateWith($policy, [
            new PayrollSurchargeSegment(PayrollSurchargeKind::Night, '2026-06-15', 480),
        ]);
        // 20 % z 500 Kč × 8 h = 800 Kč — táž hodnota jako před migrací 1845.
        self::assertSame(80_000, $result->amountFor(PayrollSurchargeKind::Night));
    }

    public function testStatutoryDefaultAgreesNoFixedAmounts(): void
    {
        $policy = PayrollSurchargePolicy::statutoryDefault();

        foreach (PayrollSurchargeKind::all() as $kind) {
            self::assertNull($policy->agreedFixedHourlyMinor($kind), $kind->value);
        }
    }

    public function testFixedAmountMustBePositive(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/musí být kladná/');

        PayrollSurchargePolicy::agreed(
            PayrollSurchargeCompensationMode::Surcharge,
            PayrollSurchargeCompensationMode::Surcharge,
            null,
            [],
            $this->ruleset(),
            [PayrollSurchargeKind::Weekend->value => 0],
        );
    }

    /**
     * @param array<string,int> $fixedHourlyMinor
     * @param list<PayrollSurchargeSegment> $segments
     */
    private function calculate(array $fixedHourlyMinor, array $segments): PayrollSurchargeResult
    {
        return $this->calculateWith(
            PayrollSurchargePolicy::agreed(
                PayrollSurchargeCompensationMode::Surcharge,
                PayrollSurchargeCompensationMode::Surcharge,
                2,
                [],
                $this->ruleset(),
                $fixedHourlyMinor,
            ),
            $segments,
        );
    }

    /** @param list<PayrollSurchargeSegment> $segments */
    private function calculateWith(
        PayrollSurchargePolicy $policy,
        array $segments,
    ): PayrollSurchargeResult {
        return (new PayrollSurchargeCalculator(CzechPayrollRulesets2026::provider()))->calculate(
            '2026-06-01',
            self::AVERAGE_HOURLY_MINOR,
            $policy,
            $segments,
        );
    }

    private function ruleset(): PayrollSurchargeRuleset
    {
        return PayrollSurchargeRuleset::forDate(
            CzechPayrollRulesets2026::provider(),
            '2026-06-01',
        );
    }
}
