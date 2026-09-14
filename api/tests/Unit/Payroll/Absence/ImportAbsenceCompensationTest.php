<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Absence;

use InvalidArgumentException;
use MyInvoice\Service\Payroll\Absence\ImportAbsenceCompensationRates;
use MyInvoice\Service\Payroll\Absence\LeaveCompensationCalculator;
use MyInvoice\Service\Payroll\Absence\PayrollImportAbsenceCompensationMaterializer;
use MyInvoice\Service\Payroll\Absence\PayrollWageProrationService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Čisté části náhrad mzdy z měsíčních součtů importu docházky: zaokrouhlení
 * náhrady z minut, sazby podle významu, převod millihodin na minuty a rozpad
 * nahrazených minut pro krácení měsíční mzdy.
 */
final class ImportAbsenceCompensationTest extends TestCase
{
    /** Tatáž částka jako ze směn: 7 h 30 min × 253,30 Kč = 1 899,75 Kč → 1 900 Kč. */
    public function testMinutesOverloadMatchesTheShiftBasedCalculation(): void
    {
        $fromShifts = LeaveCompensationCalculator::calculate(25_330, [[
            'shift_id' => null,
            'local_date' => '2026-06-15',
            'planned_minutes' => 450,
            'eligible_minutes' => 450,
        ]]);

        self::assertSame(190_000, LeaveCompensationCalculator::calculateMinutes(25_330, 450));
        self::assertSame($fromShifts->amountsByPeriod['2026-06-01'], LeaveCompensationCalculator::calculateMinutes(25_330, 450));
    }

    /**
     * Procento se uplatní na přesný zlomek a zaokrouhlí se až výsledek:
     * 7 h 30 min × 253,30 Kč × 80 % = 1 519,80 Kč → 1 520 Kč.
     */
    public function testRateIsAppliedBeforeRoundingUpToWholeCrowns(): void
    {
        self::assertSame(160_000, LeaveCompensationCalculator::calculateMinutes(25_000, 480, 80));
        self::assertSame(152_000, LeaveCompensationCalculator::calculateMinutes(25_330, 450, 80));
        self::assertSame(100, LeaveCompensationCalculator::calculateMinutes(1, 1));
    }

    /** @return iterable<string,array{int,int,int}> */
    public static function invalidCalculations(): iterable
    {
        yield 'nulový průměr' => [0, 60, 100];
        yield 'nulové minuty' => [25_000, 0, 100];
        yield 'záporné minuty' => [25_000, -60, 100];
        yield 'nulová sazba' => [25_000, 60, 0];
        yield 'sazba nad 100 %' => [25_000, 60, 101];
    }

    #[DataProvider('invalidCalculations')]
    public function testInvalidInputIsRefused(int $average, int $minutes, int $percent): void
    {
        $this->expectException(InvalidArgumentException::class);
        LeaveCompensationCalculator::calculateMinutes($average, $minutes, $percent);
    }

    public function testDefaultRatesFollowTheLawAndTheImportColumn(): void
    {
        self::assertSame(
            ['vacation_hours' => 100, 'doctor_hours' => 100, 'obstacle_employer_hours' => 80],
            ImportAbsenceCompensationRates::defaults()->toArray(),
        );
    }

    public function testEmployerObstacleRateIsConfigurableWithinStatutoryBounds(): void
    {
        self::assertSame(60, ImportAbsenceCompensationRates::fromMap(['obstacle_employer_hours' => 60])->percentFor('obstacle_employer_hours'));
        self::assertSame(100, ImportAbsenceCompensationRates::fromMap(['obstacle_employer_hours' => 100])->percentFor('obstacle_employer_hours'));
        self::assertSame(100, ImportAbsenceCompensationRates::fromMap(['obstacle_employer_hours' => 100])->percentFor('vacation_hours'));
    }

    /** @return iterable<string,array{array<string,mixed>}> */
    public static function invalidRates(): iterable
    {
        yield 'překážka pod 60 %' => [['obstacle_employer_hours' => 59]];
        yield 'dovolená jinak než 100 %' => [['vacation_hours' => 80]];
        yield 'lékař jinak než 100 %' => [['doctor_hours' => 90]];
        yield 'nemoc se nepočítá' => [['sick_hours' => 60]];
        yield 'desetinné procento' => [['obstacle_employer_hours' => 80.5]];
    }

    /** @param array<string,mixed> $map */
    #[DataProvider('invalidRates')]
    public function testRatesOutsideTheLawAreRefused(array $map): void
    {
        $this->expectException(InvalidArgumentException::class);
        ImportAbsenceCompensationRates::fromMap($map);
    }

    public function testMillihoursRoundToWholeMinutes(): void
    {
        self::assertSame(440, PayrollImportAbsenceCompensationMaterializer::minutes(7_333), '7:20 zapsané jako 7,333 h.');
        self::assertSame(960, PayrollImportAbsenceCompensationMaterializer::minutes(16_000));
        self::assertSame(0, PayrollImportAbsenceCompensationMaterializer::minutes(8));
        self::assertSame(1, PayrollImportAbsenceCompensationMaterializer::minutes(9));
        self::assertSame(0, PayrollImportAbsenceCompensationMaterializer::minutes(0));
    }

    public function testNegativeHoursAreRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        PayrollImportAbsenceCompensationMaterializer::minutes(-1);
    }

    public function testExternalIdIsStableAndPassesTheLeaveSnapshotCheck(): void
    {
        self::assertSame(
            'leave:attendance:2026-07:42:vacation_hours',
            PayrollImportAbsenceCompensationMaterializer::externalId('2026-07', 42, 'vacation_hours'),
        );
    }

    /**
     * Krácení bere každou nepřítomnost, ale ne svátek, odpracované hodiny,
     * pracovní cestu, práci z domova ani fond.
     */
    public function testImportSummaryHoursMapToWageReplacementTitles(): void
    {
        $byTitle = PayrollWageProrationService::replacedMinutesFromImportSummary([
            'worked_hours' => 120_000,
            'fund_hours' => 176_000,
            'holiday_hours' => 8_000,
            'business_trip_hours' => 8_000,
            'home_office_hours' => 8_000,
            'vacation_hours' => 16_000,
            'doctor_hours' => 2_500,
            'obstacle_employee_hours' => 1_000,
            'obstacle_employer_hours' => 8_000,
            'sick_hours' => 24_000,
            'care_hours' => 4_000,
            'paternity_hours' => 4_000,
            'unpaid_leave_hours' => 2_000,
            'unexcused_hours' => 1_000,
            'compensatory_time_off_hours' => 3_000,
        ]);

        self::assertSame([
            'vacation' => 960,
            'sickness_compensation' => 1_440,
            'state_benefit' => 480,
            'paid_obstacle' => 150 + 60 + 480,
            'unpaid' => 360,
        ], $byTitle);
    }

    public function testSummaryWithoutAbsenceHasNothingToReplace(): void
    {
        self::assertSame([], PayrollWageProrationService::replacedMinutesFromImportSummary([
            'worked_hours' => 176_000,
            'fund_hours' => 176_000,
            'vacation_hours' => 0,
        ]));
    }
}
