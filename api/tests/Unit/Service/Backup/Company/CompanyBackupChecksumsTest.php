<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup\Company;

use MyInvoice\Service\Backup\Company\CompanyBackupArchiveException;
use MyInvoice\Service\Backup\Company\CompanyBackupChecksums;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CompanyBackupChecksumsTest extends TestCase
{
    public function testParsesCanonicalSortedChecksumInventory(): void
    {
        $manifestHash = str_repeat('a', 64);
        $dataHash = str_repeat('b', 64);
        $content = $dataHash . "  data/table-invoices.jsonl\n"
            . $manifestHash . "  manifest.json\n";

        $checksums = CompanyBackupChecksums::parse($content);

        self::assertSame(
            ['data/table-invoices.jsonl', 'manifest.json'],
            $checksums->paths(),
        );
        self::assertSame($manifestHash, $checksums->hashFor('manifest.json'));
        self::assertSame($dataHash, $checksums->hashFor('data/table-invoices.jsonl'));
        self::assertNull($checksums->hashFor('files/missing.pdf'));
        self::assertSame(
            $dataHash . "  data/table-invoices.jsonl\n"
                . $manifestHash . "  manifest.json\n",
            $checksums->canonicalText(),
        );
    }

    #[DataProvider('invalidContents')]
    public function testRejectsAmbiguousOrUnsafeInventories(string $content, string $errorCode): void
    {
        try {
            CompanyBackupChecksums::parse($content);
            self::fail('Neplatný checksum inventář nesmí projít.');
        } catch (CompanyBackupArchiveException $e) {
            self::assertSame($errorCode, $e->errorCode);
        }
    }

    /** @return iterable<string,array{string,string}> */
    public static function invalidContents(): iterable
    {
        $a = str_repeat('a', 64);
        $b = str_repeat('b', 64);

        yield 'empty' => ['', 'checksums_invalid'];
        yield 'missing final newline' => [$a . '  manifest.json', 'checksums_not_canonical'];
        yield 'uppercase hash' => [str_repeat('A', 64) . "  manifest.json\n", 'checksums_invalid'];
        yield 'one separator space' => [$a . " manifest.json\n", 'checksums_invalid'];
        yield 'unsorted' => [
            $a . "  manifest.json\n" . $b . "  data/table.jsonl\n",
            'checksums_not_canonical',
        ];
        yield 'duplicate path' => [
            $a . "  manifest.json\n" . $b . "  manifest.json\n",
            'checksums_duplicate',
        ];
        yield 'case collision' => [
            $a . "  Manifest.json\n" . $b . "  manifest.json\n",
            'checksums_duplicate',
        ];
        yield 'self checksum' => [$a . "  CHECKSUMS.txt\n", 'checksums_invalid'];
        yield 'traversal' => [$a . "  ../manifest.json\n", 'entry_path_unsafe'];
    }
}
