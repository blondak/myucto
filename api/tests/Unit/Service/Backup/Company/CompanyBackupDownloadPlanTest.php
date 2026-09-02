<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup\Company;

use MyInvoice\Service\Backup\Company\CompanyBackupDownloadPlan;
use MyInvoice\Service\Backup\Company\CompanyBackupDownloadRangeException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CompanyBackupDownloadPlanTest extends TestCase
{
    private const SHA256 = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';
    private const ETAG = '"sha256:' . self::SHA256 . '"';

    public function testPlansFullImmutableDownloadWithoutRange(): void
    {
        $plan = CompanyBackupDownloadPlan::forArchive(1_000, self::SHA256);

        self::assertSame(200, $plan->statusCode);
        self::assertSame(0, $plan->offset);
        self::assertSame(1_000, $plan->length);
        self::assertSame(999, $plan->endInclusive());
        self::assertSame(1_000, $plan->totalBytes);
        self::assertSame(self::ETAG, $plan->etag);
        self::assertNull($plan->contentRange());
        self::assertFalse($plan->isPartial());
    }

    #[DataProvider('satisfiableRanges')]
    public function testPlansSingleSatisfiableByteRange(
        string $header,
        int $offset,
        int $length,
        string $contentRange,
    ): void {
        $plan = CompanyBackupDownloadPlan::forArchive(
            1_000,
            self::SHA256,
            $header,
        );

        self::assertSame(206, $plan->statusCode);
        self::assertSame($offset, $plan->offset);
        self::assertSame($length, $plan->length);
        self::assertSame($offset + $length - 1, $plan->endInclusive());
        self::assertSame($contentRange, $plan->contentRange());
        self::assertTrue($plan->isPartial());
    }

    /** @return iterable<string,array{string,int,int,string}> */
    public static function satisfiableRanges(): iterable
    {
        yield 'closed' => ['bytes=100-199', 100, 100, 'bytes 100-199/1000'];
        yield 'open end' => ['bytes=900-', 900, 100, 'bytes 900-999/1000'];
        yield 'suffix' => ['bytes=-125', 875, 125, 'bytes 875-999/1000'];
        yield 'oversized suffix' => ['bytes=-1500', 0, 1_000, 'bytes 0-999/1000'];
        yield 'clamped end' => ['bytes=950-2000', 950, 50, 'bytes 950-999/1000'];
        yield 'case insensitive unit' => ['BYTES=0-0', 0, 1, 'bytes 0-0/1000'];
    }

    public function testHonoursRangeOnlyForExactStrongIfRangeEtag(): void
    {
        $partial = CompanyBackupDownloadPlan::forArchive(
            1_000,
            self::SHA256,
            'bytes=500-',
            self::ETAG,
        );
        self::assertSame(206, $partial->statusCode);

        foreach ([
            'W/' . self::ETAG,
            '"sha256:ffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffff"',
            'Wed, 02 Sep 2026 10:00:00 GMT',
        ] as $ifRange) {
            $full = CompanyBackupDownloadPlan::forArchive(
                1_000,
                self::SHA256,
                'bytes=500-',
                $ifRange,
            );
            self::assertSame(200, $full->statusCode);
            self::assertSame(0, $full->offset);
            self::assertSame(1_000, $full->length);
            self::assertNull($full->contentRange());
        }
    }

    public function testIgnoresMalformedRangeWhenIfRangeNoLongerMatches(): void
    {
        $plan = CompanyBackupDownloadPlan::forArchive(
            1_000,
            self::SHA256,
            'not-a-range',
            '"old-etag"',
        );

        self::assertSame(200, $plan->statusCode);
        self::assertSame(1_000, $plan->length);
    }

    #[DataProvider('unsatisfiableRanges')]
    public function testRejectsMalformedMultipleAndUnsatisfiableRanges(string $header): void
    {
        try {
            CompanyBackupDownloadPlan::forArchive(1_000, self::SHA256, $header);
            self::fail('Neplatný nebo neuspokojitelný Range nesmí být přijat.');
        } catch (CompanyBackupDownloadRangeException $e) {
            self::assertSame('range_not_satisfiable', $e->errorCode);
            self::assertSame(1_000, $e->totalBytes);
            self::assertSame('bytes */1000', $e->contentRange());
        }
    }

    /** @return iterable<string,array{string}> */
    public static function unsatisfiableRanges(): iterable
    {
        yield 'empty' => [''];
        yield 'wrong unit' => ['items=0-1'];
        yield 'both positions empty' => ['bytes=-'];
        yield 'spaces inside syntax' => ['bytes = 0-1'];
        yield 'multiple ranges' => ['bytes=0-1,10-11'];
        yield 'start outside representation' => ['bytes=1000-'];
        yield 'reversed range' => ['bytes=500-499'];
        yield 'zero suffix' => ['bytes=-0'];
        yield 'integer overflow' => ['bytes=999999999999999999999999999999-'];
    }

    #[DataProvider('invalidArchiveMetadata')]
    public function testRejectsInvalidArchiveMetadata(int $bytes, string $sha256): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Metadata archivu');

        CompanyBackupDownloadPlan::forArchive($bytes, $sha256);
    }

    /** @return iterable<string,array{int,string}> */
    public static function invalidArchiveMetadata(): iterable
    {
        yield 'empty archive' => [0, self::SHA256];
        yield 'uppercase hash' => [1, strtoupper(self::SHA256)];
        yield 'short hash' => [1, 'abcd'];
    }
}
