<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup\Company;

use MyInvoice\Infrastructure\Config\RuntimePaths;
use MyInvoice\Repository\InvoiceAttachmentRepository;
use MyInvoice\Service\Backup\Company\CompanyBackupInvoiceAttachmentFilePath;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CompanyBackupInvoiceAttachmentFilePathTest extends TestCase
{
    public function testPreservesUnicodeFilenameAndMatchesRuntimeLayout(): void
    {
        $filename = 'ab12cd34-příloha účet.pdf';
        $path = CompanyBackupInvoiceAttachmentFilePath::sourcePath($filename, 7, 41);
        self::assertSame('sup-7/attachments/41/' . $filename, $path);
        self::assertSame(
            RuntimePaths::storage('invoices') . '/' . $path,
            InvoiceAttachmentRepository::dirFor(7, 41) . '/' . $filename,
        );
        self::assertTrue(CompanyBackupInvoiceAttachmentFilePath::accepts($path, 7));
        self::assertSame(41, CompanyBackupInvoiceAttachmentFilePath::parseInvoiceId($path, 7));
        self::assertSame($filename, CompanyBackupInvoiceAttachmentFilePath::storedFilename($path, 7));

        $target = CompanyBackupInvoiceAttachmentFilePath::restoreTargetPath($path, 7, 81, 901);
        self::assertSame('sup-81/attachments/901/' . $filename, $target);
        self::assertSame($filename, CompanyBackupInvoiceAttachmentFilePath::storedFilename($target, 81));
        self::assertSame(901, CompanyBackupInvoiceAttachmentFilePath::parseInvoiceId($target, 81));
    }

    public function testAcceptsExactly255ByteBasename(): void
    {
        $filename = str_repeat('a', 255);
        $path = CompanyBackupInvoiceAttachmentFilePath::sourcePath($filename, 7, 41);
        self::assertSame($filename, CompanyBackupInvoiceAttachmentFilePath::storedFilename($path, 7));
    }

    /** @param mixed $filename */
    #[DataProvider('invalidFilenames')]
    public function testRejectsUnsafeBasenameWithoutSanitizing(mixed $filename): void
    {
        $this->expectException(\InvalidArgumentException::class);
        CompanyBackupInvoiceAttachmentFilePath::sourcePath($filename, 7, 41);
    }

    /** @return iterable<string,array{mixed}> */
    public static function invalidFilenames(): iterable
    {
        yield 'empty' => [''];
        yield 'null' => [null];
        yield 'slash traversal' => ['../proof.pdf'];
        yield 'nested path' => ['folder/proof.pdf'];
        yield 'Windows separator' => ['folder\\proof.pdf'];
        yield 'drive/ADS' => ['C:proof.pdf'];
        yield 'colon ADS' => ['proof.pdf:stream'];
        yield 'reserved device' => ['CON.pdf'];
        yield 'reserved device bare' => ['nul'];
        yield 'Windows console input' => ['CONIN$.txt'];
        yield 'Windows console output' => ['conout$.txt'];
        yield 'Windows superscript device' => ['COM¹.pdf'];
        yield 'trailing dot' => ['proof.pdf.'];
        yield 'trailing space' => ['proof.pdf '];
        yield 'wildcard' => ['proof?.pdf'];
        yield 'pipe' => ['proof|copy.pdf'];
        yield 'control' => ["proof\n.pdf"];
        yield 'invalid UTF-8' => ["proof\xff.pdf"];
        yield 'basename 256 bytes' => [str_repeat('a', 256)];
        yield 'full path overflow' => [str_repeat('a', 1_024)];
    }

    /** @param string $path */
    #[DataProvider('invalidPaths')]
    public function testRejectsWrongTenantAndNoncanonicalPaths(string $path): void
    {
        self::assertFalse(CompanyBackupInvoiceAttachmentFilePath::accepts($path, 7));
        $this->expectException(\InvalidArgumentException::class);
        CompanyBackupInvoiceAttachmentFilePath::storedFilename($path, 7);
    }

    /** @return iterable<string,array{string}> */
    public static function invalidPaths(): iterable
    {
        yield 'other tenant' => ['sup-8/attachments/41/proof.pdf'];
        yield 'prefix confusion' => ['sup-70/attachments/41/proof.pdf'];
        yield 'leading-zero supplier' => ['sup-07/attachments/41/proof.pdf'];
        yield 'leading-zero invoice' => ['sup-7/attachments/041/proof.pdf'];
        yield 'zero invoice' => ['sup-7/attachments/0/proof.pdf'];
        yield 'overflow invoice' => ['sup-7/attachments/9223372036854775808/proof.pdf'];
        yield 'wrong structure' => ['sup-7/_archive/41/proof.pdf'];
        yield 'extra segment' => ['sup-7/attachments/41/nested/proof.pdf'];
        yield 'Windows drive segment' => ['sup-7/attachments/41/C:proof.pdf'];
        yield 'Windows device' => ['sup-7/attachments/41/LPT1.pdf'];
        yield 'absolute' => ['/sup-7/attachments/41/proof.pdf'];
    }

    public function testRequiresPositiveSourceAndTargetIds(): void
    {
        foreach ([[0, 41], [7, 0], [-1, 41]] as [$supplierId, $invoiceId]) {
            try {
                CompanyBackupInvoiceAttachmentFilePath::sourcePath('proof.pdf', $supplierId, $invoiceId);
                self::fail('Nekladné ID nesmí vytvořit cestu.');
            } catch (\InvalidArgumentException $e) {
                self::assertSame('Cesta přílohy faktury není platná.', $e->getMessage());
            }
        }
        $source = CompanyBackupInvoiceAttachmentFilePath::sourcePath('proof.pdf', 7, 41);
        foreach ([[0, 81, 901], [7, 0, 901], [7, 81, 0]] as $ids) {
            try {
                CompanyBackupInvoiceAttachmentFilePath::restoreTargetPath($source, ...$ids);
                self::fail('Nekladné cílové ID nesmí obnovit cestu.');
            } catch (\InvalidArgumentException $e) {
                self::assertSame('Cesta přílohy faktury není platná.', $e->getMessage());
            }
        }
    }
}
