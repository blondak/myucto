<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Migration\StereoNx;

use MyInvoice\Service\Migration\StereoNx\StereoNxBackup;
use MyInvoice\Service\Migration\StereoNx\StereoNxBackupPassword;
use MyInvoice\Tests\Fixtures\StereoNx\SyntheticNx1Archive;
use PHPUnit\Framework\TestCase;

final class SyntheticNx1ArchiveTest extends TestCase
{
    public function testDefaultPasswordOpensArchiveWithoutUserInput(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'stereo_default_');
        $identity = ['ico' => '00000000', 'dic' => 'CZ00000000', 'name' => 'Synthetic', 'vat_payer' => true];
        try {
            SyntheticNx1Archive::write($path, ['Empty' => []], $identity, StereoNxBackupPassword::value());
            self::assertSame(0, StereoNxBackup::companies($path)[0]['index']);
            self::assertSame($identity, StereoNxBackup::open($path, 0)->companyIdentity());
        } finally {
            unlink($path);
        }
    }

    public function testEncryptedArchiveRoundTripsRowsIdentityAndEmptyTablesThroughRealReader(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'stereo_synthetic_');
        $identity = ['ico' => '00000000', 'dic' => 'CZ00000000', 'name' => 'Syntetická firma', 'vat_payer' => true];
        $rows = [];
        for ($i = 0; $i < 140; $i++) {
            $rows[] = ['Text' => 'Syntetická položka ' . $i, 'Castka' => $i + 0.25,
                'ID' => $i + 1, 'ZpracovatDPH' => $i % 2 === 0 ? true : null];
        }
        try {
            SyntheticNx1Archive::write($path, ['Rows' => $rows, 'Empty' => []], $identity, 'synthetic-password');
            $backup = StereoNxBackup::open($path, 0, 'synthetic-password');
            self::assertSame(0, $backup->companyIndex());
            self::assertSame($identity, $backup->companyIdentity());
            self::assertSame($rows, iterator_to_array($backup->rows('Rows')));
            self::assertSame([], iterator_to_array($backup->rows('Empty')));
            self::assertSame(['ok', 'ok'], array_column($backup->inventory(), 'status'));
        } finally {
            unlink($path);
        }
    }
}
