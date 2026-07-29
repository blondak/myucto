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
}
