<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Migration;

use MyInvoice\Service\Payroll\Migration\PayrollTakeoverMonth;
use MyInvoice\Service\Payroll\Migration\PayrollTakeoverYear;
use PHPUnit\Framework\TestCase;

/**
 * Čtecí rozhraní převzatých mezd (PAM-09).
 *
 * Testuje se to, na co se ptají navazující sestavy: kde je hranice mezi
 * převzatým a spočítaným měsícem, který měsíc chybí odnikud a který je z obou
 * stran. Rok přechodu se bez správné odpovědi na tyhle tři otázky vykáže
 * dvakrát, nebo vůbec.
 */
final class PayrollTakeoverYearTest extends TestCase
{
    /** @param array<string,mixed> $overrides */
    private static function month(string $period, array $overrides = []): PayrollTakeoverMonth
    {
        return PayrollTakeoverMonth::fromRow([
            'period' => $period,
            'source' => 'other',
            'external_person_ref' => 'employee:7',
            'external_relationship_ref' => 'employment:11',
            'employee_id' => 7,
            'employment_id' => 11,
            'relationship_start_date' => '2026-01-15',
            'relationship_end_date' => null,
            'relation_type' => 'employment',
            'activity_code' => '1',
            'pension_participation' => 1,
            'insurance_days' => 31,
            'excluded_days' => 0,
            'worked_days_hundredths' => 2100,
            'worked_minutes' => 10080,
            'gross_minor' => 4_000_000,
            'net_minor' => 3_200_000,
            'deductions_minor' => 100_000,
            'net_payable_minor' => 3_100_000,
            'social_base_minor' => 4_000_000,
            'health_base_minor' => 4_000_000,
            'employee_social_minor' => 284_000,
            'employee_health_minor' => 180_000,
            'employer_social_minor' => 992_000,
            'employer_health_minor' => 360_000,
            'advance_tax_minor' => 300_000,
            'withholding_tax_minor' => 0,
            'tax_bonus_minor' => 0,
            'payout_date' => null,
            'import_reference' => null,
            ...$overrides,
        ]);
    }

    private static function year(
        array $months,
        array $calculated,
        ?string $startPeriod = '2026-08',
    ): PayrollTakeoverYear {
        return new PayrollTakeoverYear(1, 2026, $startPeriod, $months, $calculated, 7, null);
    }

    /** Hranicí je `start_period`, a je ostrá — měsíc rovný startu už počítá MyÚčto. */
    public function testHistoricalBoundaryIsTheModuleStartPeriod(): void
    {
        $year = self::year([self::month('2026-07')], ['2026-08']);

        self::assertTrue($year->isHistorical('2026-07'));
        self::assertFalse($year->isHistorical('2026-08'));
        self::assertFalse($year->isHistorical('2026-09'));
    }

    /** Bez nastaveného startu se nic neoznačuje jako historické. */
    public function testWithoutStartPeriodNothingIsHistorical(): void
    {
        $year = self::year([self::month('2026-07')], [], null);

        self::assertFalse($year->isHistorical('2026-07'));
    }

    /** Převzatý měsíc se pozná od spočítaného a měsíc z obou stran od obou. */
    public function testPresenceSeparatesTakeoverFromCalculated(): void
    {
        $year = self::year(
            [self::month('2026-06'), self::month('2026-07')],
            ['2026-07', '2026-08'],
        );

        self::assertSame(PayrollTakeoverYear::PRESENCE_TAKEOVER_ONLY, $year->presence('2026-06'));
        self::assertSame(PayrollTakeoverYear::PRESENCE_BOTH, $year->presence('2026-07'));
        self::assertSame(PayrollTakeoverYear::PRESENCE_CALCULATED_ONLY, $year->presence('2026-08'));
        self::assertSame(PayrollTakeoverYear::PRESENCE_NONE, $year->presence('2026-09'));
        self::assertSame(['2026-07'], $year->overlappingPeriods());
    }

    /** „Chybí mi měsíc X" — a mimo trvání vztahu se nic nehlásí. */
    public function testMissingPeriodsRespectTheRequestedSpan(): void
    {
        $year = self::year(
            [self::month('2026-01'), self::month('2026-02'), self::month('2026-04')],
            ['2026-05'],
        );

        self::assertSame(
            ['2026-03', '2026-06', '2026-07', '2026-08', '2026-09', '2026-10', '2026-11', '2026-12'],
            $year->missingPeriods(),
        );
        // Interval trvání vztahu: leden až květen, chybí jen březen.
        self::assertSame(['2026-03'], $year->missingPeriods('2026-01', '2026-05'));
        // Hranice jdou zadat i jako datum.
        self::assertSame(['2026-03'], $year->missingPeriods('2026-01-15', '2026-05-31'));
    }

    /** Souběžné vztahy: peníze se sčítají, kalendářní dny ne. */
    public function testPersonTotalsSumMoneyButNotDays(): void
    {
        $year = self::year([
            self::month('2026-03'),
            self::month('2026-03', [
                'external_relationship_ref' => 'employment:12',
                'employment_id' => 12,
                'gross_minor' => 1_000_000,
                'insurance_days' => 31,
                'excluded_days' => 5,
            ]),
        ], []);

        $totals = $year->personMonthTotals('2026-03', 7);

        self::assertSame(5_000_000, $totals['gross_minor']);
        self::assertSame(31, $totals['insurance_days'], 'Dny dvou souběžných vztahů se nesmí sečíst.');
        self::assertSame(5, $totals['excluded_days']);
    }

    /** Kód ELDP je druh činnosti plus `++`; nedoložitelný druh dá `null`. */
    public function testEldpCodeOnlyForActivityCodeOneToNine(): void
    {
        self::assertSame('1++', self::month('2026-01')->eldpCode());
        self::assertSame('9++', self::month('2026-01', ['activity_code' => '9'])->eldpCode());
        self::assertNull(self::month('2026-01', ['activity_code' => '10'])->eldpCode());
        self::assertNull(self::month('2026-01', ['activity_code' => null])->eldpCode());
    }

    /** Doba pojištění v měsíci je oříznutá trváním vztahu. */
    public function testInsuranceSpanIsClippedByRelationshipDates(): void
    {
        self::assertSame(['2026-01-15', '2026-01-31'], self::month('2026-01')->insuranceSpan());
        self::assertSame(['2026-02-01', '2026-02-28'], self::month('2026-02')->insuranceSpan());
        self::assertSame(
            ['2026-03-01', '2026-03-10'],
            self::month('2026-03', ['relationship_end_date' => '2026-03-10'])->insuranceSpan(),
        );
        self::assertNull(
            self::month('2026-04', ['relationship_end_date' => '2026-03-10'])->insuranceSpan(),
            'Měsíc mimo trvání vztahu nemá dobu pojištění.',
        );
    }

    /** Řádky se dají vytáhnout za měsíc i za pracovní vztah. */
    public function testRowsCanBeFilteredByPeriodAndEmployment(): void
    {
        $year = self::year([
            self::month('2026-01'),
            self::month('2026-01', ['external_relationship_ref' => 'employment:12', 'employment_id' => 12]),
            self::month('2026-02'),
        ], []);

        self::assertCount(2, $year->forPeriod('2026-01'));
        self::assertCount(2, $year->forEmployment(11));
        self::assertCount(1, $year->forEmployment(12));
        self::assertSame(['2026-01', '2026-02'], $year->takeoverPeriods());
    }
}
