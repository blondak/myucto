<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Migration\Pohoda;

use MyInvoice\Service\Migration\Pohoda\PohodaException;
use MyInvoice\Service\Migration\Pohoda\PohodaExport;
use MyInvoice\Service\Migration\Pohoda\PohodaJournal;
use MyInvoice\Service\Migration\Pohoda\PohodaMdbAccounting;
use MyInvoice\Service\Migration\Pohoda\PohodaMdbTables;
use MyInvoice\Service\Migration\Pohoda\PohodaXml;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PohodaMdbAccountingTest extends TestCase
{
    private string $path;
    private string $dir;

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'pohoda_accounting_unit_');
        $this->dir = $this->path . '_files';
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        unlink($this->path);
        $entries = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($entries as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($this->dir);
    }

    public function testJournalHasSameDebitCreditAndAmountsAsOfficialXml(): void
    {
        file_put_contents($this->path, '<root><accountingItem><homeCurrency><priceSum>1250.50</priceSum></homeCurrency><accounting><credit>518000</credit><debit>321000</debit></accounting></accountingItem></root>');
        $official = iterator_to_array(PohodaXml::records($this->path, 'accountingItem'))[0];
        $mapper = $this->mapper(['pUD' => [
            ['ID' => '1', 'RelUdAg' => '3', 'UMD' => '518000', 'UD' => '321000', 'Kc' => '1250.50', 'Datum' => '2026-02-03T00:00:00'],
            ['ID' => '2', 'RelUdAg' => '3', 'UMD' => '518000', 'UD' => '321000', 'Kc' => '-80.25'],
        ]]);
        $records = iterator_to_array($mapper->records('journal'));
        self::assertSame(PohodaJournal::effect($official), PohodaJournal::effect($records[0]));
        self::assertSame(['debit' => '518000', 'credit' => '321000', 'amount' => 1250.5], PohodaJournal::effect($records[0]));
        self::assertSame(['debit' => '321000', 'credit' => '518000', 'amount' => 80.25], PohodaJournal::effect($records[1]));
        self::assertSame('2026-02-03', $records[0]['date']);
    }

    public function testInvoicePreservesVatBucketsAndSeparatesAdvanceDeduction(): void
    {
        $mapper = $this->mapper([
            'FA' => [['ID' => '1', 'Cislo' => 'TEST-F1', 'RelTpFak' => '1', 'Kc0' => '20', 'Kc1' => '100', 'KcDPH1' => '12', 'Kc2' => '200', 'KcDPH2' => '42', 'KcZaokr' => '0.25']],
            'FApol' => [
                ['ID' => '1', 'RefAg' => '1', 'RelSzDPH' => '2', 'Kc' => '200', 'KcDPH' => '42', 'KcJedn' => '100', 'Mnozstvi' => '2'],
                ['ID' => '2', 'RefAg' => '1', 'RelAgID' => '43', 'Kc' => '-100', 'KcDPH' => '-21', 'RelSzDPH' => '2'],
            ],
        ]);
        $invoice = iterator_to_array($mapper->records('issued'))[0];
        $summary = $invoice['invoiceSummary']['homeCurrency'];
        self::assertSame('20', $summary['priceNone']);
        self::assertSame('100', $summary['priceLow']);
        self::assertSame('12', $summary['priceLowVAT']['#']);
        self::assertSame('200', $summary['priceHigh']);
        self::assertSame('42', $summary['priceHighVAT']['#']);
        self::assertSame('112', $summary['priceLowSum']);
        self::assertSame('242', $summary['priceHighSum']);
        self::assertSame('0.25', $summary['round']['priceRound']);
        self::assertCount(1, $invoice['invoiceDetail']['invoiceItem']);
        self::assertCount(1, $invoice['invoiceDetail']['invoiceAdvancePaymentItem']);
        self::assertSame('242', $invoice['invoiceDetail']['invoiceItem'][0]['homeCurrency']['priceSum']);
        self::assertSame('-121', $invoice['invoiceDetail']['invoiceAdvancePaymentItem'][0]['homeCurrency']['priceSum']);
        self::assertSame('21', $invoice['invoiceDetail']['invoiceItem'][0]['rateVAT']['@value']);
    }

    /**
     * Doklad v režimu OSS z MDB nese stát spotřeby, typ plnění, skutečné procento sazby
     * a částky položky v cizí měně na týchž místech jako XML POHODY - převod pak z obou
     * zdrojů rozhoduje stejně (issue #75). Bez MOSS se elementy vynechávají jako v XML.
     */
    public function testOssFieldsMapToSameElementsAsOfficialXml(): void
    {
        $mapper = $this->mapper([
            'sCMeny' => [['ID' => '2', 'Kod' => 'EUR']],
            'FA' => [
                ['ID' => '1', 'Cislo' => 'TEST-OSS', 'RelTpFak' => '1', 'Datum' => '2026-02-10', 'Kc1' => '1000', 'KcDPH1' => '230',
                    'RefCM' => '2', 'CmKurs' => '25', 'CmMnoz' => '1', 'CmCelkem' => '49.2', 'MOSS' => 'SK', 'MOSSDukaz' => 'G'],
                ['ID' => '2', 'Cislo' => 'TEST-CZ', 'RelTpFak' => '1', 'Datum' => '2026-02-11', 'Kc2' => '100', 'KcDPH2' => '21'],
            ],
            'FApol' => [
                ['ID' => '1', 'RefAg' => '1', 'RelSzDPH' => '1', 'ProcentoDPH' => '23', 'Kc' => '1000', 'KcDPH' => '230', 'MJ' => 'ks',
                    'MOSSDruh' => 'GD', 'CmJedn' => '40', 'Cm' => '40', 'CmDPH' => '9.2'],
                ['ID' => '2', 'RefAg' => '2', 'RelSzDPH' => '2', 'Kc' => '100', 'KcDPH' => '21'],
            ],
        ]);
        [$oss, $domestic] = iterator_to_array($mapper->records('issued'), false);

        self::assertSame('SK', PohodaXml::text($oss['invoiceHeader'], 'MOSS/ids'));
        self::assertSame('G', PohodaXml::text($oss['invoiceHeader'], 'evidentiaryResourcesMOSS/ids'));
        $item = $oss['invoiceDetail']['invoiceItem'][0];
        self::assertSame('GD', PohodaXml::text($item, 'typeServiceMOSS/ids'));
        self::assertSame('23', PohodaXml::text($item, 'percentVAT'));
        self::assertSame('40', PohodaXml::text($item, 'foreignCurrency/price'));
        self::assertSame('9.2', PohodaXml::text($item, 'foreignCurrency/priceVAT'));
        self::assertSame('EUR', PohodaXml::text($oss, 'invoiceSummary/foreignCurrency/currency/ids'));

        // Doklad mimo OSS a v Kč: nic z toho nenese, stejně jako XML POHODY.
        self::assertSame('', PohodaXml::text($domestic['invoiceHeader'], 'MOSS/ids'));
        self::assertArrayNotHasKey('evidentiaryResourcesMOSS', $domestic['invoiceHeader']);
        self::assertArrayNotHasKey('typeServiceMOSS', $domestic['invoiceDetail']['invoiceItem'][0]);
        self::assertArrayNotHasKey('foreignCurrency', $domestic['invoiceDetail']['invoiceItem'][0]);
    }

    public function testBankDirectionAndMovementNumberArePreserved(): void
    {
        $mapper = $this->mapper(['BV' => [
            ['ID' => '1', 'RelTpBV' => '1', 'Cislo' => 'TEST-B1', 'Vypis' => '4/7', 'Kc0' => '125.50'],
            ['ID' => '2', 'RelTpBV' => '2', 'Cislo' => 'TEST-B2', 'Vypis' => '4/8', 'Kc0' => '33.25'],
        ]]);
        $records = iterator_to_array($mapper->records('bank'));
        self::assertSame('receipt', $records[0]['bankHeader']['bankType']);
        self::assertSame('expense', $records[1]['bankHeader']['bankType']);
        self::assertSame(['statementNumber' => '4', 'numberMovement' => '7'], $records[0]['bankHeader']['statementNumber']);
        self::assertSame('33.25', $records[1]['bankSummary']['homeCurrency']['priceNone']);
    }

    /**
     * Úhradu bankou váže POHODA na bankovní doklad id (`Uhrady.RelIDU`) i z položky pohybu
     * (`BVpol.RelIDUhrady`); opis čísla v úhradě (`CisloU`) může mířit na doklad jiného roku
     * se stejným číslem. Párovací symboly pohybu a položek jdou do exportu pro párování.
     */
    public function testPaymentLinksAndPairingSymbolsComeFromIdsNotFromCopiedNumbers(): void
    {
        $mapper = $this->mapper([
            'FA' => [['ID' => '10', 'RelTpFak' => '11', 'Cislo' => 'TEST-P1', 'Datum' => '2026-02-03', 'Kc2' => '100', 'KcDPH2' => '21']],
            'BV' => [
                ['ID' => '1', 'RelTpBV' => '2', 'Cislo' => 'TEST-B1', 'Kc0' => '121', 'ParSym' => 'TEST-P1'],
                ['ID' => '2', 'RelTpBV' => '2', 'Cislo' => 'TEST-B2', 'Kc0' => '50'],
            ],
            'BVpol' => [['ID' => '5', 'RefAg' => '1', 'RelIDUhrady' => '7', 'ParSym' => '2026007', 'Kc' => '121']],
            'Uhrady' => [
                // RelIDU chybí, CisloU je opis čísla dokladu jiného roku - platí vazba z položky pohybu.
                ['ID' => '7', 'RelIDH' => '10', 'RelAgH' => '3', 'RelAgU' => '28', 'DatumU' => '2026-02-10', 'CisloU' => 'TEST-B2', 'KcU' => '121'],
                ['ID' => '8', 'RelIDH' => '10', 'RelAgH' => '3', 'RelAgU' => '28', 'DatumU' => '2026-02-11', 'RelIDU' => '2', 'CisloU' => 'TEST-OLD', 'KcU' => '1'],
            ],
        ]);
        $invoice = iterator_to_array($mapper->records('received'))[0];
        $liquidations = $invoice['liquidations']['liquidation'];
        self::assertSame(['id' => '1', 'number' => 'TEST-B1'], $liquidations[0]['sourceDocument']);
        self::assertSame(['id' => '2', 'number' => 'TEST-B2'], $liquidations[1]['sourceDocument']);

        $bank = iterator_to_array($mapper->records('bank'));
        self::assertSame('TEST-P1', $bank[0]['bankHeader']['symPar']);
        self::assertSame('2026007', $bank[0]['bankDetail']['bankItem'][0]['symPar']);
        self::assertSame('', $bank[1]['bankHeader']['symPar']);
    }

    /** Export staršího převodníku (bez sloupců pro párování) se čte beze změny. */
    public function testExportWithoutPairingColumnsKeepsCopiedPaymentNumber(): void
    {
        $mapper = $this->mapper([
            'FA' => [['ID' => '10', 'RelTpFak' => '11', 'Cislo' => 'TEST-P1', 'Datum' => '2026-02-03', 'Kc2' => '100', 'KcDPH2' => '21']],
            'BV' => [['ID' => '1', 'RelTpBV' => '2', 'Cislo' => 'TEST-B1', 'Kc0' => '121']],
            'Uhrady' => [['ID' => '7', 'RelIDH' => '10', 'RelAgH' => '3', 'RelAgU' => '28', 'DatumU' => '2026-02-10', 'CisloU' => 'TEST-B1', 'KcU' => '121']],
        ]);
        $invoice = iterator_to_array($mapper->records('received'))[0];
        self::assertSame(['id' => '', 'number' => 'TEST-B1'], $invoice['liquidations']['liquidation'][0]['sourceDocument']);
        self::assertSame('', iterator_to_array($mapper->records('bank'))[0]['bankHeader']['symPar']);
    }

    #[DataProvider('unknownTypes')]
    public function testUnknownTypesFailInsteadOfGuessing(array $tables, string $key, string $code): void
    {
        try {
            $mapper = $this->mapper($tables);
            iterator_to_array($mapper->records($key));
            self::fail('Unknown source type must be rejected.');
        } catch (PohodaException $error) {
            self::assertSame($code, $error->errorCode);
        }
    }

    public static function unknownTypes(): iterable
    {
        yield 'invoice' => [['FA' => [['ID' => '1', 'Cislo' => 'TEST-F1', 'RelTpFak' => '999']]], 'issued', 'mdb_invoice_type'];
        yield 'journal' => [['pUD' => [['ID' => '1', 'RelUdAg' => '999']]], 'journal', 'mdb_journal_source'];
        yield 'bank' => [['BV' => [['ID' => '1', 'RelTpBV' => '999']]], 'bank', 'mdb_direction'];
        yield 'historical VAT' => [['FA' => [['ID' => '1', 'Cislo' => 'TEST-F1', 'RelTpFak' => '1', 'HistSzDPH' => 'true']]], 'issued', 'mdb_historical_vat'];
    }

    public function testZipAutomaticallySelectsMdbReaderAndProducesOverview(): void
    {
        $this->mapper([
            'pUD' => [['ID' => '1', 'RelUdAg' => '2', 'Datum' => '2026-02-03', 'Cislo' => 'TEST-F1', 'UMD' => '311000', 'UD' => '602000', 'Kc' => '100']],
            'FA' => [['ID' => '1', 'RelTpFak' => '1', 'Cislo' => 'TEST-F1', 'Datum' => '2026-02-03', 'Kc2' => '100', 'KcDPH2' => '21']],
            'FApol' => [['ID' => '1', 'RefAg' => '1', 'RelSzDPH' => '2', 'Kc' => '100', 'KcDPH' => '21']],
        ]);
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($this->dir . '/source.zip', \ZipArchive::CREATE));
        $zip->addFile($this->path, '12345678_2026/' . PohodaMdbAccounting::FILE);
        $zip->close();
        PohodaExport::extractArchive($this->dir . '/source.zip', $this->dir . '/unpacked');
        $overview = PohodaExport::overview($this->dir . '/unpacked');
        self::assertCount(1, $overview);
        self::assertSame('12345678', $overview[0]['ico']);
        self::assertSame(2026, $overview[0]['year']);
        self::assertSame(1, $overview[0]['counts']['journal']);
        self::assertSame(1, $overview[0]['counts']['issued']);
        self::assertSame('2026-02-03', $overview[0]['counts']['first_date']);
        self::assertTrue($overview[0]['has_accounting']);
        $export = PohodaExport::open($this->dir . '/unpacked/12345678_2026');
        $first = iterator_to_array($export->records('issued', 'invoice'));
        self::assertSame($first, iterator_to_array($export->records('issued', 'invoice')));
        self::assertSame('121', $first[0]['invoiceSummary']['homeCurrency']['priceHighSum']);
        self::assertSame('121', $first[0]['invoiceDetail']['invoiceItem'][0]['homeCurrency']['priceSum']);
    }

    public function testMixedMdbAndOfficialXmlIsRejected(): void
    {
        $this->mapper([]);
        $agenda = $this->dir . '/12345678_2026';
        mkdir($agenda);
        copy($this->path, $agenda . '/' . PohodaMdbAccounting::FILE);
        file_put_contents($agenda . '/' . PohodaExport::FILES['journal'], '<root/>');
        $this->expectException(PohodaException::class);
        $this->expectExceptionMessage('současně MDB');
        PohodaExport::open($agenda);
    }

    public function testDirectoryMetadataMismatchIsRejected(): void
    {
        $this->mapper([]);
        $agenda = $this->dir . '/12345678_2025';
        mkdir($agenda);
        copy($this->path, $agenda . '/' . PohodaMdbAccounting::FILE);
        $this->expectException(PohodaException::class);
        $this->expectExceptionMessage('Složka agendy neodpovídá');
        PohodaExport::open($agenda);
    }

    public function testSourceMetadataMismatchIsRejected(): void
    {
        $this->expectException(PohodaException::class);
        $this->expectExceptionMessage('údajům zdrojové MDB');
        $this->mapper(['sKonfig' => [['ICO' => '12345678', 'Rok' => '2025']]]);
    }

    public function testUnsupportedHistoricalDateIsRejectedBeforeImport(): void
    {
        $this->expectException(PohodaException::class);
        $this->expectExceptionMessage('staršími sazbami');
        $this->mapper(['FA' => [['ID' => '1', 'Cislo' => 'TEST-OLD', 'RelTpFak' => '1', 'Datum' => '2012-12-31', 'Kc2' => '100']]]);
    }

    private function mapper(array $data): PohodaMdbAccounting
    {
        $tables = array_fill_keys(['pUD', 'pOS', 'sDPH', 'sDPHTp', 'pPK', 'FA', 'FApol', 'AD', 'sUcet', 'sZeme', 'sCMeny', 'sFormUh', 'BV', 'BVpol', 'HO', 'HOpol', 'pINT', 'pINTpol', 'Uhrady'], []);
        $tables['sKonfig'] = [['ICO' => '12345678', 'Rok' => '2026']];
        $xml = new \XMLWriter();
        $xml->openMemory();
        $xml->startElement('pohodaMdbAccounting');
        foreach (['formatVersion' => '1', 'ico' => '12345678', 'year' => '2026', 'programVersion' => 'test'] as $key => $value) {
            $xml->writeAttribute($key, $value);
        }
        foreach (array_replace($tables, $data) as $name => $rows) {
            $xml->startElement('table');
            $xml->writeAttribute('name', $name);
            foreach ($rows as $row) {
                $xml->startElement('row');
                foreach ($row as $key => $value) {
                    $xml->writeElement($key, $value);
                }
                $xml->endElement();
            }
            $xml->endElement();
        }
        $xml->endElement();
        file_put_contents($this->path, $xml->outputMemory());
        return new PohodaMdbAccounting(new PohodaMdbTables($this->path));
    }
}
