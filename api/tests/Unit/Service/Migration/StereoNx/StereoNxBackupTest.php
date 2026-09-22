<?php

declare(strict_types=1);
namespace MyInvoice\Tests\Unit\Service\Migration\StereoNx;

use MyInvoice\Service\Migration\StereoNx\StereoNxBackup;
use MyInvoice\Service\Migration\StereoNx\StereoNxException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ZipArchive;

final class StereoNxBackupTest extends TestCase
{
    private string $path;

    protected function setUp(): void { $this->path = tempnam(sys_get_temp_dir(), 'stereo-test-'); }
    protected function tearDown(): void { unlink($this->path); }

    private function archive(array $extra = [], bool $encrypted = false): void
    {
        $zip = new ZipArchive();
        self::assertTrue($zip->open($this->path, ZipArchive::OVERWRITE));
        $entries = ['ObsahBck.txt' => "26.1.00\nZáloha\nFirmy|1|0\n0|Testovací firma|synthetic\n",
            'Firma_0/Table.nx1' => 'synthetic invalid NX1'] + $extra;
        foreach ($entries as $name => $value) {
            $zip->addFromString($name, $value);
            if ($encrypted) self::assertTrue($zip->setEncryptionName($name, ZipArchive::EM_AES_256, 'synthetic-test-password'));
        }
        self::assertTrue($zip->close());
    }

    public function testEncryptedManifestAndCompanySelection(): void
    {
        $this->archive(encrypted: true);
        self::assertSame(0, StereoNxBackup::companies($this->path, 'synthetic-test-password')[0]['index']);
        $this->expectException(StereoNxException::class);
        StereoNxBackup::open($this->path, 1, 'synthetic-test-password');
    }

    public function testWrongPasswordFails(): void
    {
        $this->archive(encrypted: true);
        $this->expectException(StereoNxException::class);
        $this->expectExceptionMessage('ověřte heslo');
        StereoNxBackup::companies($this->path, 'wrong-test-password');
    }

    public function testUnreadableTableIsNotReportedAsEmptyOrSuccessful(): void
    {
        $this->archive();
        $inventory = StereoNxBackup::open($this->path, 0)->inventory();
        self::assertSame('decode_error', $inventory['Table']['status']);
        self::assertNull($inventory['Table']['declared_rows']);
        self::assertStringNotContainsString('synthetic invalid NX1', json_encode($inventory));
    }

    #[DataProvider('unsafeNames')]
    public function testRejectsUnsafeOrCaseAmbiguousNames(string $name): void
    {
        $this->archive([$name => 'synthetic']);
        $this->expectException(StereoNxException::class);
        StereoNxBackup::companies($this->path);
    }

    public static function unsafeNames(): array
    {
        return [['../outside'], ['Firma_0/../outside'], ['C:/outside'], ['/outside'],
            ['Firma_0\\Table.nx1'], ['firma_0/table.NX1'], ['obsahbck.txt']];
    }

    public function testMissingTableFails(): void
    {
        $this->archive();
        $this->expectException(StereoNxException::class);
        iterator_to_array(StereoNxBackup::open($this->path, 0)->rows('Absent'));
    }
}
