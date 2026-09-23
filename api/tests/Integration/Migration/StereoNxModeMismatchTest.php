<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Migration;

use MyInvoice\Bootstrap;
use MyInvoice\Service\Migration\StereoNx\StereoNxAccountingImporter;
use MyInvoice\Service\Migration\StereoNx\StereoNxBackup;
use MyInvoice\Service\Migration\StereoNx\StereoNxImporter;
use MyInvoice\Tests\Fixtures\StereoNx\SyntheticNx1Archive;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/** The source-mode guard must reject the opposite importer before reading required agenda tables. */
#[Group('integration')]
final class StereoNxModeMismatchTest extends TestCase
{
    public function testTaxEvidenceImporterRejectsDoubleEntrySourceBeforeAnyWrite(): void
    {
        $this->assertOppositeModeRejected(
            StereoNxImporter::class,
            [['UcetMD' => '311', 'UcetD' => '602']],
        );
    }

    public function testAccountingImporterRejectsTaxEvidenceSourceBeforeAnyWrite(): void
    {
        $this->assertOppositeModeRejected(
            StereoNxAccountingImporter::class,
            [['UcetMD' => '', 'UcetD' => '']],
        );
    }

    private function assertOppositeModeRejected(string $importerClass, array $journal): void
    {
        $path = tempnam(sys_get_temp_dir(), 'stereo_mode_guard_');
        try {
            SyntheticNx1Archive::write($path, ['Cdenik' => $journal],
                ['ico' => '00000000', 'dic' => 'CZ00000000', 'name' => 'Syntetická firma', 'vat_payer' => true]);
            $importer = Bootstrap::buildApp()->getContainer()->get($importerClass);
            $report = $importer->run(StereoNxBackup::open($path, 0), 1, 1, true);
            self::assertFalse($report['ok']);
            self::assertFalse($report['database_writes']);
            self::assertSame('source_accounting_mode_mismatch', $report['errors'][0]['code'] ?? null);
        } finally {
            unlink($path);
        }
    }
}
