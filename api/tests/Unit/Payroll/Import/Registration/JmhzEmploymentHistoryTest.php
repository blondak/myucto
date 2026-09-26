<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Import\Registration;

use MyInvoice\Service\Payroll\Import\Jmhz\JmhzBatch;
use MyInvoice\Service\Payroll\Import\Jmhz\JmhzBatchItem;
use MyInvoice\Service\Payroll\Import\Jmhz\JmhzDerivedRegistrations;
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

    /**
     * Hlášení nese jen příznak srážek, ne jejich výši ani druh. Když ho má
     * poslední převzatý měsíc, převzetí založí úkol na vztahu; srážka, která
     * skončila dřív, úkol nezakládá.
     */
    public function testDeductionsFlagInTheLastTakenOverMonthBecomesAFollowUp(): void
    {
        $row = ['id' => 7, 'code' => 'ZAM-7', 'start_date' => '2026-01-01', 'actual_start_date' => '2026-01-01', 'end_date' => null, 'relation_type' => 'employment'];
        $ongoing = $this->history([
            [2026, 1, [$this->a()]],
            [2026, 2, [$this->a(['deductions_recorded' => true])]],
        ]);
        $ended = $this->history([
            [2026, 1, [$this->a(['deductions_recorded' => true])]],
            [2026, 2, [$this->a()]],
        ]);

        self::assertSame(
            [JmhzPayrollTakeover::DEDUCTIONS_FOLLOW_UP],
            JmhzPayrollTakeover::record($ongoing, 'ppv:' . self::PPV_A, 3, $row, '2026-09')->employment->followUps,
        );
        self::assertSame([], JmhzPayrollTakeover::record($ended, 'ppv:' . self::PPV_A, 3, $row, '2026-09')->employment->followUps);
        self::assertSame(
            [],
            JmhzPayrollTakeover::record($ongoing, 'ppv:' . self::PPV_A, 3, $row, '2026-02')->employment->followUps,
            'Měsíc, který už počítá MyÚčto, se nepřebírá.',
        );
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

    /**
     * DPP bez účasti na pojištění, jak ji hlásí cizí program: bez druhu činnosti
     * a bez kódu ELDP. Dřív z ní nešlo určit druh vztahu a věta se zablokovala.
     */
    public function testUninsuredAgreementIsDerivedAsDppWithDpcChoiceUnderSmallScaleLimit(): void
    {
        $small = $this->batchFromXml([
            JmhzReportFixtures::uninsuredAgreement($this->report(1, [$this->a(), $this->concurrent()]), self::PPV_B, 3_960),
            JmhzReportFixtures::uninsuredAgreement($this->report(2, [$this->a(), $this->concurrent()]), self::PPV_B, 3_960),
        ]);
        $history = $small->history();
        $form = $history->latest('ppv:' . self::PPV_B)?->form;
        self::assertNotNull($form);
        self::assertSame(['code' => null, 'insurance_days' => 0, 'excluded_days' => 0], $form->eldp);
        self::assertSame(3_960, $form->uninsuredIncome);
        self::assertNull($form->workload(), 'Týdenní doba 99 je „neuvedeno", ne 99 hodin.');
        self::assertNull($history->activityCode('ppv:' . self::PPV_B));
        self::assertSame(
            ['relation_type' => 'dpp', 'options' => ['dpp', 'dpc'], 'max_income' => 3_960],
            $history->uninsuredAgreement('ppv:' . self::PPV_B),
        );
        self::assertNull($history->uninsuredAgreement('ppv:' . self::PPV_A), 'Účastný pracovní poměr dohodou není.');

        $derived = JmhzDerivedRegistrations::build($small, $history, [])['records'];
        $byPpv = array_column(array_map(static fn ($r): array => ['ppv' => $r->employmentIdentifier, 'r' => $r], $derived), 'r', 'ppv');
        self::assertSame('dpp', $byPpv[self::PPV_B]->relationType());
        self::assertSame(['dpp', 'dpc'], $byPpv[self::PPV_B]->relationTypeOptions);
        self::assertSame('employment', $byPpv[self::PPV_A]->relationType());

        $large = $this->batchFromXml([
            JmhzReportFixtures::uninsuredAgreement($this->report(1, [$this->a(), $this->concurrent()]), self::PPV_B, 8_000),
        ])->history();
        self::assertSame(['dpp'], $large->uninsuredAgreement('ppv:' . self::PPV_B)['options'] ?? null,
            'Neúčastný příjem nad hranicí malého rozsahu má jen DPP.');
    }

    /**
     * Účast na důchodovém pojištění = kód ELDP nebo vyměřovací základ, ne počet dnů.
     * Dřív byl měsíc celý v dávkách (kód, nula dnů) „bez účasti" a pracující
     * důchodce bez ELDP s pojistným konfliktem.
     */
    public function testPensionParticipationFollowsEldpCodeOrAssessmentBase(): void
    {
        $batch = $this->batchFromXml([
            JmhzReportFixtures::eldpOnBenefits(
                JmhzReportFixtures::withoutEldp($this->report(3, [$this->a(), $this->b(['insurance_from' => '2026-03-10'])]), self::PPV_B),
                self::PPV_A,
                31,
            ),
            JmhzReportFixtures::uninsuredAgreement($this->report(4, [$this->a(), $this->concurrent()]), self::PPV_B, 3_960),
        ]);
        $byPpv = [];
        foreach ($batch->effective() as $item) {
            $byPpv[$item->period() . '|' . $item->form->employmentIdentifier] = $item;
        }

        $row = static fn (int $id): array => ['id' => $id, 'start_date' => '2026-01-01', 'actual_start_date' => null, 'end_date' => null, 'relation_type' => 'employment'];
        $facts = static fn (JmhzBatchItem $item, int $id): array => [
            JmhzPayrollTakeover::totals($item, [$item], 3, $row($id), '1')->facts->pensionParticipation,
            JmhzPayrollTakeover::totals($item, [$item], 3, $row($id), '1')->facts->insuranceDays,
            JmhzPayrollTakeover::totals($item, [$item], 3, $row($id), '1')->facts->excludedDays,
        ];

        $benefits = $byPpv['2026-03|' . self::PPV_A];
        self::assertSame(['code' => '1++', 'insurance_days' => 0, 'excluded_days' => 31], $benefits->form->eldp,
            'Vyloučené dny jen v podpoložkách (bez úhrnu 10357) se sečtou.');
        self::assertSame([true, 0, 31], $facts($benefits, 1), 'Měsíc v dávkách s kódem ELDP je doba účasti.');

        $pensioner = $byPpv['2026-03|' . self::PPV_B];
        self::assertSame(['code' => null, 'insurance_days' => 0, 'excluded_days' => 0], $pensioner->form->eldp);
        self::assertSame([true, 22, 0], $facts($pensioner, 2), 'Důchodce bez ELDP s pojistným: dny účasti = trvání pojištění 10.–31. 3.');

        $agreement = $byPpv['2026-04|' . self::PPV_B];
        self::assertSame([false, 0, 0], $facts($agreement, 2));
        self::assertSame([false, 0], JmhzPayrollTakeover::pensionInsurance($agreement));
    }

    /** Nástup z exportu zaměstnanců ČSSZ má přednost před odhadem z prvního hlášeného měsíce. */
    public function testDeclaredStartFromCsszExportReplacesEstimate(): void
    {
        $batch = $this->batch([[2026, 1, [$this->a()]]]);
        self::assertTrue($batch->history()->start('ppv:' . self::PPV_A)['needs_check'] ?? false);

        $batch->declareStart('ppv:' . self::PPV_A, '2019-05-01');
        self::assertSame(
            ['on' => '2019-05-01', 'source' => JmhzEmploymentHistory::START_CSSZ_EXPORT, 'period' => '2026-01', 'needs_check' => false],
            $batch->history()->start('ppv:' . self::PPV_A),
        );
    }

    /** @param list<array<string,mixed>> $people */
    private function report(int $month, array $people): string
    {
        return JmhzReportFixtures::report($people, 2026, $month, ['guid_seed' => $month]);
    }

    /** DPP téže osoby jako `a()` vedle pracovního poměru (souběh). */
    private function concurrent(): array
    {
        return $this->b(['primary' => false, 'oic' => RegistrationXmlFixtures::oic(7), 'wage' => 3_960, 'taxable' => 3_960, 'social_base' => 0]);
    }

    /** @param list<string> $xmls */
    private function batchFromXml(array $xmls): JmhzBatch
    {
        $reader = new JmhzReportReader();
        $items = [];
        foreach ($xmls as $index => $xml) {
            $file = $reader->read($xml);
            self::assertFalse($file->lenient, implode(' ', $file->warnings));
            $sha = hash('sha256', $xml);
            foreach ($file->forms as $form) {
                $items[] = new JmhzBatchItem(substr($sha, 0, 16) . ':' . $form->position, $file, $form, "jmhz-{$index}.xml", $sha, $index);
            }
        }

        return JmhzBatch::build($items, []);
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
