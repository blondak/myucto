<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Intrastat;

use MyInvoice\Service\Intrastat\InstatEvoCsvExporter;
use PHPUnit\Framework\TestCase;

final class InstatEvoCsvExporterTest extends TestCase
{
    public function testExportsExactlyTwentyUtf8ColumnsWithoutHeader(): void
    {
        $row = ['9', '2026', 'CZ12345678', 'D', 'SK2020123456', 'SK', '', 'DE', '11', '3', 'K', 'ST',
            '27101981', '50', 'Čerpadlo; průmyslové', '3', '10', '1250', 'poznámka', ''];

        $csv = (new InstatEvoCsvExporter())->export([$row]);

        self::assertTrue(mb_check_encoding($csv, 'UTF-8'));
        self::assertStringStartsWith('9;2026;', $csv);
        $stream = fopen('php://temp', 'w+b');
        fwrite($stream, $csv);
        rewind($stream);
        $parsed = fgetcsv($stream, separator: ';', enclosure: '"', escape: '');
        fclose($stream);
        self::assertCount(20, $parsed);
        self::assertSame($row, $parsed);
    }

    public function testNeutralizesSpreadsheetFormulaPayloads(): void
    {
        $row = array_fill(0, 20, '');
        $row[14] = '=HYPERLINK("https://example.invalid")';
        $row[18] = " \t+SUM(1;1)";

        $csv = (new InstatEvoCsvExporter())->export([$row]);
        $stream = fopen('php://temp', 'w+b');
        fwrite($stream, $csv);
        rewind($stream);
        $parsed = fgetcsv($stream, separator: ';', enclosure: '"', escape: '');
        fclose($stream);

        self::assertSame("'=HYPERLINK(\"https://example.invalid\")", $parsed[14]);
        self::assertSame("' \t+SUM(1;1)", $parsed[18]);
    }

    public function testRejectsRowsWithWrongColumnCount(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new InstatEvoCsvExporter())->export([array_fill(0, 19, '')]);
    }
}
