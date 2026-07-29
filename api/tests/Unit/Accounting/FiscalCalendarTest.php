<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Accounting;

use MyInvoice\Service\Accounting\FiscalCalendar;
use PHPUnit\Framework\TestCase;

/**
 * FiscalCalendar — mapování datum ↔ label období pro kalendářní i hospodářský rok.
 * Kalendářní default musí být shodné s dřívějším substr($date, 0, 4).
 */
final class FiscalCalendarTest extends TestCase
{
    public function testCalendarIsIdentityToSubstr(): void
    {
        $cal = FiscalCalendar::calendar();
        $this->assertTrue($cal->isCalendar());
        foreach (['2023-01-01', '2024-06-15', '2025-12-31', '2026-02-28'] as $date) {
            $this->assertSame((int) substr($date, 0, 4), $cal->fiscalYearOfDate($date), $date);
        }
    }

    public function testCalendarMonthIndexMatchesLegacy(): void
    {
        $cal = FiscalCalendar::calendar();
        // monthIndex = year*12 + (month-1); legacy year = intdiv(monthIndex, 12)
        for ($y = 2024; $y <= 2026; $y++) {
            for ($m = 1; $m <= 12; $m++) {
                $idx = $y * 12 + ($m - 1);
                $this->assertSame(intdiv($idx, 12), $cal->fiscalYearOfMonthIndex($idx));
            }
            $this->assertSame($y * 12 + 11, $cal->lastMonthIndex($y));
            $this->assertSame($y * 12, $cal->firstMonthIndex($y));
        }
        $this->assertSame('2025-01-01', $cal->periodStart(2025));
        $this->assertSame('2025-12-31', $cal->periodEnd(2025));
    }

    public function testFiscalYearJulyToJune(): void
    {
        // Hospodářský rok 1. 7. – 30. 6., label = rok počátku (§21a, konvence F4).
        $cal = FiscalCalendar::fromPeriodStart('2025-07-01');
        $this->assertFalse($cal->isCalendar());
        $this->assertSame(7, $cal->startMonth());

        // Datumy v období 2025-07-01 .. 2026-06-30 → label 2025.
        $this->assertSame(2025, $cal->fiscalYearOfDate('2025-07-01'));
        $this->assertSame(2025, $cal->fiscalYearOfDate('2025-09-15'));
        $this->assertSame(2025, $cal->fiscalYearOfDate('2026-06-30'));
        // Následující období začíná 2026-07-01 → label 2026.
        $this->assertSame(2026, $cal->fiscalYearOfDate('2026-07-01'));
        // Před obdobím: 2025-06-30 spadá do předchozího období (label 2024).
        $this->assertSame(2024, $cal->fiscalYearOfDate('2025-06-30'));

        // Hranice období.
        $this->assertSame('2025-07-01', $cal->periodStart(2025));
        $this->assertSame('2026-06-30', $cal->periodEnd(2025));
        $this->assertSame('2026-07-01', $cal->periodStart(2026));
        $this->assertSame('2027-06-30', $cal->periodEnd(2026));
    }

    public function testFiscalMonthBucketing(): void
    {
        $cal = FiscalCalendar::fromPeriodStart('2025-07-01');
        // červen 2026 (měsíc 6) patří do období 2025; červenec 2026 (měsíc 7) do 2026.
        $juneIdx = 2026 * 12 + (6 - 1);
        $julyIdx = 2026 * 12 + (7 - 1);
        $this->assertSame(2025, $cal->fiscalYearOfMonthIndex($juneIdx));
        $this->assertSame(2026, $cal->fiscalYearOfMonthIndex($julyIdx));
        // první/poslední měsíc období 2025 = červenec 2025 .. červen 2026.
        $this->assertSame(2025 * 12 + 6, $cal->firstMonthIndex(2025));
        $this->assertSame(2026 * 12 + 5, $cal->lastMonthIndex(2025));
        // spojitost: lastMonthIndex(fy) + 1 == firstMonthIndex(fy+1)
        $this->assertSame($cal->firstMonthIndex(2026), $cal->lastMonthIndex(2025) + 1);
    }
}
