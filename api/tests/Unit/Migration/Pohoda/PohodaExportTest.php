<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Migration\Pohoda;

use MyInvoice\Service\Migration\Pohoda\PohodaException;
use MyInvoice\Service\Migration\Pohoda\PohodaExport;
use MyInvoice\Service\Migration\Pohoda\PohodaJournal;
use MyInvoice\Service\Migration\Pohoda\PohodaVat;
use MyInvoice\Service\Migration\Pohoda\PohodaXml;
use MyInvoice\Tests\Fixtures\Pohoda\SyntheticPohodaExport;
use PHPUnit\Framework\TestCase;

/**
 * Čtení XML exportu z POHODY: strany deníku, členění DPH, proudové čtení záznamů
 * a bezpečné rozbalení ZIP exportu.
 */
final class PohodaExportTest extends TestCase
{
    private string $tmp = '';

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pohoda_unit_' . bin2hex(random_bytes(5));
        mkdir($this->tmp, 0755, true);
    }

    protected function tearDown(): void
    {
        if ($this->tmp !== '' && is_dir($this->tmp)) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->tmp, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($it as $f) {
                $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
            }
            @rmdir($this->tmp);
        }
    }

    /** POHODA pojmenovává strany obráceně: `act:credit` je MD, `act:debit` Dal. */
    public function testJournalSidesFollowPohodaNaming(): void
    {
        $item = ['homeCurrency' => ['priceSum' => '500'], 'accounting' => ['credit' => '518000', 'debit' => '321001']];
        self::assertSame(['debit' => '518000', 'credit' => '321001', 'amount' => 500.0], PohodaJournal::effect($item));

        $negative = ['homeCurrency' => ['priceSum' => '-80'], 'accounting' => ['credit' => '221001', 'debit' => '311001']];
        self::assertSame(['debit' => '311001', 'credit' => '221001', 'amount' => 80.0], PohodaJournal::effect($negative));

        $same = ['homeCurrency' => ['priceSum' => '10'], 'accounting' => ['credit' => '349000', 'debit' => '349000']];
        self::assertNull(PohodaJournal::effect($same));
        self::assertSame(PohodaJournal::OPENING_KEY, PohodaJournal::groupKey(['source' => PohodaJournal::OPENING]));
    }

    public function testExportIsReadInWindows1250AndSidesAreSummed(): void
    {
        $export = PohodaExport::open(SyntheticPohodaExport::write($this->tmp));
        self::assertSame(SyntheticPohodaExport::ICO, $export->ico);
        self::assertSame(SyntheticPohodaExport::YEAR, $export->year);

        $sources = [];
        foreach ($export->records('journal', 'accountingItem') as $item) {
            $sources[PohodaJournal::source($item)] = true;
        }
        self::assertArrayHasKey('Počáteční stavy účtů', $sources, 'Diakritika z Windows-1250 musí projít do UTF-8.');

        $tb = PohodaJournal::trialBalance($export);
        self::assertSame([100000.0, 555.0, 100555.0], $tb['221']);
        self::assertSame([0.0, 500.0, 500.0], $tb['518']);
    }

    /** Členění se páruje podle řádků přiznání v číselníku jednotky, ne podle zkratky. */
    public function testVatClassificationByReturnLines(): void
    {
        $vat = PohodaVat::fromExport(PohodaExport::open(SyntheticPohodaExport::write($this->tmp)));
        self::assertSame(['in_return' => true, 'code' => null], $vat->sale('UD', 21.0));
        self::assertSame(['in_return' => false, 'code' => null], $vat->sale('UN', 0.0));
        self::assertSame(['in_return' => true, 'deduction' => 'full', 'reverse' => false], $vat->purchase('PD'));
        self::assertSame(['in_return' => true, 'deduction' => 'full', 'reverse' => true], $vat->purchase('PDslRegEU'));
        self::assertSame('24e', $vat->selfAssessmentCode('DDslRegEU'));
        self::assertTrue($vat->forcesA5('UDA5'));
        self::assertFalse($vat->forcesA5('UD'));
        self::assertNull($vat->sale('NEZNAME', 21.0));
    }

    public function testRepeatedElementsAndAttributes(): void
    {
        $file = $this->tmp . '/list.xml';
        file_put_contents($file, '<?xml version="1.0" encoding="UTF-8"?><root><invoice><item><price>1</price></item><item><price>2</price></item>'
            . '<rate value="21">high</rate></invoice><invoice><item><price>3</price></item></invoice></root>');
        $records = iterator_to_array(PohodaXml::records($file, 'invoice'), false);
        self::assertCount(2, $records);
        self::assertCount(2, PohodaXml::all($records[0], 'item'));
        self::assertCount(1, PohodaXml::all($records[1], 'item'));
        self::assertSame('21', PohodaXml::attr($records[0], 'rate', 'value'));
        self::assertSame('high', PohodaXml::text($records[0], 'rate'));
        self::assertSame(2, PohodaXml::count($file, 'invoice'));
    }

    public function testDoctypeIsRejected(): void
    {
        $file = $this->tmp . '/evil.xml';
        file_put_contents($file, '<?xml version="1.0"?><!DOCTYPE root [<!ENTITY x "y">]><root><invoice>&x;</invoice></root>');
        $this->expectException(PohodaException::class);
        iterator_to_array(PohodaXml::records($file, 'invoice'));
    }

    /** DOCTYPE schovaný za dlouhým komentářem v prologu se odmítne stejně jako na začátku souboru. */
    public function testDoctypeAfterPaddingIsRejected(): void
    {
        $file = $this->tmp . '/padded.xml';
        file_put_contents($file, '<?xml version="1.0"?><!--' . str_repeat(' ', 9000) . '--><!DOCTYPE root [<!ENTITY x "y">]><root><invoice>&x;</invoice></root>');
        $this->expectException(PohodaException::class);
        iterator_to_array(PohodaXml::records($file, 'invoice'));
    }

    /** Hlavička balíku se čte i v HTTP náhledu - DOCTYPE tam nesmí projít. */
    public function testDoctypeInPackHeaderIsRejected(): void
    {
        $file = $this->tmp . '/pack.xml';
        file_put_contents($file, '<?xml version="1.0"?><!--' . str_repeat(' ', 9000) . '--><!DOCTYPE r [<!ENTITY a "12345678">]><responsePack ico="&a;" state="ok"/>');
        $this->expectException(PohodaException::class);
        PohodaXml::packInfo($file);
    }

    /** Dva soubory lišící se jen velikostí písmen by se na Windows tiše přepsaly - ZIP se odmítne. */
    public function testArchiveWithCaseCollisionIsRejected(): void
    {
        $zipPath = $this->tmp . '/case.zip';
        $zip = new \ZipArchive();
        $zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString(SyntheticPohodaExport::ICO . '_2026/01_ucetni_denik.xml', '<a/>');
        $zip->addFromString('obal/' . SyntheticPohodaExport::ICO . '_2026/01_UCETNI_DENIK.xml', '<b/>');
        $zip->close();
        $this->expectException(PohodaException::class);
        PohodaExport::extractArchive($zipPath, $this->tmp . '/out');
    }

    /** Z archivu se berou jen XML agend a přehled jednotek, jméno z archivu nikdy nevede mimo cíl. */
    public function testArchiveExtractsOnlyAgendaFiles(): void
    {
        $zipPath = $this->tmp . '/export.zip';
        SyntheticPohodaExport::writeZip($zipPath, $this->tmp . '/src');
        $zip = new \ZipArchive();
        $zip->open($zipPath);
        $zip->addFromString('../../mimo.xml', '<x/>');
        $zip->addFromString(SyntheticPohodaExport::ICO . '_' . SyntheticPohodaExport::YEAR . '/skript.php', '<?php echo 1;');
        $zip->addFromString('obal/' . SyntheticPohodaExport::ICO . '_2025/01_ucetni_denik.xml', '<x/>');
        $zip->close();

        $target = $this->tmp . '/out';
        PohodaExport::extractArchive($zipPath, $target);

        self::assertFileExists($target . '/00_ucetni_jednotky.xml');
        self::assertFileExists($target . '/' . SyntheticPohodaExport::ICO . '_' . SyntheticPohodaExport::YEAR . '/01_ucetni_denik.xml');
        self::assertFileExists($target . '/' . SyntheticPohodaExport::ICO . '_2025/01_ucetni_denik.xml', 'Agenda ve vnořené složce se rozbalí do kořene.');
        self::assertFileDoesNotExist($target . '/' . SyntheticPohodaExport::ICO . '_' . SyntheticPohodaExport::YEAR . '/skript.php');
        self::assertFileDoesNotExist($this->tmp . '/mimo.xml');
        self::assertFileDoesNotExist($target . '/souhrn.txt');

        $overview = PohodaExport::overview($target);
        $agenda = array_values(array_filter($overview, static fn (array $a): bool => $a['year'] === SyntheticPohodaExport::YEAR))[0];
        self::assertSame(SyntheticPohodaExport::NAME, $agenda['company']);
        self::assertSame(9, $agenda['counts']['journal']);
        self::assertSame(2, $agenda['counts']['opening']);
        self::assertSame(2, $agenda['counts']['issued']);
        self::assertSame(1, $agenda['counts']['purchase']);
        self::assertSame(4, $agenda['counts']['bank']);
        self::assertSame(2, $agenda['counts']['partners']);
    }

    public function testNonZipIsRejected(): void
    {
        file_put_contents($this->tmp . '/x.zip', 'neni zip');
        $this->expectException(PohodaException::class);
        PohodaExport::extractArchive($this->tmp . '/x.zip', $this->tmp . '/out');
    }
}
