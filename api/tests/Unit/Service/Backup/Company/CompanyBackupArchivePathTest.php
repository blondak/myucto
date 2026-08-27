<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup\Company;

use MyInvoice\Service\Backup\Company\CompanyBackupArchiveException;
use MyInvoice\Service\Backup\Company\CompanyBackupArchivePath;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CompanyBackupArchivePathTest extends TestCase
{
    #[DataProvider('safePaths')]
    public function testAcceptsPortableContractPaths(string $path, bool $directory): void
    {
        self::assertSame(
            rtrim($path, '/'),
            CompanyBackupArchivePath::normalize($path, $directory),
        );
    }

    /** @return iterable<string,array{string,bool}> */
    public static function safePaths(): iterable
    {
        yield 'manifest' => ['manifest.json', false];
        yield 'checksums' => ['CHECKSUMS.txt', false];
        yield 'stable data object' => ['data/table-invoices.jsonl', false];
        yield 'explicit directory' => ['readable/doklady/', true];
    }

    #[DataProvider('unsafePaths')]
    public function testRejectsPathsThatAreUnsafeOnLinuxOrWindows(string $path, bool $directory): void
    {
        try {
            CompanyBackupArchivePath::normalize($path, $directory);
            self::fail('Nebezpečná cesta nesmí projít validací.');
        } catch (CompanyBackupArchiveException $e) {
            self::assertSame('entry_path_unsafe', $e->errorCode);
            self::assertSame($path, $e->entry);
        }
    }

    /** @return iterable<string,array{string,bool}> */
    public static function unsafePaths(): iterable
    {
        yield 'absolute unix' => ['/manifest.json', false];
        yield 'drive absolute' => ['C:/manifest.json', false];
        yield 'drive relative' => ['C:manifest.json', false];
        yield 'backslash' => ['data\\invoice.jsonl', false];
        yield 'parent traversal' => ['data/../manifest.json', false];
        yield 'current segment' => ['data/./invoice.jsonl', false];
        yield 'empty segment' => ['data//invoice.jsonl', false];
        yield 'file trailing slash' => ['manifest.json/', false];
        yield 'directory without slash' => ['data', true];
        yield 'device basename' => ['files/CON.txt', false];
        yield 'device with extension' => ['files/lpt1.pdf', false];
        yield 'trailing dot' => ['files/invoice.', false];
        yield 'trailing space' => ["files/invoice ", false];
        yield 'control byte' => ["files/invoice\n.pdf", false];
        yield 'unicode lookalike' => ['files/účet.pdf', false];
        yield 'colon' => ['data/table:invoices.jsonl', false];
        yield 'empty' => ['', false];
    }
}
