<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Absence;

use MyInvoice\Service\Payroll\Absence\SicknessCompensationCalculator;
use MyInvoice\Service\Payroll\Absence\SicknessCompensationReduction;
use MyInvoice\Service\Payroll\Ruleset\CzechPayrollRulesets2026;
use PHPUnit\Framework\TestCase;

/**
 * DPN-03: snížení náhrady mzdy podle § 192 odst. 4 a 5 ZP.
 *
 * Průměr 250 Kč/h leží celý v prvním redukčním pásmu (90 %), redukovaný výdělek je
 * 225 Kč/h. Směna 7,5 h dává přesnou náhradu 225 × 0,6 × 7,5 = 1 012,50 Kč, vyplácí
 * se 1 013 Kč (celé koruny nahoru, § 142 odst. 2 a § 144 ZP).
 */
final class SicknessCompensationReductionTest extends TestCase
{
    private const AVERAGE_HOURLY_MINOR = 25_000;

    public function testWithoutReductionPaysFullCompensation(): void
    {
        $result = $this->calculate();

        self::assertSame(22_500, $result->reducedHourlyMinor);
        self::assertSame(101_300, $result->compensationMinor);
        self::assertTrue($result->reduction()->isNone());
        self::assertArrayNotHasKey('reduction', $result->trace);
    }

    /** § 192 odst. 4 ZP: polovina z 1 012,50 Kč je 506,25 Kč, nahoru 507 Kč. */
    public function testHalfReductionHalvesTheExactCompensationBeforeRounding(): void
    {
        $result = $this->calculate(SicknessCompensationReduction::half('Syntetický případ § 31.'));

        self::assertSame(50_700, $result->compensationMinor);
        self::assertSame(101_300, $result->trace['compensation_before_reduction_minor']);
        self::assertSame('half_192_4', $result->trace['reduction']);
        self::assertSame(5_000, $result->trace['reduction_basis_points']);
        self::assertSame($result->compensationMinor, array_sum(array_column($result->segments, 'compensation_minor')));
    }

    /**
     * § 192 odst. 5 ZP o 30 %: 70 % z přesných 1 012,50 Kč je 708,75 Kč, nahoru 709 Kč.
     * Kdyby se krátila už zaokrouhlená náhrada (70 % z 1 013 Kč = 709,10 Kč), vyšlo by
     * 710 Kč.
     */
    public function testShareReductionUsesTheExactNumerator(): void
    {
        $result = $this->calculate(SicknessCompensationReduction::byShare(3_000, 'Syntetické porušení režimu.'));

        self::assertSame(70_900, $result->compensationMinor);
    }

    public function testFullShareReductionMeansNoCompensation(): void
    {
        $result = $this->calculate(SicknessCompensationReduction::byShare(10_000, 'Syntetické porušení režimu.'));

        self::assertSame(0, $result->compensationMinor);
        self::assertSame([0], array_column($result->segments, 'compensation_minor'));
    }

    public function testAmountReductionSubtractsFromTheRoundedCompensation(): void
    {
        $result = $this->calculate(SicknessCompensationReduction::byAmount(30_000, 'Syntetické porušení režimu.'));

        self::assertSame(71_300, $result->compensationMinor);
        self::assertSame(30_000, $result->trace['reduction_minor']);
    }

    public function testAmountReductionAboveCompensationIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('vyšší než celá náhrada');
        $this->calculate(SicknessCompensationReduction::byAmount(101_400, 'Syntetické porušení režimu.'));
    }

    public function testAmountReductionAcrossMonthsIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('v jednom kalendářním měsíci');
        (new SicknessCompensationCalculator(CzechPayrollRulesets2026::provider()))->calculate(
            '2026-06-30',
            self::AVERAGE_HOURLY_MINOR,
            [
                ['shift_id' => 1, 'local_date' => '2026-06-30', 'planned_minutes' => 450, 'eligible_minutes' => 450],
                ['shift_id' => 2, 'local_date' => '2026-07-01', 'planned_minutes' => 450, 'eligible_minutes' => 450],
            ],
            SicknessCompensationReduction::byAmount(10_000, 'Syntetické porušení režimu.'),
        );
    }

    public function testReductionNeedsReason(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        SicknessCompensationReduction::half('  ');
    }

    public function testWithoutCompensationKeepsRulesetEvidence(): void
    {
        $result = (new SicknessCompensationCalculator(CzechPayrollRulesets2026::provider()))
            ->withoutCompensation('2026-06-15', 'not_eligible');

        self::assertSame(0, $result->compensationMinor);
        self::assertSame([], $result->segments);
        self::assertSame('not_eligible', $result->trace['no_compensation_reason']);
        self::assertSame(14, $result->trace['window_calendar_days']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $result->rulesetHash);
    }

    private function calculate(?SicknessCompensationReduction $reduction = null): \MyInvoice\Service\Payroll\Absence\SicknessCompensationResult
    {
        return (new SicknessCompensationCalculator(CzechPayrollRulesets2026::provider()))->calculate(
            '2026-06-15',
            self::AVERAGE_HOURLY_MINOR,
            [['shift_id' => 1, 'local_date' => '2026-06-15', 'planned_minutes' => 450, 'eligible_minutes' => 450]],
            $reduction,
        );
    }
}
