<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission;

use MyInvoice\Service\Payroll\Submission\Eldp\EldpPensionAgeCode;
use PHPUnit\Framework\TestCase;

/** Jediné pravidlo kódu ELDP „D" pro roční evidenční list i měsíční hlášení JMHZ. */
final class EldpPensionAgeCodeTest extends TestCase
{
    public function testCodeStartsOnTheEarlierOfAgeAndEarlyPension(): void
    {
        self::assertNull(EldpPensionAgeCode::codeFrom([]));
        self::assertNull(EldpPensionAgeCode::codeFrom(['pension_age_reached_on' => null, 'early_pension_from' => null]));
        self::assertSame('2025-03-15', EldpPensionAgeCode::codeFrom(['pension_age_reached_on' => '2025-03-15', 'early_pension_from' => null]));
        self::assertSame('2024-11-01', EldpPensionAgeCode::codeFrom(['pension_age_reached_on' => '2025-03-15', 'early_pension_from' => '2024-11-01']));
    }

    public function testPlacementInsideAnInterval(): void
    {
        self::assertSame(EldpPensionAgeCode::PLAIN, EldpPensionAgeCode::placement(null, '2026-07-01', '2026-07-31'));
        self::assertSame(EldpPensionAgeCode::PLAIN, EldpPensionAgeCode::placement('2026-08-01', '2026-07-01', '2026-07-31'));
        self::assertSame(EldpPensionAgeCode::MID_INTERVAL, EldpPensionAgeCode::placement('2026-07-31', '2026-07-01', '2026-07-31'));
        self::assertSame(EldpPensionAgeCode::PENSION_AGE, EldpPensionAgeCode::placement('2026-07-01', '2026-07-01', '2026-07-31'));
        self::assertSame(EldpPensionAgeCode::PENSION_AGE, EldpPensionAgeCode::placement('2025-01-01', '2026-07-01', '2026-07-31'));
    }

    public function testSecondPositionBecomesD(): void
    {
        self::assertSame('1D+', EldpPensionAgeCode::withPensionAge('1++'));
        self::assertSame('AD+', EldpPensionAgeCode::withPensionAge('A++'));
        self::assertSame('DD+', EldpPensionAgeCode::withPensionAge('D++'));
        self::assertSame('1DT', EldpPensionAgeCode::withPensionAge('1+T'));
    }
}
