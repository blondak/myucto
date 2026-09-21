<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Migration\Premier;

use MyInvoice\Service\Migration\Premier\Dbf\CabinetExtractor;
use MyInvoice\Service\Migration\Premier\PremierException;
use MyInvoice\Tests\Fixtures\Premier\CabWriter;
use PHPUnit\Framework\TestCase;

/** Rozbalení zálohy iCAB (Microsoft Cabinet, komprese MSZIP) bez externích nástrojů. */
final class CabinetExtractorTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'premier_cab_' . bin2hex(random_bytes(5));
        mkdir($this->dir . DIRECTORY_SEPARATOR . 'out', 0755, true);
    }

    protected function tearDown(): void
    {
        foreach ([$this->dir . DIRECTORY_SEPARATOR . 'out', $this->dir] as $d) {
            foreach (glob($d . DIRECTORY_SEPARATOR . '*') ?: [] as $f) {
                is_file($f) && @unlink($f);
            }
            @rmdir($d);
        }
    }

    /**
     * Čtyři soubory přes několik bloků CFDATA. První soubor je třikrát tatáž náhodná
     * (nekomprimovatelná) posloupnost - druhý blok ji zkomprimuje jen odkazem do slovníku
     * předchozího bloku, takže bez něj by rozbalení selhalo.
     */
    public function testExtractsMultiBlockMszipFolderByteForByte(): void
    {
        mt_srand(20250101);
        $random = '';
        for ($i = 0; $i < 20000; $i++) {
            $random .= chr(mt_rand(0, 255));
        }
        $files = [
            'DATA\\PUB_UCTO.DBF' => $random . $random . $random . 'konec',
            'DATA\\osnova.dbf' => str_repeat('Účtová osnova ', 5000),
            'DATA\\PRAZDNY.DBF' => '',
            'DATA\\ZBYTEK.FPT' => str_repeat("\x01\x02\x03", 9000),
        ];
        $cab = $this->dir . DIRECTORY_SEPARATOR . 'zaloha.icab';
        CabWriter::write($cab, $files);
        self::assertTrue(CabinetExtractor::isCabinet($cab));
        // Bez slovníku by druhý blok (7 232 B dokončení kopie + 20 000 B třetí kopie) zůstal
        // nekomprimovaný; se slovníkem je to pár odkazů do předchozího bloku.
        self::assertLessThan(20000 + 8000, filesize($cab), 'Opakování náhodných dat musí jít přes slovník předchozího bloku.');

        $extractor = new CabinetExtractor($cab);
        self::assertSame([
            ['name' => 'DATA/PUB_UCTO.DBF', 'size' => 60005],
            ['name' => 'DATA/osnova.dbf', 'size' => strlen($files['DATA\\osnova.dbf'])],
            ['name' => 'DATA/PRAZDNY.DBF', 'size' => 0],
            ['name' => 'DATA/ZBYTEK.FPT', 'size' => 27000],
        ], $extractor->entries());

        $written = $extractor->extract($this->dir . DIRECTORY_SEPARATOR . 'out', static fn (string $name): ?string => strtoupper(basename($name)), PHP_INT_MAX);
        self::assertSame(array_sum(array_map('strlen', $files)), $written);
        foreach ($files as $name => $content) {
            $target = $this->dir . DIRECTORY_SEPARATOR . 'out' . DIRECTORY_SEPARATOR . strtoupper(basename(str_replace('\\', '/', $name)));
            self::assertFileExists($target);
            self::assertSame(md5($content), md5((string) file_get_contents($target)), $name);
        }
    }

    public function testExtractsOnlyAcceptedFiles(): void
    {
        $cab = $this->dir . DIRECTORY_SEPARATOR . 'zaloha.icab';
        CabWriter::write($cab, ['A.DBF' => str_repeat('a', 40000), 'B.TXT' => 'b', 'C.DBF' => str_repeat('c', 30000)]);

        $extractor = new CabinetExtractor($cab);
        $written = $extractor->extract($this->dir . DIRECTORY_SEPARATOR . 'out', static fn (string $n): ?string => str_ends_with($n, '.DBF') ? 'X_' . $n : null, PHP_INT_MAX);

        self::assertSame(70000, $written);
        self::assertSame(str_repeat('c', 30000), file_get_contents($this->dir . DIRECTORY_SEPARATOR . 'out' . DIRECTORY_SEPARATOR . 'X_C.DBF'));
        self::assertFileDoesNotExist($this->dir . DIRECTORY_SEPARATOR . 'out' . DIRECTORY_SEPARATOR . 'X_B.TXT');
    }

    public function testUncompressedFolderIsSupported(): void
    {
        $cab = $this->dir . DIRECTORY_SEPARATOR . 'zaloha.icab';
        CabWriter::write($cab, ['A.DBF' => str_repeat('xyz', 20000)], CabWriter::COMPRESSION_NONE);

        (new CabinetExtractor($cab))->extract($this->dir . DIRECTORY_SEPARATOR . 'out', static fn (string $n): string => $n, PHP_INT_MAX);
        self::assertSame(str_repeat('xyz', 20000), file_get_contents($this->dir . DIRECTORY_SEPARATOR . 'out' . DIRECTORY_SEPARATOR . 'A.DBF'));
    }

    public function testSizeLimitIsEnforced(): void
    {
        $cab = $this->dir . DIRECTORY_SEPARATOR . 'zaloha.icab';
        CabWriter::write($cab, ['A.DBF' => str_repeat('a', 5000)]);
        self::assertSame('backup_too_large', $this->error(fn () => (new CabinetExtractor($cab))->extract($this->dir . DIRECTORY_SEPARATOR . 'out', static fn (string $n): string => $n, 4999)));
    }

    public function testRejectsNonCabinet(): void
    {
        $zip = $this->dir . DIRECTORY_SEPARATOR . 'zaloha.izip';
        file_put_contents($zip, "PK\x03\x04" . str_repeat("\0", 60));
        self::assertFalse(CabinetExtractor::isCabinet($zip));
        self::assertSame('backup_not_cab', $this->error(fn () => new CabinetExtractor($zip)));
    }

    public function testRejectsMultiPartCabinet(): void
    {
        $cab = $this->dir . DIRECTORY_SEPARATOR . 'zaloha.icab';
        CabWriter::write($cab, ['A.DBF' => 'data'], CabWriter::COMPRESSION_MSZIP, 0x0002);
        self::assertSame('backup_multipart', $this->error(fn () => new CabinetExtractor($cab)));
    }

    public function testRejectsLzxCompression(): void
    {
        $cab = $this->dir . DIRECTORY_SEPARATOR . 'zaloha.icab';
        CabWriter::write($cab, ['A.DBF' => 'data'], CabWriter::COMPRESSION_LZX);
        self::assertSame('backup_compression', $this->error(fn () => new CabinetExtractor($cab)));
    }

    public function testRejectsTruncatedData(): void
    {
        $cab = $this->dir . DIRECTORY_SEPARATOR . 'zaloha.icab';
        CabWriter::write($cab, ['A.DBF' => str_repeat('abc', 30000)]);
        $bytes = (string) file_get_contents($cab);
        file_put_contents($cab, substr($bytes, 0, strlen($bytes) - 20));
        self::assertSame('backup_corrupted', $this->error(fn () => (new CabinetExtractor($cab))->extract($this->dir . DIRECTORY_SEPARATOR . 'out', static fn (string $n): string => $n, PHP_INT_MAX)));
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
