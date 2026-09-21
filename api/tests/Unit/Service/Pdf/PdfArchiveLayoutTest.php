<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Pdf;

use MyInvoice\Service\Pdf\PdfArchiveLayout;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PdfArchiveLayoutTest extends TestCase
{
    #[DataProvider('filenames')]
    public function testKeepsRuntimeMonthExtractionSemantics(string $filename, string $expected): void
    {
        self::assertSame($expected, PdfArchiveLayout::monthSubdirectory($filename));
    }

    /** @return iterable<string,array{string,string}> */
    public static function filenames(): iterable
    {
        yield 'archive filename' => ['20260921-142530-a1b2c3d4-invoice.pdf', '2026-09'];
        yield 'legacy filename' => ['invoice.pdf', ''];
        yield 'missing separator' => ['20260921invoice.pdf', ''];
        yield 'regex compatibility permits non-calendar month' => ['20261301-proof.pdf', '2026-13'];
    }
}
