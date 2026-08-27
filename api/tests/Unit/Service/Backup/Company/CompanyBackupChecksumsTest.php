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

    public function testBuildsCanonicalInventoryFromWriterMetadata(): void
    {
        $checksums = CompanyBackupChecksums::fromEntryHashes([
            'manifest.json' => ['sha256' => str_repeat('a', 64), 'size' => 120],
            'data/table.jsonl' => ['sha256' => str_repeat('b', 64), 'size' => 8],
        ]);

        self::assertSame(
            str_repeat('b', 64) . "  data/table.jsonl\n"
                . str_repeat('a', 64) . "  manifest.json\n",
            $checksums->canonicalText(),
        );
    }

    /** @param array<mixed> $entries */
    #[DataProvider('invalidWriterEntries')]
    public function testRejectsMalformedWriterMetadata(array $entries): void
    {
        $this->expectException(CompanyBackupArchiveException::class);
        $this->expectExceptionMessage('checksums_invalid');

        CompanyBackupChecksums::fromEntryHashes($entries);
    }

    /** @return iterable<string,array{array<mixed>}> */
    public static function invalidWriterEntries(): iterable
    {
        $valid = ['sha256' => str_repeat('a', 64), 'size' => 1];

        yield 'empty' => [[]];
        yield 'numeric path' => [[0 => $valid]];
        yield 'metadata is not object' => [['manifest.json' => null]];
        yield 'missing hash' => [['manifest.json' => ['size' => 1]]];
        yield 'negative size' => [[
            'manifest.json' => ['sha256' => str_repeat('a', 64), 'size' => -1],
        ]];
        yield 'self checksum' => [['CHECKSUMS.txt' => $valid]];
        yield 'case collision' => [[
            'Manifest.json' => $valid,
            'manifest.json' => ['sha256' => str_repeat('b', 64), 'size' => 1],
        ]];
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
