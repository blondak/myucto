<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Migration;

use MyInvoice\Service\Payroll\Migration\PayrollMigrationReferenceTotals;
use MyInvoice\Service\Payroll\Migration\PayrollMigrationTakeoverFacts;
use PHPUnit\Framework\TestCase;

/**
 * Tovární metody převzatých úhrnů pro zdroje, které si pole samy přeloží na metriky
 * (PREMIER): stejné haléře jako ruční skládání konstruktoru.
 */
final class PayrollMigrationReferenceTotalsFactoryTest extends TestCase
{
    private const MONTH = [
        'gross' => 6000.0, 'net' => 4404.0, 'social_base' => 6000.0, 'health_base' => 6000.0,
        'employee_social' => 426.0, 'employee_health' => 270.0, 'employer_social' => 1488.0, 'employer_health' => 540.0,
        'advance_tax' => 0.0, 'withholding_tax' => 900.0, 'tax_bonus' => 0.0,
        'pension_participation' => true, 'insurance_days' => 31, 'excluded_days' => 2,
        'worked_days' => 20.5, 'worked_minutes' => 9840, 'deductions' => 150.25, 'net_payable' => 4253.75,
    ];

    public function testFromAmountsMatchesConstructor(): void
    {
        $facts = PayrollMigrationTakeoverFacts::fromMonth(self::MONTH, '2025-01-01', null, 'statutory_body', 'S');
        $totals = PayrollMigrationReferenceTotals::fromAmounts('2025-01', 'premier:P', 'premier:1', 7, 8, self::MONTH, $facts);

        self::assertEquals(new PayrollMigrationReferenceTotals(
            '2025-01', 'premier:P', 'premier:1', 7, 8,
            600000, 440400, 600000, 600000, 42600, 27000, 148800, 54000, 0, 90000, 0,
            new PayrollMigrationTakeoverFacts(
                relationshipStartDate: '2025-01-01',
                relationType: 'statutory_body',
                activityCode: 'S',
                pensionParticipation: true,
                insuranceDays: 31,
                excludedDays: 2,
                workedDaysHundredths: 2050,
                workedMinutes: 9840,
                deductionsMinor: 15025,
                netPayableMinor: 425375,
            ),
        ), $totals);
    }

    public function testRoundingIsASingleStepToHalere(): void
    {
        $totals = PayrollMigrationReferenceTotals::fromAmounts('2025-02', 'p', 'r', null, null, ['gross' => 180.985] + self::MONTH);

        self::assertSame((int) round(180.985 * 100.0), $totals->grossMinor);
    }

    public function testMissingMetricIsRejected(): void
    {
        $month = self::MONTH;
        unset($month['tax_bonus']);

        $this->expectException(\InvalidArgumentException::class);
        PayrollMigrationReferenceTotals::fromAmounts('2025-01', 'p', 'r', null, null, $month);
    }
}
