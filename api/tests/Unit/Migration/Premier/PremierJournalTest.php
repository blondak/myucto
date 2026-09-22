<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Migration\Premier;

use MyInvoice\Service\Migration\Premier\PremierJournal;
use PHPUnit\Framework\TestCase;

/**
 * Deník PREMIER (`PUB_UCTO`): počáteční stavy dopočtené z předchozích let (PREMIER
 * počáteční ani uzávěrkové zápisy v deníku nevede), předvaha, účinek řádku a doklady.
 */
final class PremierJournalTest extends TestCase
{
    /**
     * Firma převedená do PREMIER v roce 2024 (počáteční stavy zápisy na 701), rok 2024
     * uzavřený v PREMIER (702/710 - převod je ignoruje), 2025 s rozdělením zisku.
     */
    private static function journal(): PremierJournal
    {
        return PremierJournal::fromRows([
            self::row(1, '2024-01-01', 'PS', '1', 100000, '221001', '701000'),
            self::row(2, '2024-01-01', 'PS', '1', 100000, '701000', '411000'),
            self::row(3, '2024-03-01', 'BV', '1', 1000, '518100', '221001'),
            self::row(4, '2024-04-01', 'VF', '240001', 5000, '311000', '602100', ['SB_KOD' => 'VF', 'SBORNIK' => 7, 'IKOD' => 'P', 'KOD_DPH' => '36']),
            self::row(5, '2024-05-01', 'ID', '1', 500, '261000', '221001'),
            self::row(6, '2024-05-02', 'ID', '2', 500, '221001', '261000'),
            // Uzávěrka roku 2024 v PREMIER.
            self::row(7, '2024-12-31', 'UZ', '1', 1000, '710000', '518100'),
            self::row(8, '2024-12-31', 'UZ', '1', 5000, '602100', '710000'),
            self::row(9, '2024-12-31', 'UZ', '2', 99000, '702000', '221001', ['SB_KOD' => 'VF', 'SBORNIK' => 7]),
            // 2025: rozdělení výsledku, náklad a dobropis zápornou částkou.
            self::row(10, '2025-02-01', 'ID', '3', 4000, '431000', '428000'),
            self::row(11, '2025-02-10', 'PF', '250001', 300, '518100', '321000'),
            self::row(12, '2025-03-01', 'VF', '250002', -200, '311000', '602100'),
            // Výpis za dva dny: dva zápisy.
            self::row(13, '2025-03-10', 'BV', '5', 100, '221001', '311000'),
            self::row(14, '2025-03-11', 'BV', '5', 50, '221001', '311000'),
            self::row(15, '2025-03-11', 'BV', '5', 25, '568000', '221001'),
            // Neplatné řádky: bez data, účet s písmeny.
            self::row(16, '', 'ID', '9', 1, '518100', '221001'),
            self::row(17, '2027-04-01', 'ID', '10', 7, '518ABC', '221001', ['MENA' => 'eur1']),
        ]);
    }

    /**
     * @param array<string,mixed> $extra
     * @return array<string,mixed>
     */
    private static function row(int $inter, string $date, string $series, string $number, float $amount, string $md, string $dal, array $extra = []): array
    {
        return $extra + ['INTER' => $inter, 'DATUM' => $date, 'DOKLAD' => $series, 'CISLO' => $number, 'CASTKA' => $amount, 'MD' => $md, 'DAL' => $dal, 'POPIS' => 'Zápis ' . $inter];
    }

    public function testOpeningBalancesCarryBalanceAccountsAndProfitOfAllPriorYears(): void
    {
        $journal = self::journal();

        // 2025: zůstatky 2024 včetně stavů zapsaných na 701; výsledek 2024 (5 000 - 1 000) na 431.
        // Účet 261 vyšel nula a vypadne; uzávěrkové zápisy 702/710 se nepočítají.
        self::assertSame([
            '221001' => 99000.0,
            '311000' => 5000.0,
            '411000' => -100000.0,
            '431000' => -4000.0,
        ], $journal->openingBalances(2025, '431000'));

        // 2026: výsledek všech předchozích let (-4 000 + 300 + 200) + rozdělení 431 → 428.
        self::assertSame([
            '221001' => 99125.0,
            '311000' => 4650.0,
            '321000' => -300.0,
            '411000' => -100000.0,
            '428000' => -4000.0,
            '431000' => 525.0,
        ], $journal->openingBalances(2026, '431000'));
        self::assertEqualsWithDelta(0.0, array_sum($journal->openingBalances(2026, '431000')), 0.001, 'Počáteční stavy jsou vyrovnané.');

        self::assertSame([], $journal->openingBalances(2024, '431000'), 'Nejstarší rok nemá z čeho počítat.');
    }

    public function testOpeningAndClosingRowsAreSeparatedFromTheYear(): void
    {
        $journal = self::journal();
        self::assertSame([1, 2], array_column($journal->openingRows(2024), 'inter'), 'Zápisy na 701 = počáteční stavy.');
        self::assertSame([], $journal->openingRows(2025));
        self::assertSame(3, $journal->closingRowCount(2024));
        self::assertSame([3, 4, 5, 6], array_column($journal->year(2024), 'inter'));
        self::assertSame(['221001', '261000', '311000', '411000', '518100', '602100', '701000'], $journal->accountsUsed(2024));
        self::assertSame(2024, $journal->firstYear());
        self::assertTrue($journal->hasRowsBefore(2025));
        self::assertFalse($journal->hasRowsBefore(2024));
        self::assertSame(['closing'], array_values(array_unique(array_column(array_filter([$journal->row(7), $journal->row(9)]), 'kind'))));

        // Vazba do sborníku vynechá uzávěrkový řádek, i když nese SB_KOD.
        self::assertSame([4], array_column($journal->linkedRows('vf', 7), 'inter'));
        self::assertSame([], $journal->linkedRows('VF', 999));
    }

    public function testInvalidRowsAreDropped(): void
    {
        $journal = self::journal();
        self::assertNull($journal->row(16), 'Řádek bez data převod nevidí.');
        $row = $journal->row(17);
        self::assertNotNull($row);
        self::assertSame('', $row['md'], 'Účet s písmeny není kód účtu.');
        self::assertSame('', $row['currency']);
        self::assertNull(PremierJournal::effect($row));
    }

    public function testTrialBalanceBySyntheticAccounts(): void
    {
        $journal = self::journal();
        $tb = $journal->trialBalance(2025, $journal->openingBalances(2025, '431000'));

        self::assertSame([99000.0, 125.0, 99125.0], $tb['221']);
        self::assertSame([5000.0, -350.0, 4650.0], $tb['311'], 'Dobropis zápornou částkou snižuje MD.');
        self::assertSame([-4000.0, 4000.0, 0.0], $tb['431']);
        self::assertSame([0.0, -4000.0, -4000.0], $tb['428']);
        self::assertSame([0.0, 300.0, 300.0], $tb['518']);
        self::assertSame([0.0, 200.0, 200.0], $tb['602']);
        self::assertSame([0.0, 25.0, 25.0], $tb['568']);
        self::assertArrayNotHasKey('701', $tb);
        self::assertArrayNotHasKey('518ABC', $tb);
        self::assertEqualsWithDelta(0.0, array_sum(array_column($tb, 1)), 0.001, 'Obraty MD = D.');
    }

    public function testEffectSwapsSidesOfNegativeAmount(): void
    {
        $journal = self::journal();
        self::assertSame(['debit' => '602100', 'credit' => '311000', 'amount' => 200.0], PremierJournal::effect($journal->row(12)));
        self::assertSame(['debit' => '518100', 'credit' => '321000', 'amount' => 300.0], PremierJournal::effect($journal->row(11)));

        $row = $journal->row(11);
        self::assertNull(PremierJournal::effect(['amount' => 0.004] + $row), 'Nulová částka nemá účinek.');
        self::assertNull(PremierJournal::effect(['dal' => '518100'] + $row), 'MD = D nemá účinek.');
        self::assertNull(PremierJournal::effect(['dal' => ''] + $row));

        self::assertSame(-300.0, PremierJournal::movement($row, '321'));
        self::assertSame(300.0, PremierJournal::movement($row, '518100'));
        self::assertSame(0.0, PremierJournal::movement($row, '311'));
    }

    public function testDocumentsGroupBySeriesNumberDateAndLink(): void
    {
        $docs = self::journal()->documents(2025);
        self::assertSame([
            'ID|3|2025-02-01||0',
            'PF|250001|2025-02-10||0',
            'VF|250002|2025-03-01||0',
            'BV|5|2025-03-10||0',
            'BV|5|2025-03-11||0',
        ], array_keys($docs));
        self::assertSame([14, 15], array_column($docs['BV|5|2025-03-11||0'], 'inter'));

        $doc = self::journal()->documents(2024);
        self::assertArrayHasKey('VF|240001|2024-04-01|VF|7', $doc);
        self::assertArrayNotHasKey('UZ|1|2024-12-31||0', $doc, 'Uzávěrka do dokladů roku nepatří.');
        self::assertArrayNotHasKey('PS|1|2024-01-01||0', $doc, 'Počáteční stavy taky ne.');
    }

    /**
     * Doklad bankovní řady se dělí po pohybech: řádek bez pohybu jde k pohybu, který hradí
     * stejnou fakturu, zbytek do zápisu dokladu. Kurzové přecenění EUR účtu pohyb není.
     */
    public function testBankDocumentSplitsIntoOneEntryPerMovement(): void
    {
        $journal = PremierJournal::fromRows([
            self::row(1, '2025-10-15', 'BV', '8', 0.40, '548000', '311000'),
            self::row(2, '2025-10-15', 'BV', '8', 1209.60, '221001', '311000'),
            self::row(3, '2025-10-15', 'BV', '8', 2420, '221001', '311000'),
            self::row(4, '2025-10-15', 'BV', '8', 25, '568000', '221001'),
            self::row(5, '2025-10-15', 'BV', '8', 5, '311000', '663000'),
            self::row(6, '2025-10-15', 'ID', '1', 10, '568000', '221001'),
            self::row(7, '2025-12-31', 'BE', '2', 500, '221002', '663000', ['MENA' => 'EUR', 'ZCASTKA' => 0]),
            self::row(8, '2025-12-31', 'BE', '2', 2500, '221002', '411000', ['MENA' => 'EUR', 'ZCASTKA' => 100]),
            self::row(9, '2025-12-31', 'BE', '3', 300, '221002', '663000', ['MENA' => 'EUR', 'ZCASTKA' => 0]),
        ], [
            ['DOKLAD' => 'BV', 'TOK' => 2, 'MD' => '221', 'MDA' => '001', 'MENA' => 'CZK'],
            ['DOKLAD' => 'BE', 'TOK' => 2, 'MD' => '221', 'MDA' => '002', 'MENA' => 'EUR'],
        ]);
        $journal->usePaymentLinks([
            1 => [['direction' => 'issued', 'inter' => 6]],
            2 => [['direction' => 'issued', 'inter' => 6]],
            3 => [['direction' => 'issued', 'inter' => 7]],
        ]);

        $docs = $journal->documents(2025);
        self::assertSame([
            'BV|8|2025-10-15||0|#2' => [2, 1],
            'BV|8|2025-10-15||0|#3' => [3],
            'BV|8|2025-10-15||0|#4' => [4],
            'BV|8|2025-10-15||0|#' => [5],
            'ID|1|2025-10-15||0' => [6],
            'BE|2|2025-12-31||0|#8' => [8],
            'BE|2|2025-12-31||0|#' => [7],
            'BE|3|2025-12-31||0' => [9],
        ], array_map(static fn (array $rows): array => array_column($rows, 'inter'), $docs));
        self::assertSame('BV|8|2025-10-15||0', PremierJournal::groupKey('BV|8|2025-10-15||0|#2'));
        self::assertSame('BV|8|2025-10-15||0', PremierJournal::groupKey('BV|8|2025-10-15||0|#'));
        self::assertCount(5, $journal->groups(2025)['BV|8|2025-10-15||0']);

        self::assertSame(1209.60, $journal->bankAmount($journal->row(2)));
        self::assertSame(-25.0, $journal->bankAmount($journal->row(4)));
        self::assertSame(100.0, $journal->bankAmount($journal->row(8)), 'Pohyb EUR účtu je v měně účtu.');
        self::assertNull($journal->bankAmount($journal->row(7)), 'Kurzové přecenění (částka v měně 0) pohyb není.');
        self::assertNull($journal->bankAmount($journal->row(1)), 'Řádek výpisu mimo účet banky pohyb není.');
        self::assertNull($journal->bankAmount($journal->row(6)), 'Řádek na 221 mimo bankovní řadu pohyb není.');
    }

    public function testRowIsNormalized(): void
    {
        $row = PremierJournal::fromRows([self::row(1, '2025-07-01', ' pf ', '250004', 10051.724, '518100', '321000', [
            'DATUM_DPH' => '2025-07-02', 'MENA' => 'eur', 'ZCASTKA' => 400.1, 'KURS' => 25.123, 'M_KURS' => 0, 'IKOD' => 'p', 'SB_KOD' => 'pf', 'SBORNIK' => 104,
            'DIC_ODB' => 'cz 112 233 41', 'KOD_DPH' => ' 15 ', 'CASTKA_DPH' => 2110.861, 'STKOD' => 3,
        ])])->row(1);
        self::assertNotNull($row);
        self::assertSame(
            ['PF', '2025-07-02', 10051.72, 'EUR', 400.1, 25.123, 1, 'P', 'PF', 104, 'CZ11223341', '15', 2110.86, 3, 'regular', 2025],
            [$row['series'], $row['tax_date'], $row['amount'], $row['currency'], $row['amount_foreign'], $row['rate'], $row['rate_units'], $row['line_kind'],
                $row['sb_kod'], $row['sbornik'], $row['partner_dic'], $row['vat_code'], $row['vat_amount'], $row['cost_center'], $row['kind'], $row['year']]
        );
    }
}
