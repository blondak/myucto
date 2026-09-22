<?php

declare(strict_types=1);
namespace MyInvoice\Tests\Unit\Service\Migration\StereoNx;

use MyInvoice\Service\Migration\StereoNx\StereoNxException;
use MyInvoice\Service\Migration\StereoNx\StereoNxManifest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class StereoNxManifestTest extends TestCase
{
    public function testReadsCp1250AndKeepsCompanyIndexes(): void
    {
        $bytes = iconv('UTF-8', 'Windows-1250', "26.1.00\r\nZáloha\r\nSeznam firem|2|0\r\n0|Testovací firma|test_a\r\n7|Druhá firma|test_b\r\n");
        $rows = StereoNxManifest::parse($bytes);
        self::assertSame([0, 7], array_column($rows, 'index'));
        self::assertSame('Firma_7/', $rows[1]['prefix']);
        self::assertSame('Druhá firma', $rows[1]['label']);
        self::assertSame('test_b', $rows[1]['source_key']);
    }

    public function testUtf8Bom(): void
    {
        self::assertCount(1, StereoNxManifest::parse("\xEF\xBB\xBF26.1.00\nZáloha\nFirmy|1|0\n0|Firma|test\n"));
    }

    #[DataProvider('invalidManifests')]
    public function testRejectsAmbiguousOrUnsupportedManifest(string $text): void
    {
        $this->expectException(StereoNxException::class);
        StereoNxManifest::parse($text);
    }

    public static function invalidManifests(): array
    {
        $header = "26.1.00\nZáloha\nFirmy|1|0\n";
        return [[''], [$header], [$header . '0|Firma'], [$header . '../0|Firma|test'],
            [$header . "0|Firma|test\n0|Jiná|test2"], [$header . '00|Firma|test'],
            [$header . '0||test'], [$header . "0|Firma|test\0"]];
    }
}
