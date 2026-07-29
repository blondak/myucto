<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Report;

use DateTimeImmutable;
use MyInvoice\Service\Report\CzechWorkingDays;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * § 33 odst. 4 daňového řádu: připadne-li poslední den lhůty na sobotu, neděli
 * nebo svátek, je posledním dnem nejblíže následující pracovní den.
 *
 * Bez posunu hlásila aplikace „po termínu" den po zákonném termínu — reálný
 * případ: kontrolní hlášení za 06/2026 se podává do 25. 7. 2026, což je SOBOTA,
 * takže skutečná lhůta končí až v pondělí 27. 7.
 */
final class CzechWorkingDaysTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function deadlines(): iterable
    {
        yield 'sobota → pondělí (KH 06/2026)' => ['2026-07-25', '2026-07-27'];
        yield 'neděle → pondělí'              => ['2026-10-25', '2026-10-26'];
        yield 'všední den beze změny'         => ['2026-08-25', '2026-08-25'];
        // Svátky: 1. 5. 2026 je pátek, takže se posouvá až na pondělí 4. 5.
        yield 'pevný svátek → další pracovní' => ['2026-05-01', '2026-05-04'];
        // Velikonoce 2026: neděle 5. 4. → Velký pátek 3. 4., pondělí 6. 4.
        yield 'Velký pátek → úterý'           => ['2026-04-03', '2026-04-07'];
        yield 'Velikonoční pondělí → úterý'   => ['2026-04-06', '2026-04-07'];
        // Vánoční blok 24.–26. 12. 2026 je čtvrtek–sobota → až pondělí 28. 12.
        yield 'vánoční blok → pondělí'        => ['2026-12-24', '2026-12-28'];
    }

    #[DataProvider('deadlines')]
    public function testShiftToWorkingDay(string $from, string $expected): void
    {
        self::assertSame(
            $expected,
            CzechWorkingDays::shiftToWorkingDay(new DateTimeImmutable($from))->format('Y-m-d'),
        );
    }

    public function testDeadlineFormatsShiftedDate(): void
    {
        self::assertSame('2026-07-27', CzechWorkingDays::deadline(2026, 7), 'KH 06/2026 — 25. 7. je sobota.');
        self::assertSame('2026-08-25', CzechWorkingDays::deadline(2026, 8), 'Všední termín zůstává na 25.');
    }

    /** Velikonoce počítá vlastní algoritmus (bez ext-calendar) — ověřeno proti známým datům. */
    public function testEasterSunday(): void
    {
        $known = [2024 => '2024-03-31', 2025 => '2025-04-20', 2026 => '2026-04-05', 2027 => '2027-03-28'];
        foreach ($known as $year => $date) {
            self::assertSame($date, CzechWorkingDays::easterSunday($year)->format('Y-m-d'), "Velikonoce {$year}");
        }
    }

    public function testWeekendIsNotWorkingDay(): void
    {
        self::assertFalse(CzechWorkingDays::isWorkingDay(new DateTimeImmutable('2026-07-25')), 'sobota');
        self::assertFalse(CzechWorkingDays::isWorkingDay(new DateTimeImmutable('2026-07-26')), 'neděle');
        self::assertTrue(CzechWorkingDays::isWorkingDay(new DateTimeImmutable('2026-07-27')), 'pondělí');
    }
}
