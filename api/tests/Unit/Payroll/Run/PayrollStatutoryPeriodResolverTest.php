<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Run;

use MyInvoice\Service\Payroll\Run\PayrollStatutoryPeriodResolver;
use PHPUnit\Framework\TestCase;

final class PayrollStatutoryPeriodResolverTest extends TestCase
{
    public function testCalculationDatesFollowSettlementMonthNotPaymentDate(): void
    {
        $period = (new PayrollStatutoryPeriodResolver())->resolve(
            '2026-12-01',
            '2027-01-05',
        );

        self::assertSame('2026-12-01', $period->periodStart);
        self::assertSame('2026-12-31', $period->periodEnd);
        self::assertSame('2026-12-31', $period->taxCalculationDate);
        self::assertSame('2026-12-31', $period->socialCalculationDate);
        self::assertSame('2026-12-31', $period->healthCalculationDate);
        self::assertSame('2027-01-05', $period->paymentDate);
    }

    public function testInvalidPeriodOrPaymentDateFailsClosed(): void
    {
        $resolver = new PayrollStatutoryPeriodResolver();
        foreach ([
            ['2026-12-02', '2027-01-05'],
            ['2026-12-01', '2026-11-30'],
            ['not-a-date', '2027-01-05'],
        ] as [$period, $payment]) {
            try {
                $resolver->resolve($period, $payment);
                self::fail('Neplatný rozhodný měsíc musí být odmítnut.');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }
}
