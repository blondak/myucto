<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission;

use MyInvoice\Service\Payroll\Submission\Eldp\EldpExcludedPeriodDeriver;
use PHPUnit\Framework\TestCase;

/**
 * Vyloučené dny podle § 18 odst. 7 zákona č. 187/2006 Sb. (10366 a rozpad
 * 10473–10475).
 *
 * Jiná veličina než vyloučené DOBY podle § 16 odst. 4 zákona č. 155/1995 Sb.:
 * ty krátí osobní vyměřovací základ důchodu, tyhle rozhodné období denního
 * vyměřovacího základu nemocenských dávek. Neplacené volno není omluvným
 * důvodem podle § 16 odst. 4, ale vyloučeným dnem podle § 18 odst. 7 ANO —
 * bez něj počítá ČSSZ nemocenskou i z měsíce, ve kterém zaměstnanec
 * nevydělával.
 */
final class EldpSection18ExcludedDaysTest extends TestCase
{
    public function testUnpaidLeaveBecomesExcusedAbsenceDays(): void
    {
        $derived = (new EldpExcludedPeriodDeriver())->deriveSection18(
            [$this->absence(1, 'unpaid_leave', '2026-08-10', '2026-08-12')],
            '2026-08-01',
            '2026-08-31',
        );

        self::assertTrue($derived['derivable']);
        self::assertSame(3, $derived['total']);
        self::assertSame(
            [
                'omluvenaNepritomnost' => 3,
                'pracovniNeschopnost' => 0,
                'vyplaceniDavek' => 0,
            ],
            $derived['components'],
        );
        self::assertSame(3, array_sum($derived['components']));
    }

    /**
     * Pracovní volno bez náhrady mzdy (výkon veřejné funkce, neplacená
     * překážka) je omluvená nepřítomnost bez náhrady příjmu — vyloučený den
     * § 18 odst. 7 stejně jako neplacené volno. Vyloučenou DOBOU podle § 16
     * odst. 4 písm. a) ale není a měsíc bez příjmu dobou pojištění nezůstane.
     */
    public function testUnpaidPublicFunctionAndObstacleAreExcusedAbsenceDays(): void
    {
        $absences = [
            $this->absence(1, 'public_function', '2026-08-10', '2026-08-11'),
            $this->absence(2, 'employee_obstacle_unpaid', '2026-08-13', '2026-08-13'),
        ];
        $deriver = new EldpExcludedPeriodDeriver();

        $section18 = $deriver->deriveSection18($absences, '2026-08-01', '2026-08-31');
        self::assertTrue($section18['derivable']);
        self::assertSame(3, $section18['components']['omluvenaNepritomnost']);

        $excluded = $deriver->derive($absences, '2026-08-01', '2026-08-31', '2026-08');
        self::assertSame([], $excluded['blockers']);
        self::assertSame(0, $excluded['total']);

        self::assertSame(
            EldpExcludedPeriodDeriver::MONTH_OUTSIDE_INSURANCE,
            EldpExcludedPeriodDeriver::insuranceMonthStatus($absences, 0, '2026-08-01', '2026-08-31'),
        );
    }

    /**
     * Neplacené volno přesahující měsíc se ořízne na interval řezu — den mimo
     * dobu pojištění nemá z čeho být vyloučený.
     */
    public function testDaysAreClampedToTheReportedInterval(): void
    {
        $derived = (new EldpExcludedPeriodDeriver())->deriveSection18(
            [$this->absence(1, 'unpaid_leave', '2026-07-25', '2026-08-03')],
            '2026-08-01',
            '2026-08-31',
        );

        self::assertTrue($derived['derivable']);
        self::assertSame(3, $derived['total']);
    }

    /**
     * Dovolená ani neomluvená absence vyloučeným dnem nejsou: u dovolené
     * náhrada příjmu náleží, neomluvená absence není OMLUVENÁ nepřítomnost.
     */
    public function testPaidAndUnexcusedAbsencesAreNotExcludedDays(): void
    {
        $derived = (new EldpExcludedPeriodDeriver())->deriveSection18(
            [
                $this->absence(1, 'vacation', '2026-08-03', '2026-08-07'),
                $this->absence(2, 'unexcused', '2026-08-10', '2026-08-10'),
                $this->absence(3, 'employee_obstacle', '2026-08-12', '2026-08-12'),
            ],
            '2026-08-01',
            '2026-08-31',
        );

        self::assertTrue($derived['derivable']);
        self::assertSame(0, $derived['total']);
    }

    /**
     * Nemoc rozpad 10474/10475 rozhodnout nedovolí: dělicí čárou je čtrnáctý
     * kalendářní den podpůrčí doby, a ten `payroll_absences` neurčuje.
     * Rozpad se pak nevykazuje vůbec — část by tvrdila, že zbytek je nula.
     */
    public function testSicknessMakesTheBreakdownUnderivable(): void
    {
        $derived = (new EldpExcludedPeriodDeriver())->deriveSection18(
            [
                $this->absence(1, 'unpaid_leave', '2026-08-03', '2026-08-04'),
                $this->absence(2, 'dpn', '2026-08-10', '2026-08-20'),
            ],
            '2026-08-01',
            '2026-08-31',
        );

        self::assertFalse($derived['derivable']);
        self::assertSame(['dpn'], $derived['undecidable_types']);
    }

    /**
     * Rodičovskou dovolenou jmenují Pokyny MPSV k vyplnění MH 1.4.13 u 10473
     * výslovně jako omluvenou nepřítomnost bez náhrady příjmu. Bez vyloučených
     * dnů by ČSSZ počítala nemocenskou po návratu i z měsíců rodičovské.
     */
    public function testParentalLeaveBecomesExcusedAbsenceDays(): void
    {
        $derived = (new EldpExcludedPeriodDeriver())->deriveSection18(
            [$this->absence(1, 'parental', '2026-08-01', '2026-08-31')],
            '2026-08-01',
            '2026-08-31',
        );

        self::assertTrue($derived['derivable']);
        self::assertSame(31, $derived['total']);
        self::assertSame(
            [
                'omluvenaNepritomnost' => 31,
                'pracovniNeschopnost' => 0,
                'vyplaceniDavek' => 0,
            ],
            $derived['components'],
        );
    }

    /**
     * Návrat z rodičovské uprostřed měsíce: vyloučené jsou jen dny rodičovské
     * uvnitř měsíce, sečtené s ostatní omluvenou nepřítomností bez náhrady.
     */
    public function testParentalLeaveInPartOfTheMonthCountsOnlyItsDays(): void
    {
        $derived = (new EldpExcludedPeriodDeriver())->deriveSection18(
            [
                $this->absence(1, 'parental', '2026-06-15', '2026-08-16'),
                $this->absence(2, 'unpaid_leave', '2026-08-24', '2026-08-25'),
            ],
            '2026-08-01',
            '2026-08-31',
        );

        self::assertTrue($derived['derivable']);
        self::assertSame(18, $derived['total']);
        self::assertSame(18, $derived['components']['omluvenaNepritomnost']);
    }

    /** @return array<string,mixed> */
    private function absence(int $id, string $type, string $from, string $to): array
    {
        return [
            'id' => $id,
            'absence_type' => $type,
            'date_from' => $from,
            'date_to' => $to,
        ];
    }
}
