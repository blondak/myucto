<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup\Company;

use MyInvoice\Service\Backup\Company\CompanyBackupArchiveException;
use MyInvoice\Service\Backup\Company\CompanyBackupArchivePathSet;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CompanyBackupArchivePathSetTest extends TestCase
{
    public function testAcceptsExplicitDirectoryAndItsChildren(): void
    {
        $paths = new CompanyBackupArchivePathSet();

        self::assertSame('data', $paths->add('data/', true));
        self::assertSame(
            'data/table-invoices.jsonl',
            $paths->add('data/table-invoices.jsonl', false),
        );
        self::assertSame(
            ['data', 'data/table-invoices.jsonl'],
            $paths->paths(),
        );
    }

    #[DataProvider('conflictingPaths')]
    public function testRejectsCaseCollisionsAndFileParentConflicts(
        string $first,
        bool $firstDirectory,
        string $second,
        bool $secondDirectory,
        string $errorCode,
    ): void {
        $paths = new CompanyBackupArchivePathSet();
        $paths->add($first, $firstDirectory);

        try {
            $paths->add($second, $secondDirectory);
            self::fail('Kolize cest archivu nesmí projít.');
        } catch (CompanyBackupArchiveException $e) {
            self::assertSame($errorCode, $e->errorCode);
        }
    }

    /** @return iterable<string,array{string,bool,string,bool,string}> */
    public static function conflictingPaths(): iterable
    {
        yield 'same file' => [
            'manifest.json', false, 'manifest.json', false, 'entry_path_duplicate',
        ];
        yield 'case insensitive duplicate' => [
            'manifest.json', false, 'Manifest.json', false, 'entry_path_duplicate',
        ];
        yield 'file already parent' => [
            'data', false, 'data/table.jsonl', false, 'entry_path_conflict',
        ];
        yield 'new file becomes parent' => [
            'data/table.jsonl', false, 'data', false, 'entry_path_conflict',
        ];
        yield 'case insensitive parent' => [
            'Data', false, 'data/table.jsonl', false, 'entry_path_conflict',
        ];
        yield 'file collides with explicit directory' => [
            'data/', true, 'DATA', false, 'entry_path_duplicate',
        ];
    }
}
