<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup;

use MyInvoice\Service\Backup\BackupZipPermissions;
use PHPUnit\Framework\TestCase;
use ZipArchive;

/**
 * Záloha nesmí nést práva zdrojového systému — libzip razí položky jako
 * `opsys = UNIX` a mód bere ze zdrojového souboru (Linux) nebo z pevné
 * hodnoty (Windows), takže rozbalená záloha jinak dědí umask a vlastníka
 * instalace a obnova končí ručním chmodem.
 */
final class BackupZipPermissionsTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/myinv-zipperm-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0775, true);
    }

    protected function tearDown(): void
    {
        foreach ((array) glob($this->dir . '/*') as $f) {
            @unlink((string) $f);
        }
        @rmdir($this->dir);
    }

    public function testEntryFromRestrictedSourceFileEndsUpReadable(): void
    {
        $src = $this->dir . '/faktura.pdf';
        file_put_contents($src, '%PDF-1.4');
        // Přesně ten případ, který obnovu bolí: soubor čitelný jen pro vlastníka.
        @chmod($src, 0600);

        $zipFile = $this->dir . '/backup.zip';
        $zip = new ZipArchive();
        self::assertTrue($zip->open($zipFile, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        self::assertTrue($zip->addFile($src, 'storage/invoices/faktura.pdf'));
        self::assertTrue(BackupZipPermissions::neutralize($zip, 'storage/invoices/faktura.pdf'));
        self::assertTrue($zip->close());

        [$opsys, $mode] = $this->entryMode($zipFile, 'storage/invoices/faktura.pdf');

        self::assertSame(ZipArchive::OPSYS_UNIX, $opsys);
        self::assertSame(0100644, $mode, 'položka musí mít pevné rw-r--r--, ne mód zdrojového souboru');
    }

    public function testDirectoryEntryGetsTraversableMode(): void
    {
        $zipFile = $this->dir . '/backup.zip';
        $zip = new ZipArchive();
        self::assertTrue($zip->open($zipFile, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        self::assertNotFalse($zip->addEmptyDir('storage/invoices'));
        self::assertTrue(BackupZipPermissions::neutralize($zip, 'storage/invoices/', isDir: true));
        self::assertTrue($zip->close());

        [$opsys, $mode] = $this->entryMode($zipFile, 'storage/invoices/');

        self::assertSame(ZipArchive::OPSYS_UNIX, $opsys);
        self::assertSame(040755, $mode);
    }

    /** @return array{0:int, 1:int} opsys, unixový mód položky */
    private function entryMode(string $zipFile, string $entry): array
    {
        $zip = new ZipArchive();
        self::assertTrue($zip->open($zipFile));
        $opsys = 0;
        $attr  = 0;
        self::assertTrue($zip->getExternalAttributesName($entry, $opsys, $attr));
        $zip->close();

        return [$opsys, ($attr >> 16) & 0xFFFF];
    }
}
