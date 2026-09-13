<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Import\Attendance;

use MyInvoice\Service\Payroll\Import\Attendance\AttendanceCell;
use MyInvoice\Service\Payroll\Import\Attendance\AttendanceDecimal;
use MyInvoice\Service\Payroll\Import\Attendance\AttendancePersonAggregator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AttendanceDecimalTest extends TestCase
{
    /** @return iterable<string,array{string,?string}> */
    public static function numbers(): iterable
    {
        yield 'česká částka s mezerou a měnou' => ['71 875,00 Kč', '71875'];
        yield 'pevná mezera jako oddělovač tisíců' => ["48\u{00A0}000,00 Kč", '48000'];
        yield 'desetinná čárka' => ['149,6', '149.6'];
        yield 'tečky jako tisíce' => ['1.234,50', '1234.5'];
        yield 'anglický zápis' => ['-3.5', '-3.5'];
        yield 'účetní závorka' => ['(1 200)', '-1200'];
        yield 'text' => ['abc', null];
        yield 'prázdno není nula' => ['', null];
    }

    #[DataProvider('numbers')]
    public function testParsesCzechNumbers(string $input, ?string $expected): void
    {
        self::assertSame($expected, AttendanceDecimal::parseNumber($input));
    }

    /** @return iterable<string,array{string,?string}> */
    public static function weeklyHours(): iterable
    {
        yield 'desetinná čárka' => ['37,5', '37.5'];
        yield 'jednotka' => ['40 h', '40'];
        yield 'časový zápis' => ['36:30', '36.5'];
        yield 'dlouhý rozvoj z Excelu' => ['12,1000003814697', '12.1'];
        yield 'jediné číslo v textu směny' => ['noční - 18,75', '18.75'];
        yield 'bez čísla' => ['DPP', null];
        yield 'dvě čísla nejsou jednoznačná' => ['8-16', null];
        yield 'mimo týden' => ['200', null];
        yield 'nula' => ['0', null];
    }

    #[DataProvider('weeklyHours')]
    public function testReadsWeeklyHours(string $input, ?string $expected): void
    {
        self::assertSame($expected, AttendanceDecimal::weeklyHours($input));
    }

    public function testClockValuesAreNotTruncatedToTimeOfDay(): void
    {
        self::assertSame(8000, AttendanceDecimal::parseClockMillihours('8:00'));
        self::assertSame(36500, AttendanceDecimal::parseClockMillihours('36:30'));
        self::assertSame(-1250, AttendanceDecimal::parseClockMillihours('-1:15'));
        self::assertSame(7500, AttendanceDecimal::parseClockMillihours('7:30:00'));
        self::assertNull(AttendanceDecimal::parseClockMillihours('8.5'));
    }

    public function testExcelDurationKeepsHoursOverOneDay(): void
    {
        self::assertSame(36000, AttendanceDecimal::durationMillihours(1.5));
        self::assertSame(168000, AttendanceDecimal::durationMillihours(7.0));
        self::assertSame(8000, AttendanceDecimal::durationMillihours(0.3333333333));
        self::assertSame(16000, AttendanceDecimal::durationMillihours(0.6666666667));
    }

    public function testScaledRoundsHalfUpAwayFromZero(): void
    {
        self::assertSame(101, AttendanceDecimal::scaled('1.005', 2));
        self::assertSame(-3, AttendanceDecimal::scaled('-2.5', 0));
        self::assertSame(16000, AttendanceDecimal::scaled('16', 3));
    }

    /**
     * 180,98 × 24,25 = 4388,765; jako float je to 4388,76499…, takže prosté
     * `round(x, 2)` dá o haléř méně. Předzaokrouhlení na 4 místa to drží.
     */
    public function testAmountFromFloatDoesNotLoseHaler(): void
    {
        $parsed = AttendancePersonAggregator::amountMinor(AttendanceCell::number(180.98 * 24.25));

        self::assertSame(['value' => 438877], $parsed);
    }

    public function testFormatting(): void
    {
        self::assertSame('16.00', AttendanceDecimal::formatMillihours(16000));
        self::assertSame('7.33', AttendanceDecimal::formatMillihours(7333));
        self::assertSame('-1.50', AttendanceDecimal::formatMinor(-150));
    }
}
