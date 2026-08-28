<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Mail;

use MyInvoice\Service\Mail\SafeLogoPath;
use PHPUnit\Framework\TestCase;

/**
 * Pokrývá fix #2 (LFI přes logo_path) — security report @andrejtomci.
 * Validace path patternu, allowlist extensions, traversal/null-byte rejection.
 *
 * Filesystem-touching cases (realpath success) jsou v integration testu.
 */
final class SafeLogoPathTest extends TestCase
{
    public function testNullOrEmptyReturnsNull(): void
    {
        self::assertNull(SafeLogoPath::resolve(null, 1));
        self::assertNull(SafeLogoPath::resolve('', 1));
    }

    public function testInvalidSupplierIdReturnsNull(): void
    {
        self::assertNull(SafeLogoPath::resolve('storage/supplier-logos/sup-1.png', 0));
        self::assertNull(SafeLogoPath::resolve('storage/supplier-logos/sup-1.png', -1));
    }

    public function testTraversalRejected(): void
    {
        self::assertNull(SafeLogoPath::resolve('../../../etc/passwd', 1));
        self::assertNull(SafeLogoPath::resolve('storage/supplier-logos/../../cfg.php', 1));
        self::assertNull(SafeLogoPath::resolve('storage/supplier-logos/sup-1/../../../cfg.php', 1));
    }

    public function testNullByteRejected(): void
    {
        self::assertNull(SafeLogoPath::resolve("storage/supplier-logos/sup-1.png\0.php", 1));
    }

    public function testWrongPrefixRejected(): void
    {
        self::assertNull(SafeLogoPath::resolve('cfg.php', 1));
        self::assertNull(SafeLogoPath::resolve('/etc/passwd', 1));
        self::assertNull(SafeLogoPath::resolve('storage/other-dir/sup-1.png', 1));
        self::assertNull(SafeLogoPath::resolve('storage/supplier-logos/admin.png', 1)); // wrong basename
    }

    public function testForeignSupplierIdRejected(): void
    {
        // Path pro supplier 2, ale resolve volaný se supplierId=1 → reject
        self::assertNull(SafeLogoPath::resolve('storage/supplier-logos/sup-2.png', 1));
    }

    public function testInvalidExtensionRejected(): void
    {
        self::assertNull(SafeLogoPath::resolve('storage/supplier-logos/sup-1.php', 1));
        self::assertNull(SafeLogoPath::resolve('storage/supplier-logos/sup-1.exe', 1));
        self::assertNull(SafeLogoPath::resolve('storage/supplier-logos/sup-1', 1)); // no extension
    }

    public function testSourcePathShapeCanBeCheckedWithoutFilesystem(): void
    {
        self::assertTrue(SafeLogoPath::isAllowedSourcePath('sup-7.png', 7));
        self::assertTrue(SafeLogoPath::isAllowedSourcePath(
            'sup-7-brand-11-abcdef123456.svg',
            7,
        ));
        self::assertFalse(SafeLogoPath::isAllowedSourcePath('sup-8.png', 7));
        self::assertFalse(SafeLogoPath::isAllowedSourcePath('nested/sup-7.png', 7));
        self::assertFalse(SafeLogoPath::isAllowedSourcePath('sup-7.php', 7));
    }

    public function testMissingFileReturnsNull(): void
    {
        // Validní path tvar, ale soubor neexistuje → null (realpath returns false)
        self::assertNull(SafeLogoPath::resolve('storage/supplier-logos/sup-99999.png', 99999));
    }
}
