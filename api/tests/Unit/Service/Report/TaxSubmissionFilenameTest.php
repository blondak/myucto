<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Report;

use MyInvoice\Service\Report\TaxSubmissionFilename;
use PHPUnit\Framework\TestCase;

final class TaxSubmissionFilenameTest extends TestCase
{
    public function testBuildsUniqueReadableQuarterlyArtifactName(): void
    {
        $filename = TaxSubmissionFilename::forSnapshot(
            [
                'id' => 1099,
                'form_code' => 'dphdp3',
                'form_variant' => 'D',
                'period_year' => 2026,
                'period_month' => null,
                'period_quarter' => 2,
            ],
            'confirmation.p7s',
            21,
            new \DateTimeImmutable('2026-07-27 11:22:33.123456'),
        );

        self::assertSame(
            'DPHDP3-2026-Q2-D-s1099-a21-20260727-112233-123456-confirmation.p7s',
            $filename,
        );
    }

    public function testUsesSnapshotTimestampAndIdForRepeatedArchiveDownloads(): void
    {
        $first = TaxSubmissionFilename::forSnapshot([
            'id' => 10,
            'form_code' => 'dphkh1',
            'period_year' => 2026,
            'period_month' => 6,
            'period_quarter' => null,
            'generated_at' => '2026-07-27 09:15:00',
        ], 'archive.xml');
        $second = TaxSubmissionFilename::forSnapshot([
            'id' => 11,
            'form_code' => 'dphkh1',
            'period_year' => 2026,
            'period_month' => 6,
            'period_quarter' => null,
            'generated_at' => '2026-07-27 09:15:00',
        ], 'archive.xml');

        self::assertSame('DPHKH1-2026-06-s10-20260727-091500-000000-archive.xml', $first);
        self::assertSame('DPHKH1-2026-06-s11-20260727-091500-000000-archive.xml', $second);
        self::assertNotSame($first, $second);
    }

    /**
     * Regrese pro GitHub issue #27: DPH přiznání, kontrolní i souhrnné hlášení
     * volají forSnapshot() s holou příponou 'xml' (bez názvu artefaktu). Ta se
     * nesmí připojit pomlčkou (…-xml), ale tečkou (….xml), jinak si soubor
     * podle přípony nerozezná ani systém, ani uživatel.
     */
    public function testBareExtensionSuffixIsJoinedWithDotNotDash(): void
    {
        $filename = TaxSubmissionFilename::forSnapshot(
            [
                'id' => 3,
                'form_code' => 'dphshv',
                'period_year' => 2026,
                'period_month' => 7,
                'period_quarter' => null,
            ],
            'xml',
            null,
            new \DateTimeImmutable('2026-08-19 13:42:15.327900'),
        );

        self::assertSame(
            'DPHSHV-2026-07-s3-20260819-134215-327900.xml',
            $filename,
        );
        self::assertStringEndsWith('.xml', $filename);
        self::assertStringNotContainsString('-xml', $filename);
    }
}
