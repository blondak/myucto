<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Migration\Premier;

use MyInvoice\Service\Migration\Premier\PremierBackup;
use MyInvoice\Service\Migration\Premier\PremierJournal;
use MyInvoice\Service\Migration\Premier\PremierPayroll;
use MyInvoice\Tests\Fixtures\Premier\SyntheticPremierBackup;
use PHPUnit\Framework\TestCase;

/**
 * Zaměstnanci a mzdy ze syntetické zálohy PREMIER: osoba z `PER_MAIN` i ze snímků
 * `PERSON2`, druh vztahu, měsíční úhrny a rekonciliace proti zaúčtování v deníku.
 */
final class PremierPayrollTest extends TestCase
{
    private string $tmp = '';

    protected function tearDown(): void
    {
        if ($this->tmp !== '' && is_dir($this->tmp)) {
            foreach (scandir($this->tmp) ?: [] as $f) {
                if (is_file($this->tmp . DIRECTORY_SEPARATOR . $f)) {
                    unlink($this->tmp . DIRECTORY_SEPARATOR . $f);
                }
            }
            rmdir($this->tmp);
        }
    }

    public function testRelationsPeopleAndMonths(): void
    {
        $payroll = PremierPayroll::fromBackup($this->backup(['payroll' => true]));
        self::assertTrue($payroll->hasData());
        self::assertSame([], $payroll->missingTables);
        self::assertSame(
            [['1', 'statutory_body', 'Jana Fiktivní', '855101/0105', '2025-01-01', null, '111', 14],
                ['2', 'dpp', 'Petr Zkušební', '900315/0101', '2025-03-01', '2025-06-30', null, 4],
                ['3', 'employment', 'Karel Vzorový', '920620/0102', '2026-02-01', null, '201', 1]],
            array_map(static fn (array $r): array => [$r['personal_number'], $r['relation_type'], $r['full_name'], $r['birth_number'],
                $r['start'], $r['end'], $r['insurer_code'], count($r['months'])], $payroll->relations),
        );
        [$statutory, $dpp, $employee] = $payroll->relations;
        self::assertFalse($statutory['relation_type_derived']);
        self::assertSame('PER_MAIN|OS-A', $statutory['person_key']);
        self::assertSame('CISLO|2', $dpp['person_key'], 'Osoba bez karty PER_MAIN se páruje osobním číslem.');
        self::assertSame(['street_line' => 'Nová 5', 'city' => 'Praha', 'postal_code' => '11000', 'country_code' => 'CZ'], $dpp['residence'],
            'Platí poslední snímek PERSON2.');
        self::assertSame(['account' => SyntheticPremierBackup::BANK_ACCOUNT, 'bank_code' => SyntheticPremierBackup::BANK_CODE], $statutory['account']);
        self::assertSame(['2025-01-01' => 6000.0, '2026-01-01' => 6500.0], $statutory['wages']);
        self::assertTrue($employee['insurer_registered']);

        $january = $statutory['months']['2025-01'];
        self::assertSame([6000.0, 426.0, 270.0, 1488.0, 540.0, 0.0, 900.0, 4404.0, true, 31, false],
            [$january['gross'], $january['employee_social'], $january['employee_health'], $january['employer_social'], $january['employer_health'],
                $january['advance_tax'], $january['withholding_tax'], $january['net_payable'], $january['pension_participation'], $january['insurance_days'],
                $january['signed']], 'Odměna jednatelky je MZ_ODSTAT, ne MZ_HRUBA.');
        self::assertSame([false, 0, 500], [$dpp['months']['2025-03']['pension_participation'], $dpp['months']['2025-03']['insurance_days'],
            (int) round($dpp['months']['2025-03']['worked_days'] * 100)]);
        self::assertTrue($employee['months']['2026-02']['signed']);
        self::assertSame(2570.0, $employee['months']['2026-02']['non_refundable']);
    }

    /**
     * `KODPP_SO` je v zálohách prázdný a druh vztahu vychází z kategorie. Učeň (`UCN`)
     * dřív padal do výchozího pracovního poměru s příznakem odvození.
     */
    public function testApprenticeIsNotAnEmploymentRelation(): void
    {
        $relations = PremierPayroll::fromBackup($this->backup(['payroll' => true, 'payroll_detail' => true]))->relations;
        $apprentice = array_values(array_filter($relations, static fn (array $r): bool => $r['key'] === '4'))[0];
        self::assertSame([PremierPayroll::APPRENTICE, false], [$apprentice['relation_type'], $apprentice['relation_type_derived']]);
    }

    /**
     * Sjednaná mzda je v `SAZBA_MZ` podle `TYP_MZDY`; `MZDA_MES` je vyplněné jen někdy
     * a od sazby se může lišit. Hodinová sazba sjednanou měsíční mzdou není.
     */
    public function testAgreedWageComesFromRateByWageType(): void
    {
        $relations = PremierPayroll::fromBackup($this->backup(['payroll' => true, 'payroll_detail' => true]))->relations;
        $employee = array_values(array_filter($relations, static fn (array $r): bool => $r['key'] === '5'))[0];
        self::assertSame(['2025-01-01' => 30000.0, '2025-07-01' => 32000.0], $employee['wages']);
        self::assertSame([], $employee['hourly_wages']);
        $statutory = array_values(array_filter($relations, static fn (array $r): bool => $r['key'] === '1'))[0];
        self::assertSame(['2025-01-01' => 6000.0, '2026-01-01' => 6500.0], $statutory['wages'], 'Starší verze bez typu mzdy nesou MZDA_MES.');
    }

    public function testMonthTotalsMatchJournalPostings(): void
    {
        $backup = $this->backup(['payroll' => true]);
        $payroll = PremierPayroll::fromBackup($backup);
        $journal = new PremierJournal($backup);

        $totals = $payroll->monthTotals(SyntheticPremierBackup::YEAR1);
        self::assertCount(12, $totals);
        self::assertSame(['gross' => 9000.0, 'employee_insurance' => 696.0, 'employer_insurance' => 2028.0, 'tax' => 1350.0], $totals['2025-03'],
            'Jednatelka + DPP v jednom měsíci.');
        $months = PremierPayroll::reconcile($totals, PremierPayroll::ledgerTotals($journal, SyntheticPremierBackup::YEAR1));
        self::assertCount(12, $months);
        self::assertSame([], array_values(array_filter($months, static fn (array $m): bool => !$m['ok'])));

        $next = PremierPayroll::reconcile($payroll->monthTotals(SyntheticPremierBackup::YEAR2), PremierPayroll::ledgerTotals($journal, SyntheticPremierBackup::YEAR2));
        self::assertSame(['2026-01', '2026-02'], array_column($next, 'period'));
        self::assertTrue($next[1]['ok']);
        self::assertSame(3430.0 + 975.0, $next[1]['ledger']['tax'], 'Záloha na 342100 i srážková daň na 342200.');
    }

    public function testMismatchedMonthIsReported(): void
    {
        $backup = $this->backup(['payroll' => true, 'payroll_mismatch' => true]);
        $months = PremierPayroll::reconcile(
            PremierPayroll::fromBackup($backup)->monthTotals(SyntheticPremierBackup::YEAR1),
            PremierPayroll::ledgerTotals(new PremierJournal($backup), SyntheticPremierBackup::YEAR1),
        );
        $bad = array_values(array_filter($months, static fn (array $m): bool => !$m['ok']));
        self::assertCount(1, $bad);
        self::assertSame('2025-05', $bad[0]['period']);
        self::assertSame(['employer_insurance' => 40.0], $bad[0]['diffs']);
    }

    public function testLedgerOnlyMonthIsADifference(): void
    {
        $journal = PremierJournal::fromRows([
            ['INTER' => 1, 'DATUM' => '2025-02-28', 'DOKLAD' => 'MZ', 'CISLO' => '1', 'CASTKA' => 1000, 'MD' => '521100', 'DAL' => '331100'],
            // Záporná částka na stejných stranách je storno, ne mzda.
            ['INTER' => 2, 'DATUM' => '2025-02-28', 'DOKLAD' => 'MZ', 'CISLO' => '2', 'CASTKA' => -200, 'MD' => '521100', 'DAL' => '331100'],
            ['INTER' => 3, 'DATUM' => '2025-02-28', 'DOKLAD' => 'MZ', 'CISLO' => '3', 'CASTKA' => 50, 'MD' => '342100', 'DAL' => '331100'],
            ['INTER' => 4, 'DATUM' => '2025-02-28', 'DOKLAD' => 'BV', 'CISLO' => '4', 'CASTKA' => 800, 'MD' => '331100', 'DAL' => '221001'],
        ]);
        $ledger = PremierPayroll::ledgerTotals($journal, 2025);
        self::assertSame(['2025-02' => ['gross' => 800.0, 'employee_insurance' => 0.0, 'employer_insurance' => 0.0, 'tax' => -50.0]], $ledger,
            'Výplata z banky se nepočítá, bonus (342 → 331) snižuje daň.');
        $months = PremierPayroll::reconcile([], $ledger);
        self::assertFalse($months[0]['ok']);
        self::assertSame(['gross' => -800.0, 'tax' => 50.0], $months[0]['diffs']);
    }

    public function testBackupWithoutPayrollTables(): void
    {
        $payroll = PremierPayroll::fromBackup($this->backup([]));
        self::assertFalse($payroll->hasData());
        self::assertSame(['PERSONAL', 'MZDY'], $payroll->missingTables);
        self::assertSame([], $payroll->monthTotals(2025));
    }

    /** @param array<string,bool> $flags */
    private function backup(array $flags): PremierBackup
    {
        $this->tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'premier_pay_' . bin2hex(random_bytes(5));
        SyntheticPremierBackup::writeDir($this->tmp, false, $flags);
        return PremierBackup::open($this->tmp);
    }
}
