<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Infrastructure\Config;

use PHPUnit\Framework\TestCase;

/**
 * Vision modely v Ollamě dostávají stránky PDF jako PNG (PdfPageRasterizer). Imagick
 * umí PDF jen s Ghostscript delegatem, který v alpine image není zaručený — proto oba
 * image instalují `pdftoppm` z Poppleru. Bez něj by se na skeny posílal jen (prázdný) text.
 */
final class DockerfilePdfRasterizerTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function dockerfileProvider(): array
    {
        return [
            'Dockerfile (Debian/Apache)' => ['/Dockerfile'],
            'Dockerfile.alpine (nginx)'  => ['/Dockerfile.alpine'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('dockerfileProvider')]
    public function testRuntimeImageInstallsPdftoppm(string $relativePath): void
    {
        $contents = file_get_contents(dirname(__DIR__, 5) . $relativePath);
        self::assertIsString($contents);
        // Jen kód, ne komentáře, a jen instalační příkaz balíčkovače.
        $codeOnly = implode("\n", array_filter(
            preg_split('/\R/', $contents) ?: [],
            static fn (string $line): bool => preg_match('/^\s*#/', $line) !== 1,
        ));
        self::assertMatchesRegularExpression(
            '/(apk add|apt-get install)[^\n&]*\bpoppler-utils\b/',
            $codeOnly,
            "{$relativePath} musí instalovat poppler-utils (pdftoppm) pro rasterizaci PDF.",
        );
    }
}
