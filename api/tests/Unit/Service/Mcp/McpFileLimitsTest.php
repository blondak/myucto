<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Mcp;

use MyInvoice\Service\Mcp\McpFileLimits;
use PHPUnit\Framework\TestCase;

final class McpFileLimitsTest extends TestCase
{
    public function testEnvelopeCarriesLargestBodyInBase64(): void
    {
        $encodedBody = (int) ceil(McpFileLimits::MAX_BODY_BYTES / 3) * 4;
        self::assertGreaterThan($encodedBody + 512 * 1024, McpFileLimits::MAX_ENVELOPE_BYTES);
        self::assertGreaterThan(McpFileLimits::MAX_ENVELOPE_BYTES, McpFileLimits::MAX_LINE_BYTES);
    }

    public function testDecodesStrictBase64Only(): void
    {
        self::assertSame("%PDF-\x00\xFF", McpFileLimits::decodeBase64(base64_encode("%PDF-\x00\xFF")));
        foreach (['', 'abc', 'ab*=', 'a===', 'ab=c', "YWJj\nZA==", 'YW Jj'] as $invalid) {
            try {
                McpFileLimits::decodeBase64($invalid);
                self::fail('"' . $invalid . '" nemělo projít.');
            } catch (\InvalidArgumentException $e) {
                self::assertSame(400, $e->getCode(), $invalid);
            }
        }
    }

    public function testSizeIsCheckedBeforeDecoding(): void
    {
        $this->expectExceptionCode(413);
        McpFileLimits::assertBase64(str_repeat('A', 4 * 1024), 1024);
    }

    public function testSanitizeFilenameDropsPathsAndControlCharacters(): void
    {
        self::assertSame('faktura.pdf', McpFileLimits::sanitizeFilename('..\\..\\a/faktura.pdf'));
        self::assertSame('a_b_.pdf', McpFileLimits::sanitizeFilename("a\"b\r.pdf"));
        self::assertSame('Účtenka č. 5.jpg', McpFileLimits::sanitizeFilename('Účtenka č. 5.jpg'));
        self::assertNull(McpFileLimits::sanitizeFilename('../'));
        self::assertNull(McpFileLimits::sanitizeFilename('...'));
        self::assertNull(McpFileLimits::sanitizeFilename("\xFF\xFE.pdf"));
        self::assertSame(194, strlen((string) McpFileLimits::sanitizeFilename(str_repeat('a', 300) . '.pdf')));
    }

    public function testSanitizeFilenameDefusesWindowsDeviceNames(): void
    {
        foreach (['CON', 'con.pdf', 'PRN.txt', 'Aux.jpg', 'NUL', 'COM1.pdf', 'lpt9.csv'] as $name) {
            self::assertSame('_' . $name, McpFileLimits::sanitizeFilename($name), $name);
        }
        foreach (['CONTRACT.pdf', 'console.txt', 'COM10.pdf', 'nul_hodnota.pdf'] as $name) {
            self::assertSame($name, McpFileLimits::sanitizeFilename($name), $name);
        }
    }

    public function testUploadTypeIsBoundToExtension(): void
    {
        self::assertTrue(McpFileLimits::uploadTypeMatchesFilename('a.PDF', 'application/pdf'));
        self::assertTrue(McpFileLimits::uploadTypeMatchesFilename('a.csv', 'text/plain'));
        self::assertFalse(McpFileLimits::uploadTypeMatchesFilename('a.html', 'text/plain'));
        self::assertFalse(McpFileLimits::uploadTypeMatchesFilename('a', 'application/pdf'));
        foreach (McpFileLimits::UPLOAD_EXTENSION_TYPES as $extension => $types) {
            foreach ($types as $type) {
                self::assertContains($type, McpFileLimits::ALLOWED_UPLOAD_TYPES, $extension);
            }
        }
    }
}
