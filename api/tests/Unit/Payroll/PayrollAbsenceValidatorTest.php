<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll;

use MyInvoice\Service\Payroll\PayrollAbsenceValidator;
use PHPUnit\Framework\TestCase;

final class PayrollAbsenceValidatorTest extends TestCase
{
    public function testSecondQuarterRequiresExactPreviousCalendarQuarter(): void
    {
        $data = (new PayrollAbsenceValidator())->average([
            'employment_id' => 1,
            'applicable_year' => 2026,
            'applicable_quarter' => 2,
            'decisive_from' => '2026-01-01',
            'decisive_to' => '2026-03-31',
            'gross_earnings_minor' => 1,
            'longer_period_allocated_minor' => 0,
            'worked_minutes' => 1,
            'worked_days' => 21,
        ]);

        self::assertSame('2026-01-01', $data['decisive_from']);
        self::assertSame('2026-03-31', $data['decisive_to']);
    }

    public function testFirstQuarterUsesPreviousYearFourthQuarter(): void
    {
        $data = (new PayrollAbsenceValidator())->average([
            'employment_id' => 1,
            'applicable_year' => 2026,
            'applicable_quarter' => 1,
            'decisive_from' => '2025-10-01',
            'decisive_to' => '2025-12-31',
            'gross_earnings_minor' => 1,
            'longer_period_allocated_minor' => 0,
            'worked_minutes' => 1,
            'worked_days' => 21,
        ]);

        self::assertSame(1, $data['applicable_quarter']);
    }

    public function testUnsupportedAbsenceYearFailsClosed(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new PayrollAbsenceValidator())->absence([
            'employment_id' => 1,
            'absence_type' => 'other',
            'date_from' => '2025-12-31',
            'date_to' => '2025-12-31',
        ]);
    }
}
