<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup\Company;

use MyInvoice\Service\Backup\Company\CompanyBackupFilePathPolicy;
use PHPUnit\Framework\TestCase;

final class CompanyBackupFilePathPolicyTest extends TestCase
{
    public function testAttachmentPolicyRequiresInvoiceIdentityAndPreservesBasename(): void
    {
        $policy = CompanyBackupFilePathPolicy::SupplierInvoiceAttachment;
        self::assertSame('supplier_invoice_attachment', $policy->value);
        $path = $policy->sourcePath('ab12cd34-protokol.pdf', 7, 41);
        self::assertSame('sup-7/attachments/41/ab12cd34-protokol.pdf', $path);
        self::assertTrue($policy->accepts($path, 7));
        self::assertFalse($policy->accepts($path, 8));
        self::assertSame('ab12cd34-protokol.pdf', $policy->storedRelativePath($path, 7));
        self::assertNull($policy->expectedContentSha256($path, 7));
        self::assertSame(
            'sup-81/attachments/901/ab12cd34-protokol.pdf',
            $policy->restoreTargetPath($path, 7, 81, 901),
        );
        $this->assertInvalid(static fn (): string => $policy->sourcePath('ab12cd34-protokol.pdf', 7));
        $this->assertInvalid(static fn (): string => $policy->restoreTargetPath($path, 7, 81));
        $this->assertInvalid(static fn (): string => $policy->sourcePath('../proof.pdf', 7, 41));
    }

    public function testExistingPoliciesKeepPriorPathBehavior(): void
    {
        $relative = CompanyBackupFilePathPolicy::Relative;
        self::assertSame('folder/proof.pdf', $relative->sourcePath('folder/proof.pdf', 7));
        self::assertSame('folder/proof.pdf', $relative->restoreTargetPath('folder/proof.pdf', 7, 81));
        $hash = str_repeat('a', 64);
        $content = CompanyBackupFilePathPolicy::SupplierContentHash;
        self::assertSame('sup-7/aa/' . $hash, $content->sourcePath($hash, 7));
        self::assertSame('sup-81/aa/' . $hash,
            $content->restoreTargetPath('sup-7/aa/' . $hash, 7, 81));
        self::assertSame($hash, $content->expectedContentSha256('sup-7/aa/' . $hash, 7));
    }

    public function testInvoicePdfPolicyUsesCanonicalMonthlyTarget(): void
    {
        $policy = CompanyBackupFilePathPolicy::SupplierInvoicePdf;
        $filename = '20260921-142530-a1b2c3d4-invoice.pdf';
        $source = 'sup-7/_archive/2026-09/' . $filename;

        self::assertSame('supplier_invoice_pdf', $policy->value);
        self::assertSame($source, $policy->sourcePath($filename, 7));
        self::assertTrue($policy->accepts($source, 7));
        self::assertFalse($policy->accepts($source, 8));
        self::assertSame($filename, $policy->storedRelativePath($source, 7));
        self::assertNull($policy->expectedContentSha256($source, 7));
        self::assertSame(
            'sup-81/_archive/2026-09/' . $filename,
            $policy->restoreTargetPath('sup-7/_archive/' . $filename, 7, 81),
        );
    }

    /** @param callable():string $action */
    private function assertInvalid(callable $action): void
    {
        try {
            $action();
            self::fail('Neplatná cesta nesmí projít.');
        } catch (\InvalidArgumentException) {
            // API musí selhat bez implicitního zdrojového invoice ID.
        }
    }
}
