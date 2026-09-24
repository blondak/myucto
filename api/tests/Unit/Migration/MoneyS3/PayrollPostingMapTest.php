<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Migration\MoneyS3;

use MyInvoice\Service\Migration\MoneyS3\Ms3Backup;
use MyInvoice\Service\Migration\MoneyS3\MoneyS3PayrollLedger;
use MyInvoice\Service\Migration\MoneyS3\MoneyS3PayrollPostingMap;
use MyInvoice\Service\Migration\MoneyS3\MoneyS3PayrollTotals;
use MyInvoice\Service\Migration\MoneyS3\PayrollImporter;
use MyInvoice\Service\Payroll\Migration\PayrollPostingMapProposalBuilder;
use MyInvoice\Tests\Fixtures\MoneyS3\SyntheticAgenda;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Mzdové zápisy deníku Money: význam podle druhu mzdového dokladu, u dokladů bez druhu
 * podle páru účtů, návrh kontací z posledního roku a firemní měsíční úhrny.
 */
final class PayrollPostingMapTest extends TestCase
{
    /** @return iterable<string,array{array<string,mixed>,?string,?string}> řádek, význam, účet MD návrhu */
    public static function lines(): iterable
    {
        $coded = static fn (int $code, string $md, string $d, string $text = ''): array => ['flagged' => true, 'code' => $code, 'debit' => $md, 'credit' => $d, 'amount' => 100.0, 'text' => $text];
        $plain = static fn (string $md, string $d, string $text = ''): array => ['flagged' => false, 'code' => null, 'debit' => $md, 'credit' => $d, 'amount' => 100.0, 'text' => $text];

        yield 'druh 3 hrubé mzdy' => [$coded(3, '521000', '331000'), 'employment_gross', '521000'];
        yield 'druh 13 další předpis' => [$coded(13, '521000', '331000'), 'employment_gross', '521000'];
        yield 'druh 7 zaměstnanec' => [$coded(7, '331000', '336200'), 'employee_social', '331000'];
        yield 'druh 7 zaměstnavatel' => [$coded(7, '524100', '336200'), 'employer_social', '524100'];
        yield 'druh 10 zaměstnanec' => [$coded(10, '331000', '336100'), 'employee_health', '331000'];
        yield 'druh 10 zaměstnavatel' => [$coded(10, '524200', '336100'), 'employer_health', '524200'];
        yield 'druh 5 záloha podle textu' => [$coded(5, '331000', '342000', 'Záloha na daň'), 'advance_tax', '331000'];
        yield 'druh 5 srážková podle textu' => [$coded(5, '331000', '342000', 'Srážková daň'), 'withholding_tax', '331000'];
        yield 'druh 5 bez vodítka' => [$coded(5, '331000', '342000', 'Odvod FÚ'), null, '331000'];
        yield 'druh 14 exekuce' => [$coded(14, '331000', '379000', 'Exekuce'), 'enforcement_deductions', '331000'];
        yield 'druh 14 ostatní srážka' => [$coded(14, '331000', '379000', 'Spoření'), 'other_deductions', '331000'];
        yield 'druh 15 jen do přehledu' => [$coded(15, '548000', '379100'), null, '548000'];
        yield 'druh 17 cestovné' => [$coded(17, '512000', '331000'), 'travel_expense', '512000'];
        yield 'zápočet zálohy obráceně' => [$coded(1, '331000', '335000'), 'employee_receivable', '335000'];
        yield 'storno s prohozenými stranami' => [$coded(3, '331000', '521000'), 'employment_gross', '521000'];
        yield 'bez druhu hrubé mzdy' => [$plain('521000', '331000'), 'employment_gross', '521000'];
        yield 'bez druhu jednatel' => [$plain('523000', '366000'), 'partner_gross', '523000'];
        yield 'bez druhu pojistné podle názvu' => [$plain('331000', '336300'), 'employee_social', '331000'];
        yield 'bez druhu pojistné bez vodítka' => [$plain('524000', '336900'), null, '524000'];
    }

    /** @param array<string,mixed> $line */
    #[DataProvider('lines')]
    public function testConceptFromKindAndPair(array $line, ?string $concept, ?string $debit): void
    {
        $result = MoneyS3PayrollPostingMap::classify($line, [], ['336300' => 'Sociální pojištění']);
        self::assertNotNull($result);
        self::assertSame($concept, $result['concept']);
        self::assertSame($debit, $result['debit']);
    }

    public function testNonPayrollAndTransfersAreSkipped(): void
    {
        self::assertNull(MoneyS3PayrollPostingMap::classify(['flagged' => true, 'code' => 1, 'debit' => '331000', 'credit' => '331001', 'amount' => 1.0]),
            'Přeúčtování na analytiku zaměstnance není podklad pro předkontaci.');
        self::assertNull(MoneyS3PayrollPostingMap::classify(['flagged' => false, 'code' => null, 'debit' => '331000', 'credit' => '221001', 'amount' => 1.0]),
            'Výplata z banky mzdový pár není.');
        self::assertNull(MoneyS3PayrollPostingMap::classify(['flagged' => false, 'code' => null, 'debit' => '548000', 'credit' => '379100', 'amount' => 1.0]),
            'Doklad bez příznaku s nemzdovým párem se do přehledu nedostane.');
    }

    /** Analytika 336 z dokladů s druhem rozliší pojištění i u dokladů bez druhu. */
    public function testInsuranceAccountLearnedFromCodedDocuments(): void
    {
        $ledger = MoneyS3PayrollLedger::fromLines([
            ['year' => 2025, 'flagged' => true, 'code' => 7, 'debit' => '331000', 'credit' => '336200', 'amount' => 10.0],
            ['year' => 2025, 'flagged' => true, 'code' => 10, 'debit' => '331000', 'credit' => '336100', 'amount' => 10.0],
            ['year' => 2025, 'flagged' => true, 'code' => 10, 'debit' => '331000', 'credit' => '336500', 'amount' => 10.0],
            ['year' => 2025, 'flagged' => true, 'code' => 7, 'debit' => '524000', 'credit' => '336500', 'amount' => 10.0],
        ]);
        $insurance = MoneyS3PayrollPostingMap::insuranceAccounts($ledger);
        self::assertSame(['336200' => 'social', '336100' => 'health'], $insurance, 'Analytika použitá pro obojí se neučí.');
        self::assertSame('employer_health', MoneyS3PayrollPostingMap::classify(
            ['flagged' => false, 'code' => null, 'debit' => '524000', 'credit' => '336100', 'amount' => 1.0], $insurance)['concept']);
    }

    public function testProposalFromLastYearOnly(): void
    {
        $ledger = MoneyS3PayrollLedger::fromLines([
            ['year' => 2016, 'debit' => '521100', 'credit' => '331100', 'amount' => 500.0, 'month' => '2016-05'],
            ['year' => 2025, 'flagged' => true, 'code' => 3, 'debit' => '521000', 'credit' => '331000', 'amount' => 1000.0, 'cost_center' => 'REZIE', 'text' => 'Hrubé mzdy'],
            ['year' => 2025, 'flagged' => true, 'code' => 3, 'debit' => '521000', 'credit' => '331000', 'amount' => -50.0, 'cost_center' => 'VYROBA', 'text' => 'Oprava'],
        ]);
        $map = MoneyS3PayrollPostingMap::fromLedger($ledger);
        self::assertSame(2025, $map->year);
        self::assertSame('money_s3', $map->sourceKey());
        $rows = $map->postingRows();
        self::assertCount(1, $rows);
        self::assertSame(['521.000', '331.000', 2, 105000, ['REZIE', 'VYROBA']],
            [$rows[0]->debitAccount, $rows[0]->creditAccount, $rows[0]->lineCount, $rows[0]->amountMinor, $rows[0]->costCenters]);
    }

    /**
     * Celá cesta nad syntetickou zálohou: čtení mzdových dokladů, návrh a úhrny.
     */
    public function testSyntheticAgendaWithPayroll(): void
    {
        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ms3_payroll_' . bin2hex(random_bytes(5));
        try {
            foreach (SyntheticAgenda::filesWithPayroll() as $path => $content) {
                $full = $dir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
                if (!is_dir(dirname($full))) {
                    mkdir(dirname($full), 0755, true);
                }
                file_put_contents($full, $content);
            }
            $ledger = MoneyS3PayrollLedger::read(Ms3Backup::open($dir), ['ROK.001' => 2024, 'ROK.002' => 2025]);
        } finally {
            $this->removeTree($dir);
        }
        self::assertSame('2020-10', $ledger->lastPersonPeriod);
        self::assertSame('2025-12', $ledger->lastDataPeriod);
        self::assertSame('2025-03', PayrollImporter::lastPayrollPeriod($ledger));

        $proposal = PayrollPostingMapProposalBuilder::build('money_s3', MoneyS3PayrollPostingMap::fromLedger($ledger)->postingRows(),
            ['521.000', '331.000', '336.100', '336.200', '342.100', '379.000', '524.100', '524.200', '548.000', '379.100'], []);
        $keys = [];
        foreach ($proposal['keys'] as $key) {
            $keys[$key['key']] = [$key['status'], $key['suggested_code']];
        }
        $expected = [
            'employment_gross_debit' => ['unambiguous', '521.000'],
            'employment_gross_credit' => ['unambiguous', '331.000'],
            'social_insurance_credit' => ['unambiguous', '336.200'],
            'health_insurance_credit' => ['unambiguous', '336.100'],
            'employer_insurance_debit' => ['conflict', null],
            'income_tax_credit' => ['unambiguous', '342.100'],
            'enforcement_deductions_credit' => ['unambiguous', '379.000'],
            'withholding_tax_credit' => ['missing', null],
        ];
        $actual = array_intersect_key($keys, $expected);
        ksort($expected);
        ksort($actual);
        self::assertSame($expected, $actual);
        self::assertSame([['548.000', '379.100']], array_map(static fn (array $u): array => [$u['debit_code'], $u['credit_code']], $proposal['unmapped']));

        $totals = MoneyS3PayrollTotals::fromLedger($ledger);
        self::assertSame(['2025-01', '2025-02', '2025-03'], array_column($totals, 'period'));
        $feb = $totals[1];
        self::assertSame([30000.0, 2130.0, 1350.0, 7440.0, 2700.0, 3810.0, 0.0, 1000.0, 21710.0, 4810.0, 3810.0, true], [
            $feb['gross'], $feb['employee_social'], $feb['employee_health'], $feb['employer_social'], $feb['employer_health'],
            $feb['advance_tax'], $feb['withholding_tax'], $feb['deductions'], $feb['net_payable'], $feb['dpfo'], $feb['dpfo_remitted'], $feb['tax_ok'],
        ], 'Záloha z dokladů se porovnává s odvodem (sražené zálohy minus přeplatky z ročního zúčtování).');
        self::assertFalse($totals[2]['tax_ok'], 'Březnový odvod v Money na doklady nesedí.');
    }

    /**
     * Přeplatky z ročního zúčtování převyšují sražené zálohy: mzdový doklad daně je
     * záporný, odvod ve vyúčtování nula. Doklad se porovnává se zálohami po přeplatcích.
     */
    public function testNegativeTaxAfterAnnualRefundsMatchesStatement(): void
    {
        $ledger = MoneyS3PayrollLedger::fromLines([
            ['year' => 2025, 'period' => '2025-02', 'flagged' => true, 'code' => 5, 'debit' => '342100', 'credit' => '331000', 'amount' => 1000.0,
                'text' => 'Záloha na daň'],
        ], [], ['2025-02' => ['dpfo' => 2000.0, 'refunds' => 3000.0, 'remitted' => 0.0]]);
        $month = MoneyS3PayrollTotals::fromLedger($ledger)[0];
        self::assertSame([-1000.0, -1000.0, 0.0, true], [$month['advance_tax'], $month['dpfo_net'], $month['dpfo_remitted'], $month['tax_ok']]);
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($dir);
    }
}
