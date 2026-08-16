<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Submission;

use MyInvoice\Service\Submission\DeliveryBasis;
use MyInvoice\Service\Submission\DeliveryFictionCalculator;
use MyInvoice\Service\Submission\SubmissionLegalRules;
use PHPUnit\Framework\TestCase;

/**
 * Rozhodný den doručení do datové schránky — § 17 odst. 3 a 4 zák. 300/2008 Sb.
 *
 * ⚠️ Žádný test tady nesahá na síť. Vstupem jsou holá razítka, výstupem den.
 *
 * Co se hlídá, seřazeno podle toho, co by bolelo nejvíc:
 *   1. fikce nesmí vzniknout tam, kde ji zákon nezná (§ 18a — poštovní datová
 *      zpráva žádnou fikci nemá),
 *   2. chybějící údaj nesmí skončit jako „doručeno" ani jako „v pořádku",
 *   3. desátý den se počítá ode dne NÁSLEDUJÍCÍHO po dodání a nesmí padnout na
 *      sobotu, neděli ani svátek,
 *   4. dokud lhůta běží, není doručeno — a je to jiný stav než „nevíme".
 */
final class DeliveryFictionCalculatorTest extends TestCase
{
    private const DAYS = SubmissionLegalRules::STATUTORY_FICTION_DAYS;

    private DeliveryFictionCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new DeliveryFictionCalculator();
    }

    /** § 17 odst. 3 — přihlášení v lhůtě doručuje okamžikem přihlášení. */
    public function testLoginWithinPeriodDeliversOnLoginDay(): void
    {
        $resolved = $this->resolve('2026-03-02 09:15:00', '2026-03-05 07:40:00', true, '2026-03-20');

        self::assertSame(DeliveryBasis::Login, $resolved->basis);
        self::assertSame('2026-03-05', $resolved->deliveredOn?->format('Y-m-d'));
        self::assertSame('2026-03-12', $resolved->fictionDueOn?->format('Y-m-d'));
        self::assertTrue($resolved->basis->isDelivered());
    }

    /**
     * § 17 odst. 4 — nikdo se nepřihlásil, doručeno posledním dnem lhůty.
     * Dodání 2. 3. 2026 → lhůta 3.–12. 3. → fikce 12. 3. (čtvrtek).
     */
    public function testNoLoginAfterPeriodDeliversByFictionOnLastDay(): void
    {
        $resolved = $this->resolve('2026-03-02 09:15:00', null, true, '2026-03-20');

        self::assertSame(DeliveryBasis::Fiction, $resolved->basis);
        self::assertSame('2026-03-12', $resolved->deliveredOn?->format('Y-m-d'));
        self::assertSame('2026-03-12', $resolved->fictionStatutoryOn?->format('Y-m-d'));
        self::assertFalse($resolved->fictionShifted());
        self::assertSame(self::DAYS, $resolved->fictionDays);
    }

    /**
     * Hranice desátého dne. V ten den se ještě dá přihlásit, takže doručeno
     * NENÍ — teprve den poté je jisté, že fikce nastala. Rozhodným dnem
     * zůstává ten desátý.
     */
    public function testTenthDayItselfIsStillPendingAndTheDayAfterIsFiction(): void
    {
        $onTheDay = $this->resolve('2026-03-02 09:15:00', null, true, '2026-03-12');
        self::assertSame(DeliveryBasis::Pending, $onTheDay->basis);
        self::assertNull($onTheDay->deliveredOn, 'Během desátého dne doručeno ještě není.');
        self::assertSame('2026-03-12', $onTheDay->fictionDueOn?->format('Y-m-d'));

        $dayAfter = $this->resolve('2026-03-02 09:15:00', null, true, '2026-03-13');
        self::assertSame(DeliveryBasis::Fiction, $dayAfter->basis);
        self::assertSame(
            '2026-03-12',
            $dayAfter->deliveredOn?->format('Y-m-d'),
            'Fikce doručuje posledním dnem lhůty, ne dnem, kdy si toho aplikace všimla.',
        );
    }

    /**
     * § 33 odst. 4 DŘ — konec lhůty na sobotu se posouvá na pondělí.
     * Dodání 4. 3. 2026 → desátý den 14. 3. 2026 je sobota.
     */
    public function testDeadlineFallingOnSaturdayMovesToMonday(): void
    {
        $resolved = $this->resolve('2026-03-04 12:00:00', null, true, '2026-03-20');

        self::assertSame('2026-03-14', $resolved->fictionStatutoryOn?->format('Y-m-d'));
        self::assertSame('2026-03-16', $resolved->fictionDueOn?->format('Y-m-d'));
        self::assertTrue($resolved->fictionShifted());
        self::assertSame(DeliveryBasis::Fiction, $resolved->basis);
        self::assertSame('2026-03-16', $resolved->deliveredOn?->format('Y-m-d'));
    }

    /**
     * Svátek se počítá stejně jako víkend. Dodání 26. 6. 2026 → desátý den
     * 6. 7. 2026 (Den upálení mistra Jana Husa, pondělí) → úterý 7. 7. 2026.
     */
    public function testDeadlineFallingOnPublicHolidayMovesToNextWorkingDay(): void
    {
        $resolved = $this->resolve('2026-06-26 08:00:00', null, true, '2026-07-20');

        self::assertSame('2026-07-06', $resolved->fictionStatutoryOn?->format('Y-m-d'));
        self::assertSame('2026-07-07', $resolved->fictionDueOn?->format('Y-m-d'));
        self::assertTrue($resolved->fictionShifted());
    }

    /** Přihlášení přesně v den fikce — den je stejný, mechanismus nepoznáme. */
    public function testLoginExactlyOnFictionDayIsReportedAsAmbiguous(): void
    {
        $resolved = $this->resolve('2026-03-02 09:15:00', '2026-03-12 16:00:00', true, '2026-03-20');

        self::assertSame(DeliveryBasis::LoginOrFiction, $resolved->basis);
        self::assertSame('2026-03-12', $resolved->deliveredOn?->format('Y-m-d'));
        self::assertTrue($resolved->basis->isDelivered());
    }

    /**
     * NEJDŮLEŽITĚJŠÍ TVRZENÍ: fikci zná jen § 17 (doručování orgánů veřejné
     * moci). Poštovní datová zpráva podle § 18a ji nemá — a když o odesílateli
     * nic nevíme, aplikace ji nesmí uplatnit ani „pro jistotu".
     */
    public function testFictionIsNeverAppliedToAnUnprovenSender(): void
    {
        foreach ([null, false] as $sender) {
            $resolved = $this->resolve('2026-03-02 09:15:00', null, $sender, '2026-03-30');

            self::assertSame(
                DeliveryBasis::Unknown,
                $resolved->basis,
                'Bez doloženého orgánu veřejné moci se fikce doručení uplatnit nesmí.',
            );
            self::assertNull($resolved->deliveredOn);
            self::assertNull($resolved->fictionDueOn);
            self::assertFalse($resolved->basis->isDelivered());
        }
    }

    /** Od neznámého odesílatele se přihlášením doručí, ale bez lhůty fikce. */
    public function testUnprovenSenderStillDeliversOnLogin(): void
    {
        $resolved = $this->resolve('2026-03-02 09:15:00', '2026-03-05 07:40:00', null, '2026-03-30');

        self::assertSame(DeliveryBasis::Login, $resolved->basis);
        self::assertSame('2026-03-05', $resolved->deliveredOn?->format('Y-m-d'));
        self::assertNull($resolved->fictionDueOn);
    }

    /** Chybějící údaj je „nevíme", ne „doručeno" a ani „v pořádku". */
    public function testMissingTimestampsNeverProduceADeliveryDate(): void
    {
        $resolved = $this->resolve(null, null, true, '2026-03-30');

        self::assertSame(DeliveryBasis::Unknown, $resolved->basis);
        self::assertNull($resolved->deliveredOn);
        self::assertNull($resolved->fictionDueOn);
        self::assertFalse($resolved->basis->isDelivered());
        self::assertStringContainsString('neznáme', $resolved->note);
    }

    /** Zdroj délky lhůty se táhne až do uloženého řádku. */
    public function testRowCarriesTheSourceOfThePeriodLength(): void
    {
        $row = $this->resolve('2026-03-02 09:15:00', null, true, '2026-03-20')->toRow();

        self::assertSame('fiction', $row['delivery_basis']);
        self::assertSame(self::DAYS, $row['fiction_days']);
        self::assertSame(SubmissionLegalRules::SOURCE_STATUTE, $row['fiction_days_source']);
        self::assertSame(1, $row['sender_is_public_authority']);
    }

    /** Nesmyslná délka lhůty se nemá tvářit jako platný výpočet. */
    public function testZeroLengthPeriodIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->calculator->resolve(
            new \DateTimeImmutable('2026-03-02 09:15:00'),
            null,
            true,
            0,
            SubmissionLegalRules::SOURCE_STATUTE,
            new \DateTimeImmutable('2026-03-20 10:00:00'),
        );
    }

    private function resolve(
        ?string $deliveredAt,
        ?string $acceptedAt,
        ?bool $senderIsPublicAuthority,
        string $today,
    ): \MyInvoice\Service\Submission\ResolvedDelivery {
        $zone = new \DateTimeZone('Europe/Prague');

        return $this->calculator->resolve(
            $deliveredAt !== null ? new \DateTimeImmutable($deliveredAt, $zone) : null,
            $acceptedAt !== null ? new \DateTimeImmutable($acceptedAt, $zone) : null,
            $senderIsPublicAuthority,
            self::DAYS,
            SubmissionLegalRules::SOURCE_STATUTE,
            new \DateTimeImmutable($today . ' 10:00:00', $zone),
        );
    }
}
