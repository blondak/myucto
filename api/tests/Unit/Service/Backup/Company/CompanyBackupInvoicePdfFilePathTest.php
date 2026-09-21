<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup\Company;

use MyInvoice\Service\Backup\Company\CompanyBackupInvoicePdfFilePath;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CompanyBackupInvoicePdfFilePathTest extends TestCase
{
    public function testUsesMonthlyCanonicalPathWithFlatFallbackCandidate(): void
    {
        $filename = '20260921-142530-a1b2c3d4-faktura č. 1.pdf';
        $monthly = 'sup-7/_archive/2026-09/' . $filename;
        $flat = 'sup-7/_archive/' . $filename;

        self::assertSame($monthly, CompanyBackupInvoicePdfFilePath::sourcePath($filename, 7));
        self::assertSame([$monthly, $flat], CompanyBackupInvoicePdfFilePath::sourceCandidates($filename, 7));
        self::assertTrue(CompanyBackupInvoicePdfFilePath::accepts($monthly, 7));
        self::assertTrue(CompanyBackupInvoicePdfFilePath::accepts($flat, 7));
        self::assertSame($filename, CompanyBackupInvoicePdfFilePath::storedFilename($monthly, 7));
    }

    public function testKeepsLegacyFilenameInFlatLayout(): void
    {
        $path = 'sup-7/_archive/legacy-faktura.pdf';
        self::assertSame($path, CompanyBackupInvoicePdfFilePath::sourcePath('legacy-faktura.pdf', 7));
        self::assertSame([$path], CompanyBackupInvoicePdfFilePath::sourceCandidates('legacy-faktura.pdf', 7));
        self::assertTrue(CompanyBackupInvoicePdfFilePath::accepts($path, 7));
    }

    public function testRestoreCanonicalizesFlatDatedSourceForTargetTenant(): void
    {
        $filename = '20260921-142530-a1b2c3d4-invoice.pdf';
        self::assertSame(
            'sup-81/_archive/2026-09/' . $filename,
            CompanyBackupInvoicePdfFilePath::restoreTargetPath(
                'sup-7/_archive/' . $filename,
                7,
                81,
            ),
        );
    }

    #[DataProvider('invalidPaths')]
    public function testRejectsWrongTenantMonthAndUnsafePaths(string $path): void
    {
        self::assertFalse(CompanyBackupInvoicePdfFilePath::accepts($path, 7));
        $this->expectException(\InvalidArgumentException::class);
        CompanyBackupInvoicePdfFilePath::storedFilename($path, 7);
    }

    /** @return iterable<string,array{string}> */
    public static function invalidPaths(): iterable
    {
        yield 'wrong tenant' => ['sup-8/_archive/2026-09/20260921-120000-aabbccdd-proof.pdf'];
        yield 'tenant prefix confusion' => ['sup-70/_archive/2026-09/20260921-120000-aabbccdd-proof.pdf'];
        yield 'wrong month' => ['sup-7/_archive/2026-08/20260921-120000-aabbccdd-proof.pdf'];
        yield 'arbitrary month for legacy' => ['sup-7/_archive/2026-09/legacy.pdf'];
        yield 'nested path' => ['sup-7/_archive/2026-09/nested/20260921-proof.pdf'];
        yield 'traversal' => ['sup-7/_archive/../20260921-120000-aabbccdd-proof.pdf'];
        yield 'Windows traversal' => ['sup-7/_archive/..\\20260921-120000-aabbccdd-proof.pdf'];
        yield 'ADS' => ['sup-7/_archive/20260921-120000-proof.pdf:stream'];
    }

    #[DataProvider('invalidFilenames')]
    public function testRejectsUnsafeBasenames(mixed $filename): void
    {
        $this->expectException(\InvalidArgumentException::class);
        CompanyBackupInvoicePdfFilePath::sourcePath($filename, 7);
    }

    /** @return iterable<string,array{mixed}> */
    public static function invalidFilenames(): iterable
    {
        yield 'traversal' => ['../proof.pdf'];
        yield 'Windows reserved' => ['CON.pdf'];
        yield 'ADS' => ['proof.pdf:stream'];
        yield '256 bytes' => [str_repeat('a', 256)];
        yield 'invalid UTF-8' => ["proof\xff.pdf"];
    }
}
