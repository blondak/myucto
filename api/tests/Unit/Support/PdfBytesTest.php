<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Support;

use MyInvoice\Support\PdfBytes;
use PHPUnit\Framework\TestCase;

final class PdfBytesTest extends TestCase
{
    public function testHeaderAtStartIsUntouched(): void
    {
        self::assertSame(0, PdfBytes::headerOffset("%PDF-1.7\n"));
        self::assertSame("%PDF-1.7\n", PdfBytes::normalize("%PDF-1.7\n"));
    }

    public function testLeadingBlankLinesAndBomAreStripped(): void
    {
        self::assertSame(2, PdfBytes::headerOffset("\n\n%PDF-1.7\n"));
        self::assertSame("%PDF-1.7\nbody", PdfBytes::normalize("\n\n%PDF-1.7\nbody"));
        self::assertSame("%PDF-1.4\n", PdfBytes::normalize("\xEF\xBB\xBF%PDF-1.4\n"));
    }

    public function testHeaderBeyondTheWindowIsNotPdf(): void
    {
        $bytes = str_repeat(' ', 1024) . '%PDF-1.7';
        self::assertFalse(PdfBytes::isPdf($bytes));
        self::assertSame($bytes, PdfBytes::normalize($bytes));
    }

    public function testNonPdfIsReturnedUnchanged(): void
    {
        self::assertFalse(PdfBytes::isPdf("\xFF\xD8\xFFjpeg"));
        self::assertSame("\xFF\xD8\xFFjpeg", PdfBytes::normalize("\xFF\xD8\xFFjpeg"));
        self::assertSame('', PdfBytes::normalize(''));
    }
}
