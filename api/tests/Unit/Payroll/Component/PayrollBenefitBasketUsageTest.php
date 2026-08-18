<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Component;

use MyInvoice\Service\Payroll\Component\PayrollBenefitBasketUsage;
use MyInvoice\Service\Payroll\Component\PayrollBenefitExemptionBasket;
use PHPUnit\Framework\TestCase;

/**
 * Souhrnné čerpání koše za rok — doménová pravidla přehledu.
 *
 * Testuje se právě to, co odlišuje SOUHRN od rozpadu jednoho plnění: souhrn
 * nesmí nic dopočítávat. Nadlimitní část je zmrazený fakt ze schválení, chybějící
 * podklad se přiznává a chybějící limit se nenahrazuje odhadem.
 */
final class PayrollBenefitBasketUsageTest extends TestCase
{
    /** § 6 odst. 9 písm. d) bod 2 ZDP pro rok 2026 — polovina průměrné mzdy. */
    private const LEISURE_LIMIT_MINOR = 2_448_350;

    /**
     * Nerovnost je neostrá: úhrn přesně na limitu je celý osvobozený, zbývá nula
     * a stav není „překročeno".
     */
    public function testExactlyOnTheLimitIsNotExceeded(): void
    {
        $usage = $this->usage(
            usedMinor: self::LEISURE_LIMIT_MINOR,
            exemptMinor: self::LEISURE_LIMIT_MINOR,
            taxableMinor: 0,
        );

        self::assertSame(0, $usage->remainingMinor());
        self::assertSame('approaching', $usage->status());
        self::assertFalse($usage->splitDrift());
    }

    /** Překročení se pozná ze zmrazené nadlimitní části, ne z dnešního limitu. */
    public function testFrozenTaxablePartMakesTheRowExceeded(): void
    {
        $usage = $this->usage(
            usedMinor: self::LEISURE_LIMIT_MINOR + 100,
            exemptMinor: self::LEISURE_LIMIT_MINOR,
            taxableMinor: 100,
        );

        self::assertSame('exceeded', $usage->status());
        self::assertSame(0, $usage->remainingMinor());
    }

    /**
     * Nadlimitní část je fakt ze schválení, takže platí i bez dnešního limitu.
     * Kdyby ji chybějící ruleset přebil, přehled by zamlčel jediný jistý údaj.
     */
    public function testExceededOutranksMissingLimit(): void
    {
        $usage = $this->usage(
            usedMinor: 3_000_000,
            exemptMinor: 2_448_350,
            taxableMinor: 551_650,
            limitMinor: null,
        );

        self::assertSame('exceeded', $usage->status());
        self::assertNull($usage->remainingMinor());
    }

    /** Bez limitu se netvrdí ani „zbývá", ani „blíží se" — fail-closed. */
    public function testMissingLimitIsReportedInsteadOfGuessed(): void
    {
        $usage = $this->usage(
            usedMinor: 1_000_000,
            exemptMinor: 1_000_000,
            taxableMinor: 0,
            limitMinor: null,
        );

        self::assertSame('limit_unavailable', $usage->status());
        self::assertNull($usage->remainingMinor());
        self::assertFalse($usage->splitDrift());
    }

    /**
     * Vstup schválený dřív, než koše existovaly, rozpad nemá. Nedopočítává se —
     * řádek se označí jako neúplný.
     */
    public function testInputWithoutFrozenSplitMakesTheRowIncomplete(): void
    {
        $usage = $this->usage(
            usedMinor: 1_000_000,
            exemptMinor: 500_000,
            taxableMinor: 0,
            unfrozenCount: 1,
        );

        self::assertSame('incomplete', $usage->status());
        self::assertFalse(
            $usage->splitDrift(),
            'Chybějící podklad není důkaz změny limitu.',
        );
    }

    /** Práh upozornění je 80 % koše a je neostrý. */
    public function testApproachingStartsAtEightyPercent(): void
    {
        $justBelow = $this->usage(
            usedMinor: 1_958_679,
            exemptMinor: 1_958_679,
            taxableMinor: 0,
        );
        $atThreshold = $this->usage(
            usedMinor: 1_958_680,
            exemptMinor: 1_958_680,
            taxableMinor: 0,
        );

        self::assertSame('ok', $justBelow->status());
        self::assertSame('approaching', $atThreshold->status());
    }

    /**
     * Zmrazený součet se rozešel s dnešním limitem — limit se v rulesetu po
     * schválení změnil. Přehled to oznámí a dál ukazuje zmrazená čísla.
     */
    public function testDriftIsReportedWhenFrozenExemptDoesNotMatchTodaysLimit(): void
    {
        $usage = $this->usage(
            usedMinor: 2_500_000,
            exemptMinor: 2_000_000,
            taxableMinor: 0,
        );

        self::assertTrue($usage->splitDrift());
        self::assertSame(
            2_000_000,
            $usage->jsonSerialize()['exempt_minor'],
            'Zmrazená částka se dnešním limitem nepřepisuje.',
        );
    }

    /** Záporná oprava v koši rovnost rozbije očekávaně — drift by byl planý poplach. */
    public function testNegativeCorrectionSuppressesDrift(): void
    {
        $usage = $this->usage(
            usedMinor: 1_000_000,
            exemptMinor: 1_500_000,
            taxableMinor: 0,
            negativeCount: 1,
        );

        self::assertFalse($usage->splitDrift());
    }

    private function usage(
        int $usedMinor,
        int $exemptMinor,
        int $taxableMinor,
        ?int $limitMinor = self::LEISURE_LIMIT_MINOR,
        int $unfrozenCount = 0,
        int $negativeCount = 0,
    ): PayrollBenefitBasketUsage {
        return new PayrollBenefitBasketUsage(
            employeeId: 1,
            employeeName: 'Syntetická osoba',
            basket: PayrollBenefitExemptionBasket::NonCashLeisure,
            limitMinor: $limitMinor,
            usedMinor: $usedMinor,
            exemptMinor: $exemptMinor,
            taxableMinor: $taxableMinor,
            inputCount: 1,
            unfrozenCount: $unfrozenCount,
            negativeCount: $negativeCount,
        );
    }
}
