<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Migration\Premier;

use MyInvoice\Service\Migration\Premier\PremierDocuments;
use MyInvoice\Service\Migration\Premier\PremierJournal;
use MyInvoice\Service\Migration\Premier\PremierVat;
use PHPUnit\Framework\TestCase;

/**
 * Skladba faktury PREMIER z hlavičky, položek, deníku a vazeb úhrad - bez databáze.
 * Částky v Kč jsou vždy z deníku, položky dávají rozpad.
 */
final class PremierDocumentsTest extends TestCase
{
    private const SALE = '36';
    private const PURCHASE = '15';
    private const EU_SERVICE = '45';

    /** @var list<array<string,mixed>> */
    private array $journal = [];
    /** @var list<array<string,mixed>> */
    private array $issued = [];
    /** @var list<array<string,mixed>> */
    private array $purchases = [];
    /** @var list<array<string,mixed>> */
    private array $issuedItems = [];
    /** @var list<array<string,mixed>> */
    private array $purchaseItems = [];
    /** @var list<array<string,mixed>> */
    private array $links = [];

    public function testIssuedInvoiceAmountsComeFromJournalAndItemsFromDetail(): void
    {
        $this->issuedInvoice(1, '250001', '2025-02-10', 10000, 2100, [[8000, 1680, 'Vývoj', 8, 'hod'], [2000, 420, 'Konzultace', 2, 'hod']]);

        $doc = $this->documents()->forYear(PremierDocuments::ISSUED, 2025)[0];
        self::assertSame(['VF', '250001', 'invoice', true, '311000', 'detail'], [$doc['series'], $doc['number'], $doc['kind'], $doc['booked'], $doc['partner_account'], $doc['items_source']]);
        self::assertSame([10000.0, 2100.0, 12100.0, 0.0], [$doc['base'], $doc['vat'], $doc['total'], $doc['rounding']]);
        self::assertSame([
            ['description' => 'Vývoj', 'quantity' => 8.0, 'unit' => 'hod', 'unit_price' => 1000.0, 'base' => 8000.0, 'vat' => 1680.0, 'rate' => 21.0, 'code' => self::SALE, 'account' => ''],
            ['description' => 'Konzultace', 'quantity' => 2.0, 'unit' => 'hod', 'unit_price' => 1000.0, 'base' => 2000.0, 'vat' => 420.0, 'rate' => 21.0, 'code' => self::SALE, 'account' => ''],
        ], $doc['items']);
        self::assertSame([0.0, null, false], [$doc['paid'], $doc['paid_at'], $doc['settled']]);
        self::assertSame([], $doc['reasons']);
    }

    public function testPaymentViaLinksSettlesInvoice(): void
    {
        $this->issuedInvoice(1, '250001', '2025-02-10', 10000, 2100, [[10000, 2100, 'Služby', 1, 'ks']]);
        $this->journal[] = self::row(50, '2025-02-20', 'BV', '2', 10000, '221001', '311000');
        $this->journal[] = self::row(51, '2025-03-05', 'BV', '3', 2100, '221001', '311000');
        $this->journal[] = self::row(52, '2025-03-06', 'BV', '4', 999, '221001', '395000');
        $this->links[] = ['KOD_ZDR' => 'UD', 'INT_ZDR' => 50, 'KOD_TER' => 'VF', 'INT_TER' => 1];
        // Vazba opačným směrem (faktura → řádek deníku) a duplicitní vazba.
        $this->links[] = ['KOD_ZDR' => 'VF', 'INT_ZDR' => 1, 'KOD_TER' => 'UD', 'INT_TER' => 51];
        $this->links[] = ['KOD_ZDR' => 'UD', 'INT_ZDR' => 51, 'KOD_TER' => 'VF', 'INT_TER' => 1];
        // Řádek bez pohybu na účtu partnera se do úhrady nepočítá; vazba na neznámou řadu se ignoruje.
        $this->links[] = ['KOD_ZDR' => 'UD', 'INT_ZDR' => 52, 'KOD_TER' => 'VF', 'INT_TER' => 1];
        $this->links[] = ['KOD_ZDR' => 'UD', 'INT_ZDR' => 52, 'KOD_TER' => 'XX', 'INT_TER' => 1];

        $documents = $this->documents();
        $doc = $documents->forYear(PremierDocuments::ISSUED, 2025)[0];
        self::assertSame([12100.0, '2025-03-05', true], [$doc['paid'], $doc['paid_at'], $doc['settled']]);
        self::assertSame([['inter' => 50, 'date' => '2025-02-20', 'amount' => 10000.0], ['inter' => 51, 'date' => '2025-03-05', 'amount' => 2100.0]], $doc['payment_rows']);
        self::assertSame([
            50 => [['direction' => PremierDocuments::ISSUED, 'inter' => 1]],
            51 => [['direction' => PremierDocuments::ISSUED, 'inter' => 1]],
            52 => [['direction' => PremierDocuments::ISSUED, 'inter' => 1]],
        ], $documents->paymentLinks());
    }

    public function testInvoiceOpenAtYearEndIsCarriedIntoNextYear(): void
    {
        $this->purchaseInvoice(101, '250001', '2025-11-20', [[1000, 210, 'Papír', 1]], 1000, 210);
        $this->purchaseInvoice(102, '250002', '2025-12-01', [[500, 105, 'Toner', 1]], 500, 105);
        $this->journal[] = self::row(60, '2026-01-10', 'BV', '1', 1210, '321000', '221001');
        $this->journal[] = self::row(61, '2025-12-15', 'BV', '9', 605, '321000', '221001');
        $this->links[] = ['KOD_ZDR' => 'UD', 'INT_ZDR' => 60, 'KOD_TER' => 'PF', 'INT_TER' => 101];
        $this->links[] = ['KOD_ZDR' => 'UD', 'INT_ZDR' => 61, 'KOD_TER' => 'PF', 'INT_TER' => 102];
        $documents = $this->documents();

        self::assertSame([[101, false], [102, false]], array_map(static fn (array $d): array => [$d['inter'], $d['previous']], $documents->forYear(PremierDocuments::PURCHASE, 2025)));
        $next = $documents->forYear(PremierDocuments::PURCHASE, 2026);
        self::assertCount(1, $next, 'Doklad uhrazený v roce 2025 do salda roku 2026 nepatří.');
        self::assertSame([101, true, 2025, true, 1210.0, '2026-01-10'], [$next[0]['inter'], $next[0]['previous'], $next[0]['year'], $next[0]['settled'], $next[0]['paid'], $next[0]['paid_at']]);
        self::assertSame([], $documents->forYear(PremierDocuments::PURCHASE, 2027), 'K začátku roku 2027 je uhrazený.');
    }

    public function testForeignCurrencyItemsAreConvertedAndFittedToJournal(): void
    {
        // 100,00 + 300,10 EUR × 25,123 = 2 512,30 + 7 539,41 = 10 051,71; deník 10 051,72.
        $this->purchaseInvoice(104, '250004', '2025-07-01', [[100.00, 21.00, 'Příprava', 1], [300.10, 63.02, 'Konzultace', 2]], 10051.72, 2110.86,
            ['MENA' => 'EUR', 'KURS' => 25.123, 'M_KURS' => 1]);

        $doc = $this->documents()->forYear(PremierDocuments::PURCHASE, 2025)[0];
        self::assertSame(['EUR', 25.123, 'detail'], [$doc['currency'], $doc['factor'], $doc['items_source']]);
        self::assertSame([2512.30, 7539.42], array_column($doc['items'], 'base'), 'Haléřový rozdíl kurzu dorovná největší položka.');
        self::assertSame([527.58, 1583.28], array_column($doc['items'], 'vat'), 'Daň položek sedí do tolerance, rozdíl dorovná poslední.');
        self::assertSame([3769.71, 21.0], [$doc['items'][1]['unit_price'], $doc['items'][1]['rate']]);
        self::assertSame([10051.72, 2110.86, 12162.58], [$doc['base'], $doc['vat'], $doc['total']], 'Součty v Kč = deník.');
    }

    public function testForeignCurrencyWithHundredUnitsRate(): void
    {
        $this->purchaseInvoice(105, '250005', '2025-08-01', [[10000, 0, 'Služba', 1]], 1650, 0, ['MENA' => 'HUF', 'KURS' => 16.5, 'M_KURS' => 100], self::PURCHASE);
        $doc = $this->documents()->forYear(PremierDocuments::PURCHASE, 2025)[0];
        self::assertSame([0.165, [1650.0]], [$doc['factor'], array_column($doc['items'], 'base')]);
    }

    public function testItemsThatDoNotFitJournalAreRebuiltFromJournal(): void
    {
        $this->issuedInvoice(1, '250001', '2025-02-10', 6000, 1260, [[5000, 1050, 'Služby podle položek', 1, 'ks']]);

        $doc = $this->documents()->forYear(PremierDocuments::ISSUED, 2025)[0];
        self::assertSame('journal', $doc['items_source']);
        self::assertSame([['description' => 'Služby podle položek', 'quantity' => 1.0, 'unit' => null, 'unit_price' => 6000.0, 'base' => 6000.0, 'vat' => 1260.0, 'rate' => 21.0, 'code' => self::SALE, 'account' => '602100']], $doc['items']);
    }

    /**
     * Účet položky z rozpisu (`UC_S`+`UC_SA`, jinak `UC_D`+`UC_DA`), protiúčty třídy 3 se
     * přeskakují. Odpočet zálohy (314) proto zůstane bez účtu - nesmí zdědit nákladový účet
     * dokladu, jinak by ho převod označil jako drobný majetek.
     */
    public function testDetailItemsKeepTheirOwnAccountIncludingEmptyForAdvanceDeduction(): void
    {
        $this->purchases[] = self::header(301, 'PF', '250301', '2025-11-05', 'Monitor');
        $sb = ['SB_KOD' => 'PF', 'SBORNIK' => 301, 'KOD_DPH' => self::PURCHASE, 'SAZBA_DPH' => 21];
        $this->journal[] = self::row(100, '2025-11-05', 'PF', '250301', 20000, '501200', '321000', ['IKOD' => 'P'] + $sb);
        $this->journal[] = self::row(101, '2025-11-05', 'PF', '250301', -5000, '314000', '321000', ['IKOD' => 'P'] + $sb);
        $this->journal[] = self::row(102, '2025-11-05', 'PF', '250301', 3150, '343021', '321000', ['IKOD' => 'D'] + $sb);
        $this->purchaseItems[] = ['UC_S' => '501', 'UC_SA' => '200', 'UC_D' => '321', 'UC_DA' => '000'] + self::item(301, 1, 'Monitor', 19000, 3990, 21, self::PURCHASE);
        $this->purchaseItems[] = ['UC_S' => '314', 'UC_SA' => '000', 'UC_D' => '321', 'UC_DA' => '000'] + self::item(301, 2, 'Odpočet zálohy', -5000, -1050, 21, self::PURCHASE);
        // MD strana je protiúčet 321 - účet položky je pak Dal (`UC_D` + `UC_DA`).
        $this->purchaseItems[] = ['UC_S' => '321', 'UC_D' => '518', 'UC_DA' => '100'] + self::item(301, 3, 'Doprava', 1000, 210, 21, self::PURCHASE);

        $doc = $this->documents()->forYear(PremierDocuments::PURCHASE, 2025)[0];
        self::assertSame('detail', $doc['items_source']);
        self::assertSame([['Monitor', 19000.0, '501200'], ['Odpočet zálohy', -5000.0, ''], ['Doprava', 1000.0, '518100']],
            array_map(static fn (array $i): array => [$i['description'], $i['base'], $i['account']], $doc['items']));
    }

    public function testJournalBuiltItemGetsTheExpenseAccountOfTheBucket(): void
    {
        $this->purchaseInvoice(302, '250302', '2025-09-10', [[100, 21, 'Nesedí na deník', 1]], 15000, 3150);
        // Účet z rozpisu se u položky složené z deníku nepoužije - deník je zdroj pravdy.
        $this->purchaseItems[array_key_last($this->purchaseItems)] += ['UC_S' => '501', 'UC_SA' => '200'];

        $doc = $this->documents()->forYear(PremierDocuments::PURCHASE, 2025)[0];
        self::assertSame('journal', $doc['items_source']);
        self::assertSame([['Nesedí na deník', 15000.0, '518100']], array_map(static fn (array $i): array => [$i['description'], $i['base'], $i['account']], $doc['items']));
    }

    public function testUnbookedAdvanceListItemKeepsItsAccount(): void
    {
        $this->issued[] = self::header(9, 'ZVF', '259001', '2025-08-15', 'Zálohový list');
        $this->issuedItems[] = ['UC_D' => '602', 'UC_DA' => '100'] + self::item(9, 1, 'Záloha', 5000, 1050, 21, self::SALE);

        $doc = $this->documents()->forYear(PremierDocuments::ISSUED, 2025)[0];
        self::assertSame('602100', $doc['items'][0]['account']);
    }

    public function testCreditNotePostedWithNegativeAmountsOnSameSides(): void
    {
        $this->issuedInvoice(2, '250002', '2025-06-01', -1000, -210, [[-1000, -210, 'Sleva', 1, 'ks']]);
        $this->journal[] = self::row(70, '2025-06-10', 'BV', '5', 1210, '311000', '221001');
        $this->links[] = ['KOD_ZDR' => 'UD', 'INT_ZDR' => 70, 'KOD_TER' => 'VF', 'INT_TER' => 2];

        $doc = $this->documents()->forYear(PremierDocuments::ISSUED, 2025)[0];
        self::assertSame('311000', $doc['partner_account'], 'Účet partnera podle sloupce, ne znaménka.');
        self::assertSame([-1000.0, -210.0, -1210.0], [$doc['base'], $doc['vat'], $doc['total']]);
        self::assertSame([-1000.0, -210.0], [$doc['items'][0]['base'], $doc['items'][0]['vat']]);
        self::assertSame([-1210.0, true], [$doc['paid'], $doc['settled']], 'Vrácení peněz uhradí dobropis.');
    }

    /**
     * Konečná faktura plně krytá zálohou: položka plnění +10 000 / +2 100 a odpočet zálohy
     * -10 000 / -2 100 s vlastní daní. Celkem 0 = vyřízeno, navázaný řádek není úhrada.
     */
    public function testAdvanceDeductionItemKeepsOwnVatAndSettlesAtZero(): void
    {
        $this->issued[] = self::header(3, 'VF', '250003', '2025-09-30', 'Konečná faktura');
        $sb = ['SB_KOD' => 'VF', 'SBORNIK' => 3, 'KOD_DPH' => self::SALE, 'SAZBA_DPH' => 21];
        $this->journal[] = self::row(80, '2025-09-30', 'VF', '250003', 10000, '311000', '602100', ['IKOD' => 'P'] + $sb);
        $this->journal[] = self::row(81, '2025-09-30', 'VF', '250003', 2100, '311000', '343021', ['IKOD' => 'D'] + $sb);
        $this->journal[] = self::row(82, '2025-09-30', 'VF', '250003', -10000, '311000', '324000', ['IKOD' => 'P'] + $sb);
        $this->journal[] = self::row(83, '2025-09-30', 'VF', '250003', -2100, '311000', '343021', ['IKOD' => 'D'] + $sb);
        $this->issuedItems[] = self::item(3, 1, 'Dodávka', 10000, 2100, 21, self::SALE);
        $this->issuedItems[] = self::item(3, 2, 'Odpočet zálohy', -10000, -2100, 21, self::SALE);
        $this->journal[] = self::row(84, '2025-09-01', 'BV', '8', 12100, '221001', '324000');
        $this->links[] = ['KOD_ZDR' => 'UD', 'INT_ZDR' => 84, 'KOD_TER' => 'VF', 'INT_TER' => 3];

        $doc = $this->documents()->forYear(PremierDocuments::ISSUED, 2025)[0];
        self::assertSame([[10000.0, 2100.0], [-10000.0, -2100.0]], array_map(static fn (array $i): array => [$i['base'], $i['vat']], $doc['items']));
        self::assertSame([0.0, 0.0, 0.0], [$doc['base'], $doc['vat'], $doc['total']]);
        self::assertSame([0.0, [], true], [$doc['paid'], $doc['payment_rows'], $doc['settled']]);
    }

    public function testUnbookedAdvanceListTakesAmountsFromItems(): void
    {
        $this->issued[] = self::header(9, 'ZVF', '259001', '2025-08-15', 'Zálohový list');
        $this->issuedItems[] = self::item(9, 1, 'Záloha', 5000, 1050, 21, self::SALE);
        // Rozpracovaný doklad bez položek i zápisu se vynechá, stejně jako hlavička bez čísla.
        $this->issued[] = self::header(10, 'VF', '250010', '2025-08-20', 'Rozpracováno');
        $this->issued[] = self::header(11, 'VF', '', '2025-08-20', 'Bez čísla');

        $docs = $this->documents()->forYear(PremierDocuments::ISSUED, 2025);
        self::assertCount(1, $docs);
        self::assertSame(['advance', false, 5000.0, 1050.0, 6050.0, '2025-08-15'], [$docs[0]['kind'], $docs[0]['booked'], $docs[0]['base'], $docs[0]['vat'], $docs[0]['total'], $docs[0]['accounting']]);
    }

    /**
     * Služba z EU: v deníku jen základ (bez samovyměření na 343) i se samovyměřením
     * MD 343 / D 343 zapsaným PŘED řádkem základu. Obojí musí dát týž doklad - daň 0,
     * sazba z třídy kódu, částka jen základ.
     */
    public function testReverseChargeWithOrWithoutSelfAssessmentRowsGivesSameDocument(): void
    {
        $euVendor = ['NAZEV_ODB' => 'Fiktiv Software GmbH', 'DIC_ODB' => 'DE123456789'];
        $this->purchases[] = self::header(201, 'PF', '250201', '2025-04-10', 'Licence', $euVendor);
        $this->purchaseItems[] = self::item(201, 1, 'Licence', 5000, 0, 0, self::EU_SERVICE);
        $this->journal[] = self::row(90, '2025-04-10', 'PF', '250201', 5000, '518100', '321000', ['IKOD' => 'P', 'SB_KOD' => 'PF', 'SBORNIK' => 201, 'KOD_DPH' => self::EU_SERVICE]);

        $this->purchases[] = self::header(202, 'PF', '250202', '2025-04-10', 'Licence', $euVendor);
        $this->purchaseItems[] = self::item(202, 1, 'Licence', 5000, 0, 0, self::EU_SERVICE);
        $this->journal[] = self::row(91, '2025-04-10', 'PF', '250202', 1050, '343100', '343200', ['IKOD' => 'D', 'SB_KOD' => 'PF', 'SBORNIK' => 202, 'KOD_DPH' => self::EU_SERVICE]);
        $this->journal[] = self::row(92, '2025-04-10', 'PF', '250202', 5000, '518100', '321000', ['IKOD' => 'P', 'SB_KOD' => 'PF', 'SBORNIK' => 202, 'KOD_DPH' => self::EU_SERVICE]);

        [$plain, $selfAssessed] = $this->documents()->forYear(PremierDocuments::PURCHASE, 2025);
        foreach ([$plain, $selfAssessed] as $doc) {
            self::assertSame('321000', $doc['partner_account'], $doc['number']);
            self::assertSame([5000.0, 0.0, 5000.0], [$doc['base'], $doc['vat'], $doc['total']], $doc['number']);
            self::assertSame([[5000.0, 0.0, 21.0, self::EU_SERVICE]], array_map(static fn (array $i): array => [$i['base'], $i['vat'], $i['rate'], $i['code']], $doc['items']), $doc['number']);
            self::assertSame('detail', $doc['items_source'], $doc['number']);
        }
    }

    private function documents(): PremierDocuments
    {
        $vat = PremierVat::fromRows([
            ['KOD_DPH' => self::SALE, 'SAZBA' => 2, 'FA_OUT' => true, 'R17' => 1],
            ['KOD_DPH' => self::PURCHASE, 'SAZBA' => 2, 'FA_IN' => true, 'R17' => 40],
            ['KOD_DPH' => self::EU_SERVICE, 'SAZBA' => 2, 'FA_IN' => true, 'IS_REVERS' => true, 'R17' => 5, 'R17B' => 43],
        ]);
        return new PremierDocuments(
            $this->issued,
            $this->purchases,
            $this->issuedItems,
            $this->purchaseItems,
            $this->links,
            [['DOKLAD' => 'VF', 'TOK' => 3], ['DOKLAD' => 'ZVF', 'TOK' => 11], ['DOKLAD' => 'PF', 'TOK' => 4]],
            PremierJournal::fromRows($this->journal),
            $vat,
        );
    }

    /** @param list<array{0:float,1:float,2:string,3:float,4:string}> $items */
    private function issuedInvoice(int $inter, string $number, string $date, float $base, float $vat, array $items): void
    {
        $this->issued[] = self::header($inter, 'VF', $number, $date, 'Faktura ' . $number);
        $sb = ['SB_KOD' => 'VF', 'SBORNIK' => $inter, 'KOD_DPH' => self::SALE, 'SAZBA_DPH' => 21];
        $this->journal[] = self::row($inter * 10, $date, 'VF', $number, $base, '311000', '602100', ['IKOD' => 'P'] + $sb);
        $this->journal[] = self::row($inter * 10 + 1, $date, 'VF', $number, $vat, '311000', '343021', ['IKOD' => 'D'] + $sb);
        foreach ($items as $i => [$price, $itemVat, $text, $qty, $unit]) {
            $this->issuedItems[] = self::item($inter, $i + 1, $text, $price, $itemVat, 21, self::SALE, $qty, $unit);
        }
    }

    /**
     * @param list<array{0:float,1:float,2:string,3:float}> $items
     * @param array<string,mixed> $header
     */
    private function purchaseInvoice(int $inter, string $number, string $date, array $items, float $base, float $vat, array $header = [], string $code = self::PURCHASE): void
    {
        $this->purchases[] = self::header($inter, 'PF', $number, $date, 'Přijatá ' . $number, $header);
        $sb = ['SB_KOD' => 'PF', 'SBORNIK' => $inter, 'KOD_DPH' => $code, 'SAZBA_DPH' => 21];
        $this->journal[] = self::row($inter * 10, $date, 'PF', $number, $base, '518100', '321000', ['IKOD' => 'P'] + $sb);
        if (abs($vat) >= 0.005) {
            $this->journal[] = self::row($inter * 10 + 1, $date, 'PF', $number, $vat, '343021', '321000', ['IKOD' => 'D'] + $sb);
        }
        foreach ($items as $i => [$price, $itemVat, $text, $qty]) {
            $this->purchaseItems[] = self::item($inter, $i + 1, $text, $price, $itemVat, 21, $code, $qty);
        }
    }

    /**
     * @param array<string,mixed> $extra
     * @return array<string,mixed>
     */
    private static function header(int $inter, string $series, string $number, string $date, string $text, array $extra = []): array
    {
        return $extra + ['INTER' => $inter, 'DOKLAD' => $series, 'CISLO' => $number, 'DATUM_VYS' => $date, 'DATUM_USK' => $date, 'DATUM_DPH' => $date,
            'DATUM_SPL' => $date, 'POPIS' => $text, 'MENA' => 'CZK', 'KURS' => 1, 'M_KURS' => 1];
    }

    /** @return array<string,mixed> */
    private static function item(int $invoice, int $order, string $text, float $price, float $vat, float $rate, string $code, float $qty = 1, string $unit = 'ks'): array
    {
        return ['FAKTURA' => $invoice, 'POL_SORT' => $order, 'TEXT' => $text, 'MNOZSTVI' => $qty, 'MJ' => $unit, 'CENA' => $price, 'CENA_DPH' => $vat, 'SAZBA_DPH' => $rate, 'KOD_DPH' => $code];
    }

    /**
     * @param array<string,mixed> $extra
     * @return array<string,mixed>
     */
    private static function row(int $inter, string $date, string $series, string $number, float $amount, string $md, string $dal, array $extra = []): array
    {
        return $extra + ['INTER' => $inter, 'DATUM' => $date, 'DATUM_DPH' => $date, 'DOKLAD' => $series, 'CISLO' => $number, 'CASTKA' => $amount, 'MD' => $md, 'DAL' => $dal];
    }
}
