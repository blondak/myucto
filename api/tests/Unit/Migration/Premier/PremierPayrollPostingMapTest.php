<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Migration\Premier;

use MyInvoice\Service\Migration\Premier\PremierJournal;
use MyInvoice\Service\Migration\Premier\PremierPayrollPostingMap;
use MyInvoice\Service\Payroll\Migration\PayrollLegacyPostingRow;
use PHPUnit\Framework\TestCase;

/**
 * Převzaté zaúčtování mezd z deníku PREMIER: význam ze dvojice účtů, pojištění a daň
 * rozlišené názvem analytiky, opravné zápisy s prohozenými stranami, nemzdové zápisy
 * mimo návrh.
 */
final class PremierPayrollPostingMapTest extends TestCase
{
    private const NAMES = [
        '336100' => 'Zúčtování sociálního pojištění',
        '336200' => 'Zúčtování zdravotního pojištění',
        '342100' => 'Záloha na daň ze závislé činnosti',
        '342200' => 'Srážková daň',
    ];

    public function testConceptsFromAccountPairsAndNames(): void
    {
        $rows = self::byReference(PremierPayrollPostingMap::fromJournal(PremierJournal::fromRows([
            self::row(1, 'Hrubá mzda', 40000, '521100', '331100'),
            self::row(2, 'Hrubá mzda', 30000, '521100', '331100', 7),
            self::row(3, 'Sociální pojištění', 2840, '331100', '336100'),
            self::row(4, 'Zdravotní pojištění', 1800, '331100', '336200'),
            self::row(5, 'Pojištění firma', 9920, '524100', '336100'),
            self::row(6, 'Daň', 3430, '331100', '342100'),
            self::row(7, 'Daň', 450, '331100', '342200'),
            self::row(8, 'Odměna společníka', 10000, '521100', '366100'),
            self::row(9, 'Exekuce', 500, '331100', '379100'),
            self::row(10, 'Výplata mezd', 60000, '331100', '221001'),
            // Oprava s prohozenými stranami: jde do téhož řádku, váha v absolutní hodnotě.
            self::row(11, 'Oprava mzdy', 1000, '331100', '521100'),
        ]), 2025, self::NAMES));

        self::assertSame(['employment_gross', 3, 7100000, ['7']], self::shape($rows['premier:PUB_UCTO:521100/331100']));
        self::assertSame('employee_social', $rows['premier:PUB_UCTO:331100/336100']->concept);
        self::assertSame('employee_health', $rows['premier:PUB_UCTO:331100/336200']->concept);
        self::assertSame('employer_social', $rows['premier:PUB_UCTO:524100/336100']->concept);
        self::assertSame('advance_tax', $rows['premier:PUB_UCTO:331100/342100']->concept);
        self::assertSame('withholding_tax', $rows['premier:PUB_UCTO:331100/342200']->concept);
        self::assertSame('partner_gross', $rows['premier:PUB_UCTO:521100/366100']->concept);
        self::assertSame('enforcement_deductions', $rows['premier:PUB_UCTO:331100/379100']->concept);
        self::assertSame(['521.100', '331.100'], [$rows['premier:PUB_UCTO:521100/331100']->debitAccount, $rows['premier:PUB_UCTO:521100/331100']->creditAccount]);
        self::assertArrayNotHasKey('premier:PUB_UCTO:331100/221001', $rows, 'Výplata mzdy není předkontace mezd.');
    }

    public function testInsuranceWithoutNameStaysUnmapped(): void
    {
        $rows = self::byReference(PremierPayrollPostingMap::fromJournal(PremierJournal::fromRows([
            self::row(1, 'Pojistné', 2840, '331100', '336900'),
            self::row(2, 'Daň', 100, '331100', '342900'),
        ]), 2025, self::NAMES));

        self::assertNull($rows['premier:PUB_UCTO:331100/336900']->concept);
        self::assertNull($rows['premier:PUB_UCTO:331100/342900']->concept);
    }

    public function testOtherYearIsIgnored(): void
    {
        $map = PremierPayrollPostingMap::fromJournal(PremierJournal::fromRows([self::row(1, 'Hrubá mzda', 1000, '521100', '331100', 0, '2024-12-31')]), 2025);

        self::assertSame([], $map->postingRows());
        self::assertSame('other', $map->sourceKey());
    }

    /** @return array<string,mixed> */
    private static function row(int $inter, string $text, float $amount, string $md, string $dal, int $centre = 0, string $date = '2025-01-31'): array
    {
        return ['INTER' => $inter, 'DATUM' => $date, 'DOKLAD' => 'MZ', 'CISLO' => '2501', 'POPIS' => $text, 'CASTKA' => $amount, 'MD' => $md, 'DAL' => $dal, 'STKOD' => $centre];
    }

    /** @return array<string,PayrollLegacyPostingRow> */
    private static function byReference(PremierPayrollPostingMap $map): array
    {
        $out = [];
        foreach ($map->postingRows() as $row) {
            $out[$row->sourceReference] = $row;
        }
        return $out;
    }

    /** @return array{0:?string,1:int,2:int,3:list<string>} */
    private static function shape(PayrollLegacyPostingRow $row): array
    {
        return [$row->concept, $row->lineCount, $row->amountMinor, $row->costCenters];
    }
}
