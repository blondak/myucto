<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Import\Registration;

use MyInvoice\Service\Payroll\Import\Jmhz\JmhzBatch;
use MyInvoice\Service\Payroll\Import\Jmhz\JmhzBatchItem;
use MyInvoice\Service\Payroll\Import\Jmhz\JmhzEmploymentHistory;
use MyInvoice\Service\Payroll\Import\Jmhz\JmhzPayrollTakeover;
use MyInvoice\Service\Payroll\Import\Jmhz\JmhzReportReader;
use PHPUnit\Framework\TestCase;

/**
 * Co řada syntetických měsíčních hlášení dokládá o vztahu (nástup, skončení,
 * úvazek, měsíční mzda) a jak se převede do kanonické podoby převzatých mezd.
 * XML vzniká skutečným serializérem aplikace ({@see JmhzReportFixtures}).
 */
final class JmhzEmploymentHistoryTest extends TestCase
{
    private const PPV_A = '200000000000000000101';
    private const PPV_B = '200000000000000000102';

    public function testReaderCarriesTakeoverFieldsOfTheForm(): void
    {
        $xml = JmhzReportFixtures::report([JmhzReportFixtures::person([
            'insurance_to' => '2026-03-20',
            'agreed_fund' => 84_000,
            'unworked' => [
                'unworked_total_millihours' => 24_000,
                'unworked_paid_millihours' => 24_000,
                'vacation_millihours' => 16_000,
                'dpn_with_employer_compensation_millihours' => 8_000,
            ],
        ])], 2026, 3);

        $form = (new JmhzReportReader())->read($xml)->forms[0];

        self::assertSame('2026-03-20', $form->insuranceTo);
        self::assertSame('1++', $form->eldp['code'] ?? null);
        self::assertSame(20, $form->eldp['insurance_days'] ?? null);
        self::assertSame(16_000, $form->leaveMillihours);
        self::assertSame(24_000, $form->unworkedMillihours);
        self::assertSame(8_000, $form->absenceMillihours);
        self::assertSame(40_000, $form->tariff);
        self::assertNotNull($form->employeeSocial);
        self::assertNotNull($form->netWage);
        self::assertFalse($form->deductionsRecorded);
        self::assertSame(['workload_basis_points' => 5_000, 'weekly_hours' => '20.00'], $form->workload());
    }

    public function testStartIsExactWhenInsuranceStartsInsideTheMonthOrThePreviousMonthIsComplete(): void
    {
        $history = $this->history([
            [2026, 1, [$this->a()]],
            [2026, 2, [$this->a(), $this->b(['insurance_from' => '2026-02-10'])]],
        ]);

        self::assertSame(
            ['on' => '2026-01-01', 'source' => JmhzEmploymentHistory::START_INSURANCE_FROM, 'period' => '2026-01', 'needs_check' => true],
            $history->start('ppv:' . self::PPV_A),
        );
        self::assertSame(
            ['on' => '2026-02-10', 'source' => JmhzEmploymentHistory::START_INSURANCE_FROM, 'period' => '2026-02', 'needs_check' => false],
            $history->start('ppv:' . self::PPV_B),
        );

        $later = $this->history([
            [2026, 1, [$this->a()]],
            [2026, 2, [$this->a(), $this->b()]],
        ]);
        self::assertFalse($later->start('ppv:' . self::PPV_B)['needs_check'] ?? true, 'Úplné lednové hlášení vztah B nenese, takže začal 1. února.');
    }

    public function testEndFollowsInsuranceEndOrAbsenceFromTheNextCompleteReport(): void
    {
        $history = $this->history([
            [2026, 1, [$this->a(), $this->b()]],
            [2026, 2, [$this->a(['insurance_to' => '2026-02-15']), $this->b()]],
            [2026, 3, [$this->b()]],
        ]);
        self::assertSame(
            ['on' => '2026-02-15', 'source' => JmhzEmploymentHistory::END_INSURANCE_TO, 'period' => '2026-02'],
            $history->end('ppv:' . self::PPV_A),
        );
        self::assertNull($history->end('ppv:' . self::PPV_B), 'Vztah trvá do posledního hlášeného měsíce.');

        $missing = $this->history([
            [2026, 1, [$this->a(), $this->b()]],
            [2026, 2, [$this->b()]],
        ]);
        self::assertSame(
            ['on' => '2026-01-31', 'source' => JmhzEmploymentHistory::END_MISSING_NEXT, 'period' => '2026-01'],
            $missing->end('ppv:' . self::PPV_A),
        );
    }

    public function testMonthlySalaryNeedsEqualTariffAcrossDifferentFundsWithoutLeaveOrSickness(): void
    {
        $full = ['unworked_total_millihours' => 0];
        $holiday = ['unworked_total_millihours' => 8_000, 'unworked_paid_millihours' => 8_000];
        $vacation = ['unworked_total_millihours' => 8_000, 'unworked_paid_millihours' => 8_000, 'vacation_millihours' => 8_000];
        $history = $this->history([
            [2026, 1, [$this->a(['standard_fund' => 168_000, 'agreed_fund' => 168_000, 'unworked' => $vacation, 'wage' => 38_000, 'taxable' => 38_000, 'base' => 38_000, 'social_base' => 38_000])]],
            [2026, 2, [$this->a(['standard_fund' => 160_000, 'agreed_fund' => 160_000, 'unworked' => $full])]],
            [2026, 3, [$this->a(['standard_fund' => 176_000, 'agreed_fund' => 176_000, 'unworked' => $holiday])]],
        ]);

        self::assertSame(['amount' => 40_000, 'period' => '2026-02'], $history->monthlySalary('ppv:' . self::PPV_A));

        $single = $this->history([
            [2026, 2, [$this->a(['unworked' => $full])]],
            [2026, 3, [$this->a(['unworked' => $vacation])]],
        ]);
        self::assertNull($single->monthlySalary('ppv:' . self::PPV_A), 'Jediný plně odpracovaný měsíc hodinovou mzdu nevyloučí.');
    }

    public function testCanonicalRecordCarriesDerivedFactsOnlyBeforeTheModuleStart(): void
    {
        $full = ['unworked_total_millihours' => 0];
        $history = $this->history([
            [2026, 1, [$this->a(['standard_fund' => 168_000, 'agreed_fund' => 168_000, 'unworked' => $full])]],
            [2026, 2, [$this->a(['standard_fund' => 160_000, 'agreed_fund' => 160_000, 'unworked' => $full + ['vacation_millihours' => 0]])]],
            [2026, 3, [$this->a(['insurance_to' => '2026-03-20', 'unworked' => ['unworked_total_millihours' => 16_000, 'unworked_paid_millihours' => 16_000, 'vacation_millihours' => 16_000]])]],
        ]);
        $row = ['id' => 7, 'code' => 'ZAM-7', 'start_date' => '2026-01-01', 'actual_start_date' => '2026-01-01', 'end_date' => null, 'relation_type' => 'employment'];

        $record = JmhzPayrollTakeover::record($history, 'ppv:' . self::PPV_A, 3, $row, '2026-04');
        $employment = $record->employment;

        self::assertSame('employee:3', $record->person->key);
        self::assertSame('2026-01-01', $employment->start);
        self::assertSame('2026-03-20', $employment->end);
        self::assertSame([['from' => '2026-01-01', 'amount' => 40_000.0, 'prorated' => false]], $employment->monthlyWages);
        self::assertSame([['period' => '2026-03', 'minutes' => 960]], $employment->leaveTaken);
        self::assertSame(self::PPV_A, $employment->idPpv);
        self::assertSame([1], array_column($employment->averages, 'quarter'));
        self::assertSame(238.1, $employment->averages[0]['hourly']);
        self::assertSame('Brno', $employment->workplace['work_place'] ?? null);

        $early = JmhzPayrollTakeover::record($history, 'ppv:' . self::PPV_A, 3, $row, '2026-03');
        self::assertNull($early->employment->end, 'Skončení v měsíci, který počítá MyÚčto, převzetí nezapisuje.');
        self::assertSame([], $early->employment->leaveTaken);
    }

    public function testMonthTotalsSplitPersonIncomeAcrossConcurrentEmployments(): void
    {
        $batch = $this->batch([[2026, 3, [
            $this->a(['wage' => 47_000, 'taxable' => 40_000, 'base' => 45_000, 'social_base' => 40_000]),
            $this->b(['primary' => false, 'wage' => 5_000, 'taxable' => 5_000, 'social_base' => 5_000, 'oic' => RegistrationXmlFixtures::oic(7)]),
        ]]]);
        $items = $batch->effective();
        self::assertCount(2, $items);
        $row = static fn (int $id): array => ['id' => $id, 'start_date' => '2026-01-01', 'actual_start_date' => null, 'end_date' => null, 'relation_type' => 'employment'];

        $primary = JmhzPayrollTakeover::totals($items[0], $items, 3, $row(1), '1');
        $secondary = JmhzPayrollTakeover::totals($items[1], $items, 3, $row(2), '1');

        self::assertSame(4_200_000, $primary->grossMinor, 'Vztah se souhrnnými daty nese i osvobozené příjmy osoby.');
        self::assertSame(500_000, $secondary->grossMinor);
        self::assertSame(4_700_000, $primary->grossMinor + $secondary->grossMinor);
        self::assertGreaterThan(0, $primary->advanceTaxMinor);
        self::assertSame(0, $secondary->advanceTaxMinor, 'Daň a čistá mzda jsou za osobu, nesou je jen souhrnná data.');
        self::assertSame(0, $secondary->netMinor);
        self::assertSame(500_000, $secondary->socialBaseMinor);
        self::assertSame(31, $primary->facts->insuranceDays);
        self::assertTrue($primary->facts->pensionParticipation);
        self::assertSame(168 * 60, $primary->facts->workedMinutes);
    }

    /** @param array<string,mixed> $o */
    private function a(array $o = []): array
    {
        return JmhzReportFixtures::person($o + ['employment_id' => 101, 'id_ppv' => self::PPV_A, 'children' => []]);
    }

    /** @param array<string,mixed> $o */
    private function b(array $o = []): array
    {
        return JmhzReportFixtures::person($o + [
            'employment_id' => 102,
            'id_ppv' => self::PPV_B,
            'oic' => RegistrationXmlFixtures::oic(11),
            'children' => [],
        ]);
    }

    /** @param list<array{0:int,1:int,2:list<array<string,mixed>>}> $months */
    private function history(array $months): JmhzEmploymentHistory
    {
        return $this->batch($months)->history();
    }

    /** @param list<array{0:int,1:int,2:list<array<string,mixed>>}> $months */
    private function batch(array $months): JmhzBatch
    {
        $reader = new JmhzReportReader();
        $items = [];
        foreach ($months as $index => [$year, $month, $people]) {
            $xml = JmhzReportFixtures::report($people, $year, $month, ['guid_seed' => $index + 1]);
            $file = $reader->read($xml);
            $sha = hash('sha256', $xml);
            foreach ($file->forms as $form) {
                $items[] = new JmhzBatchItem(substr($sha, 0, 16) . ':' . $form->position, $file, $form, "jmhz-{$month}.xml", $sha, $index);
            }
        }

        return JmhzBatch::build($items, []);
    }
}
