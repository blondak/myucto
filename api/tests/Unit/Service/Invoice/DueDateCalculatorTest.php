<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Invoice;

use MyInvoice\Service\Invoice\DueDateCalculator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DueDateCalculatorTest extends TestCase
{
    #[DataProvider('dates')]
    public function testCalculate(
        string $issueDate,
        int $value,
        string $unit,
        string $expected,
    ): void {
        self::assertSame(
            $expected,
            DueDateCalculator::calculate($issueDate, $value, $unit),
        );
    }

    public static function dates(): iterable
    {
        yield 'days' => ['2026-07-31', 14, 'days', '2026-08-14'];
        yield 'calendar month' => ['2026-07-31', 1, 'month', '2026-08-31'];
        yield 'shorter month' => ['2026-01-31', 1, 'month', '2026-02-28'];
        yield 'leap year' => ['2028-01-31', 1, 'month', '2028-02-29'];
        yield 'year rollover' => ['2026-11-30', 2, 'month', '2027-01-30'];
    }
}
