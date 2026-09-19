<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Document;

use MyInvoice\Service\Payroll\Document\PayrollCarriedOverPeriodReader;
use PHPUnit\Framework\TestCase;

/**
 * Za které měsíce roku se vůbec čeká převzatý počáteční stav.
 *
 * Rozhoduje ZAČÁTEK VEDENÍ MEZD, ne první měsíc se schválenou revizí té osoby. Dokud se
 * to bralo podle osoby, odmítl roční doklad každému, kdo v roce prostě nastoupil později:
 * u něj za dřívější měsíce žádný příjem není, takže není co převzít.
 */
final class PayrollCarriedOverPeriodReaderTest extends TestCase
{
    public function testNothingToCarryWhenPayrollStartedBeforeTheYear(): void
    {
        self::assertSame(1, PayrollCarriedOverPeriodReader::expectedCarryStart('2025-08-01', 2026, 1));
        self::assertSame(1, PayrollCarriedOverPeriodReader::expectedCarryStart('2025-08-01', 2026, 8));
    }

    public function testNothingToCarryWithoutAStartPeriod(): void
    {
        self::assertSame(1, PayrollCarriedOverPeriodReader::expectedCarryStart(null, 2026, 3));
    }

    public function testEmployeeHiredLaterInTheYearCarriesNothing(): void
    {
        // Mzdy v MyÚčtu od ledna, člověk nastoupil v březnu.
        self::assertSame(1, PayrollCarriedOverPeriodReader::expectedCarryStart('2026-01-01', 2026, 3));
        // Mzdy od srpna, člověk nastoupil v říjnu: měsíce 8 a 9 se počítaly ostatním, jemu ne.
        self::assertSame(1, PayrollCarriedOverPeriodReader::expectedCarryStart('2026-08-01', 2026, 10));
    }

    public function testMonthsBeforeTheStartOfPayrollAreExpectedToBeCarried(): void
    {
        self::assertSame(8, PayrollCarriedOverPeriodReader::expectedCarryStart('2026-08-01', 2026, 8));
        self::assertSame(3, PayrollCarriedOverPeriodReader::expectedCarryStart('2026-03-01', 2026, 3));
    }
}
