<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Absence;

use MyInvoice\Service\Payroll\Absence\AbsenceRuleset;
use MyInvoice\Service\Payroll\Ruleset\CzechPayrollRulesets2026;
use PHPUnit\Framework\TestCase;

/**
 * Zákonná čísla absencí, průměrů a dovolené musí být v rulesetu — hodnoty jsou
 * tytéž, jaké byly předtím literálem v kalkulátorech.
 */
final class AbsenceRulesetTest extends TestCase
{
    public function testStatutoryNumbersComeFromTheRulesetWithUnchangedValues(): void
    {
        $rules = AbsenceRuleset::forYear(CzechPayrollRulesets2026::provider(), 2026);

        self::assertSame(6_000, $rules->compensationRateBasisPoints());
        self::assertSame(14, $rules->sicknessWindowCalendarDays());
        self::assertSame(21, $rules->averageEarningMinimumWorkedDays());
        self::assertSame(4, $rules->leaveStatutoryMinimumWeeks());
        self::assertSame(28, $rules->leaveMinimumContinuousCalendarDays());
        self::assertSame(4, $rules->leaveMinimumWorkedWeekMultiples());
        self::assertSame(52, $rules->leaveWeeksPerYear());
        self::assertSame(1_200, $rules->leaveAgreementWeeklyMinutes());
    }

    public function testSicknessWindowEndIsDerivedFromTheRulesetLength(): void
    {
        $rules = AbsenceRuleset::forYear(CzechPayrollRulesets2026::provider(), 2026);

        self::assertSame(
            '2026-06-28',
            $rules->sicknessWindowEnd(new \DateTimeImmutable('2026-06-15'))->format('Y-m-d'),
        );
    }

    public function testDateWithoutRulesetFailsClosed(): void
    {
        $this->expectException(\MyInvoice\Service\Payroll\Ruleset\PayrollRulesetException::class);
        AbsenceRuleset::forDate(CzechPayrollRulesets2026::provider(), '2027-06-15');
    }
}
