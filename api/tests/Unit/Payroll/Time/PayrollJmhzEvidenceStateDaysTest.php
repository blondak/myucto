<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Time;

use MyInvoice\Service\Payroll\Time\PayrollJmhzEvidenceStateDays;
use PHPUnit\Framework\TestCase;

/**
 * Dny v evidenčním stavu (10265): pracovní poměr v něm není po dny mateřské,
 * rodičovské a otcovské (odpovědi helpdesku MPSV a ČSÚ, metodika evidenčního
 * počtu zaměstnanců). Nemoc ani neplacené volno ho nemění.
 */
final class PayrollJmhzEvidenceStateDaysTest extends TestCase
{
    public function testWholeMonthOfParentalLeaveLeavesNoDayInEvidenceState(): void
    {
        self::assertSame(0, PayrollJmhzEvidenceStateDays::days(
            'employment',
            '2026-07-01',
            '2026-07-31',
            [$this->absence('parental', '2026-01-01', '2027-12-31')],
        ));
    }

    public function testMaternityPaternityAndParentalAreSubtractedOnceEach(): void
    {
        self::assertSame(31 - 10 - 5, PayrollJmhzEvidenceStateDays::days(
            'employment',
            '2026-07-01',
            '2026-07-31',
            [
                $this->absence('ppm', '2026-06-01', '2026-07-10'),
                $this->absence('paternity', '2026-07-20', '2026-07-24'),
                // Překryv s otcovskou se nepočítá dvakrát.
                $this->absence('parental', '2026-07-22', '2026-07-24'),
            ],
        ));
    }

    public function testSicknessAndUnpaidLeaveKeepTheEvidenceState(): void
    {
        self::assertSame(31, PayrollJmhzEvidenceStateDays::days(
            'employment',
            '2026-07-01',
            '2026-07-31',
            [
                $this->absence('dpn', '2026-07-01', '2026-07-15'),
                $this->absence('unpaid_leave', '2026-07-16', '2026-07-31'),
            ],
        ));
    }

    /** Metodika evidenčního počtu se týká jen pracovního poměru. */
    public function testOnlyEmploymentIsReduced(): void
    {
        self::assertSame(31, PayrollJmhzEvidenceStateDays::days(
            'statutory_body',
            '2026-07-01',
            '2026-07-31',
            [$this->absence('ppm', '2026-07-01', '2026-07-31')],
        ));
    }

    /** @return array<string,mixed> */
    private function absence(string $type, string $from, string $to): array
    {
        return ['absence_type' => $type, 'date_from' => $from, 'date_to' => $to];
    }
}
