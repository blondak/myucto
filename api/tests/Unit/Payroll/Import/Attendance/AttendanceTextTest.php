<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Import\Attendance;

use MyInvoice\Service\Payroll\Import\Attendance\AttendanceText;
use PHPUnit\Framework\TestCase;

/**
 * Klíč osoby a poznámka k nástupu (jména jsou vymyšlená).
 */
final class AttendanceTextTest extends TestCase
{
    public function testTitlesTyposAndBracketNotesAreNotPartOfThePersonKey(): void
    {
        self::assertSame('kamila vzorova', AttendanceText::personKey('Vzorová Kamila MqA.'));
        self::assertSame('kamila vzorova', AttendanceText::personKey('MgA. Kamila Vzorová'));
        self::assertSame('eva pokorna', AttendanceText::personKey('PaedDr. Eva Pokorná'));
        self::assertSame('ondrej testovaci', AttendanceText::personKey('Testovací Ondřej (DPP)'));
        self::assertSame('jan novak', AttendanceText::personKey('Novák Jan (Z0042)'));
        // Otec a syn: „(ml.)" ke jménu patří.
        self::assertSame('jan ml novak', AttendanceText::personKey('Novák Jan (ml.)'));
    }

    public function testNoteDatesNeedAKeyword(): void
    {
        self::assertSame(['start_on' => '2026-06-15', 'end_on' => null], AttendanceText::noteDates('nový nástup 15.6.2026'));
        self::assertSame(['start_on' => null, 'end_on' => '2026-06-03'], AttendanceText::noteDates('ukončení k 3. 6. 2026'));
        self::assertSame(
            ['start_on' => '2026-06-01', 'end_on' => '2026-06-30'],
            AttendanceText::noteDates('Nástup 1.6.2026, skončení 30.6.2026'),
        );
        self::assertSame(['start_on' => null, 'end_on' => null], AttendanceText::noteDates('30.6.2026'));
        self::assertSame(['start_on' => null, 'end_on' => null], AttendanceText::noteDates('ukončení 31.6.2026'));
        self::assertSame(['start_on' => null, 'end_on' => null], AttendanceText::noteDates(''));
    }
}
