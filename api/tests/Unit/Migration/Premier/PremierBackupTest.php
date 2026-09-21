<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Migration\Premier;

use MyInvoice\Service\Migration\Premier\PremierBackup;
use MyInvoice\Service\Migration\Premier\PremierException;
use MyInvoice\Tests\Fixtures\Premier\CabWriter;
use MyInvoice\Tests\Fixtures\Premier\SyntheticPremierBackup;
use PHPUnit\Framework\TestCase;

/** Rozbalení zálohy PREMIER (iZIP / iCAB) a přehled firmy a let v ní. */
final class PremierBackupTest extends TestCase
{
    private string $tmp = '';

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'premier_backup_' . bin2hex(random_bytes(5));
        mkdir($this->tmp, 0755, true);
    }

    protected function tearDown(): void
    {
        if ($this->tmp === '' || !is_dir($this->tmp)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->tmp, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($this->tmp);
    }

    public function testZipAndCabExtractToTheSameUppercaseTables(): void
    {
        $zipDir = $this->tmp . '/zip';
        $cabDir = $this->tmp . '/cab';
        PremierBackup::extractArchive(SyntheticPremierBackup::writeZip($this->tmp . '/zaloha.izip', $this->tmp), $zipDir);
        PremierBackup::extractArchive(SyntheticPremierBackup::writeCab($this->tmp . '/zaloha.icab', $this->tmp), $cabDir);

        $expected = array_keys(SyntheticPremierBackup::files($this->tmp));
        self::assertContains('PUB_UCTO.DBF', $expected);
        self::assertContains('PUB_UCTO.FPT', $expected);
        self::assertSame($expected, $this->listing($zipDir), 'Jména se sjednotí na velká písmena, zaloha.txt se vynechá.');
        self::assertSame($expected, $this->listing($cabDir));
        foreach ($expected as $name) {
            self::assertSame(md5_file($zipDir . '/' . $name), md5_file($cabDir . '/' . $name), $name);
        }

        $zip = PremierBackup::open($zipDir);
        $cab = PremierBackup::open($cabDir);
        self::assertSame($zip->all('PUB_UCTO'), $cab->all('PUB_UCTO'));
        self::assertSame([SyntheticPremierBackup::YEAR1, SyntheticPremierBackup::YEAR2], $cab->years());
    }

    public function testOverviewListsYearsWithCompany(): void
    {
        $dir = SyntheticPremierBackup::writeDir($this->tmp . '/data');
        $backup = PremierBackup::open($dir);
        self::assertSame(SyntheticPremierBackup::ICO, $backup->ico);
        self::assertSame(SyntheticPremierBackup::DIC, $backup->dic, 'DIČ bez mezer.');
        self::assertSame(SyntheticPremierBackup::NAME, $backup->company);
        self::assertSame('CZK', $backup->homeCurrency);
        self::assertSame('2025-01-01', $backup->setting('A_ZACATEK'), 'Datum z D_SET, klíč bez ohledu na velikost písmen.');
        self::assertSame(100.0, (float) $backup->setting('a_koef'));
        self::assertNull($backup->setting('neexistuje'));

        // Smazaný zápis (hvězdička) se do počtu nezapočte.
        self::assertSame([2025 => 21, 2026 => 6], $backup->journalCountsByYear());

        $overview = PremierBackup::overview($dir);
        self::assertSame([2025, 2026], array_column($overview, 'year'));
        self::assertSame([21, 6], array_column($overview, 'entries'));
        foreach ($overview as $agenda) {
            self::assertSame(SyntheticPremierBackup::ICO, $agenda['ico']);
            self::assertSame(SyntheticPremierBackup::NAME, $agenda['company']);
            self::assertTrue($agenda['has_accounting']);
            self::assertFalse($agenda['has_payroll']);
        }
    }

    public function testArchiveWithoutJournalIsRejected(): void
    {
        $files = SyntheticPremierBackup::files($this->tmp);
        unset($files['PUB_UCTO.DBF'], $files['PUB_UCTO.FPT']);
        $zip = new \ZipArchive();
        $zip->open($this->tmp . '/bez_deniku.izip', \ZipArchive::CREATE);
        foreach ($files as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();

        self::assertSame('backup_not_premier', $this->error(fn () => PremierBackup::extractArchive($this->tmp . '/bez_deniku.izip', $this->tmp . '/out')));
    }

    public function testNonArchiveIsRejected(): void
    {
        file_put_contents($this->tmp . '/text.izip', 'toto není archiv');
        self::assertSame('backup_not_archive', $this->error(fn () => PremierBackup::extractArchive($this->tmp . '/text.izip', $this->tmp . '/out')));
    }

    public function testOpenRequiresCoreTables(): void
    {
        $dir = SyntheticPremierBackup::writeDir($this->tmp . '/data');
        unlink($dir . '/KODY_DPH.DBF');
        try {
            PremierBackup::open($dir);
            self::fail('Záloha bez číselníku DPH musí být odmítnuta.');
        } catch (PremierException $e) {
            self::assertSame(['backup_incomplete', 'KODY_DPH'], [$e->errorCode, $e->context['table']]);
        }
    }

    /**
     * Přebalený archiv: kopie tabulek o složku hlouběji (s jiným obsahem) a soubory, které
     * nejsou tabulky. Platí mělčí kopie, i když je v archivu až za hlubší.
     */
    public function testShallowestCopyWinsAndNonTablesAreIgnored(): void
    {
        $files = SyntheticPremierBackup::files($this->tmp);
        $zip = new \ZipArchive();
        $zip->open($this->tmp . '/prebaleny.izip', \ZipArchive::CREATE);
        $zip->addFromString('ZALOHA/STARA/pub_ucto.dbf', 'hlubší kopie - neplatí');
        $zip->addFromString('ZALOHA/STARA/Set_Glob.DBF', 'hlubší kopie - neplatí');
        foreach ($files as $name => $content) {
            $zip->addFromString('ZALOHA/' . $name, $content);
        }
        $zip->addFromString('ZALOHA/readme.txt', 'text');
        $zip->addFromString('ZALOHA/FOXUSER.DBF.bak', 'záloha');
        $zip->addFromString('ZALOHA/../../utek.dbf', 'mimo cíl');
        $zip->addFromString('ZALOHA/podivne jmeno.dbf', 'mezera ve jménu');
        $zip->close();

        PremierBackup::extractArchive($this->tmp . '/prebaleny.izip', $this->tmp . '/out');

        self::assertSame(md5($files['PUB_UCTO.DBF']), md5_file($this->tmp . '/out/PUB_UCTO.DBF'));
        self::assertSame(md5($files['SET_GLOB.DBF']), md5_file($this->tmp . '/out/SET_GLOB.DBF'));
        $expected = array_merge(array_keys($files), ['UTEK.DBF']);
        sort($expected);
        self::assertSame($expected, $this->listing($this->tmp . '/out'), 'Mimo cíl nic nevznikne - jméno se bere bez cesty.');
        self::assertFileDoesNotExist($this->tmp . '/utek.dbf');
        self::assertSame(SyntheticPremierBackup::ICO, PremierBackup::open($this->tmp . '/out')->ico);
    }

    public function testCabWithNestedDuplicateKeepsFirstCopy(): void
    {
        $files = SyntheticPremierBackup::files($this->tmp);
        $cab = [];
        foreach ($files as $name => $content) {
            $cab['DATA\\' . $name] = $content;
        }
        $cab['DATA\\STARA\\PUB_UCTO.DBF'] = 'druhá kopie - neplatí';
        CabWriter::write($this->tmp . '/zaloha.icab', $cab);

        PremierBackup::extractArchive($this->tmp . '/zaloha.icab', $this->tmp . '/out');
        self::assertSame(md5($files['PUB_UCTO.DBF']), md5_file($this->tmp . '/out/PUB_UCTO.DBF'));
    }

    /** @return list<string> */
    private function listing(string $dir): array
    {
        $out = array_values(array_filter(scandir($dir) ?: [], static fn (string $f): bool => is_file($dir . '/' . $f)));
        sort($out);
        return $out;
    }

    private function error(callable $fn): string
    {
        try {
            $fn();
        } catch (PremierException $e) {
            return $e->errorCode;
        }
        self::fail('Očekávaná chyba PremierException nenastala.');
    }
}
