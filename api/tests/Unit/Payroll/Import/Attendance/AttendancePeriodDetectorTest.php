<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Import\Attendance;

use MyInvoice\Service\Payroll\Import\Attendance\AttendancePeriodDetector;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AttendancePeriodDetectorTest extends TestCase
{
    /** @return iterable<string,array{string,?string}> */
    public static function names(): iterable
    {
        yield 'měsíc-rok v názvu souboru' => ['podklady 11-2025 provoz.xlsx', '2025-11'];
        yield 'měsíc bez nuly a lomítko' => ['vyplaty 3/2025.xlsx', '2025-03'];
        yield 'rok-měsíc' => ['dochazka-2025-11.csv', '2025-11'];
        yield 'list s dvoumístným rokem' => ['Mzdy 11-25', '2025-11'];
        yield 'čtyřčíslí jako celý název' => ['1125.xlsx', '2025-11'];
        yield 'datum s tečkami' => ['stav k 30.11.2025.xlsx', '2025-11'];
        yield 'číslo uvnitř slova není období' => ['export1234.csv', null];
        yield 'čtyřčíslí uvnitř názvu není období' => ['report 1125 b.xlsx', null];
        yield 'neplatný měsíc' => ['13-2025.xlsx', null];
        yield 'list bez období' => ['výpočet', null];
    }

    #[DataProvider('names')]
    public function testDetectsPeriodFromName(string $name, ?string $expected): void
    {
        self::assertSame($expected, AttendancePeriodDetector::fromName($name));
    }

    public function testGroupsNamesByPeriod(): void
    {
        self::assertSame(
            ['2025-10' => ['Mzdy 10-25'], '2025-11' => ['podklady 11-2025.xlsx', '1125.xlsx']],
            AttendancePeriodDetector::detect(['podklady 11-2025.xlsx', 'výpočet', '1125.xlsx', 'Mzdy 10-25', 'podklady 11-2025.xlsx']),
        );
    }
}
