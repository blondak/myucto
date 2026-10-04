<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Import;

use Mpdf\Mpdf;
use Mpdf\Output\Destination;
use MyInvoice\Infrastructure\Config\RuntimePaths;
use MyInvoice\Service\Import\PdfPageRasterizer;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Bez Imagicku i pdftoppm musí rasterizace vrátit prázdný seznam (klient pak jede
 * jen s textem) — to ověřuje testWithoutAnyBackendReturnsEmpty. Testy konkrétního
 * backendu běží na skutečném vícestránkovém PDF; když backend v prostředí není,
 * hlásí skip, ne zelenou nad prázdným výsledkem.
 */
final class PdfPageRasterizerTest extends TestCase
{
    private static function pdf(int $pages): string
    {
        $mpdf = new Mpdf(['tempDir' => sys_get_temp_dir(), 'default_font' => 'dejavusans']);
        for ($i = 1; $i <= $pages; $i++) {
            if ($i > 1) {
                $mpdf->AddPage();
            }
            $mpdf->WriteHTML('<h1>Testovací faktura</h1><p>Strana ' . $i . '</p>');
        }
        return (string) $mpdf->Output('', Destination::STRING_RETURN);
    }

    /** @param list<string> $pages */
    private static function assertPngPages(array $pages, int $expected): void
    {
        self::assertCount($expected, $pages);
        foreach ($pages as $png) {
            self::assertStringStartsWith("\x89PNG", $png);
            $size = getimagesizefromstring($png);
            self::assertIsArray($size);
            self::assertLessThanOrEqual(PdfPageRasterizer::MAX_EDGE, max($size[0], $size[1]));
        }
    }

    public function testWithoutAnyBackendReturnsEmpty(): void
    {
        $r = new PdfPageRasterizer(new NullLogger(), useImagick: false, pdftoppmBinary: '');
        self::assertSame([], $r->rasterize(self::pdf(1), 6));
    }

    public function testNonPdfReturnsEmpty(): void
    {
        self::assertSame([], (new PdfPageRasterizer(new NullLogger()))->rasterize('není pdf', 6));
    }

    public function testPdftoppmBackendRespectsPageLimit(): void
    {
        $bin = trim((string) @shell_exec('command -v pdftoppm 2>/dev/null'));
        if ($bin === '') {
            self::markTestSkipped('pdftoppm (Poppler) není v prostředí k dispozici.');
        }
        $r = new PdfPageRasterizer(new NullLogger(), useImagick: false, pdftoppmBinary: $bin);
        self::assertPngPages($r->rasterize(self::pdf(8), 6), 6);
    }

    public function testImagickBackendRespectsPageLimit(): void
    {
        if (!class_exists(\Imagick::class)) {
            self::markTestSkipped('PHP rozšíření Imagick není v prostředí k dispozici.');
        }
        $r = new PdfPageRasterizer(new NullLogger(), useImagick: true, pdftoppmBinary: '');
        self::assertPngPages($r->rasterize(self::pdf(3), 2), 2);
    }

    /** PHP_OS_FAMILY 'Darwin' obsahuje „win" — macOS nesmí dostat Windows `where`. */
    public function testBinaryLookupCommandPerOsFamily(): void
    {
        $cmd = new \ReflectionMethod(PdfPageRasterizer::class, 'lookupCommand');
        self::assertStringStartsWith('where ', (string) $cmd->invoke(null, 'pdftoppm', 'Windows'));
        foreach (['Linux', 'Darwin', 'BSD'] as $os) {
            self::assertStringStartsWith('command -v ', (string) $cmd->invoke(null, 'pdftoppm', $os), $os);
        }
    }

    public function testHangingPdftoppmIsTerminatedAfterTimeout(): void
    {
        $dir = sys_get_temp_dir() . '/rasterizer-fake-' . bin2hex(random_bytes(4));
        mkdir($dir);
        $isWin = PHP_OS_FAMILY === 'Windows';
        $fake = $dir . ($isWin ? '/pdftoppm.cmd' : '/pdftoppm');
        file_put_contents($fake, $isWin ? "@echo off\r\nping -n 30 127.0.0.1 >NUL\r\n" : "#!/bin/sh\nsleep 30\n");
        chmod($fake, 0755);

        $cacheDir = RuntimePaths::storage('cache/ollama');
        $before = is_dir($cacheDir) ? scandir($cacheDir) : [];
        try {
            $r = new PdfPageRasterizer(new NullLogger(), useImagick: false, pdftoppmBinary: $fake, timeoutSeconds: 1);
            $t = microtime(true);
            $pages = $r->rasterize(self::pdf(1), 6);
            $elapsed = microtime(true) - $t;
        } finally {
            @unlink($fake);
            @rmdir($dir);
        }
        self::assertSame([], $pages);
        self::assertLessThan(10.0, $elapsed);
        self::assertSame($before, is_dir($cacheDir) ? scandir($cacheDir) : [], 'v cache nesmí zůstat dočasné soubory');
    }

    /**
     * Ghostscript (delegát Imagicku pro PDF) interpretuje PostScript a opakovaně měl díry,
     * kterými podstrčené PDF spustilo kód. Když je k dispozici pdftoppm, musí jít nahraný
     * doklad přes něj a Imagick zůstane jen zálohou.
     */
    public function testPdftoppmIsPreferredOverImagick(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('Falešný pdftoppm je shellový skript — na Windows se neověřuje.');
        }
        if (!class_exists(\Imagick::class)) {
            self::markTestSkipped('Bez Imagicku není co upřednostňovat.');
        }
        $dir = sys_get_temp_dir() . '/rasterizer-fake-' . bin2hex(random_bytes(4));
        mkdir($dir);
        $marker = $dir . '/marker.png';
        $img = imagecreatetruecolor(3, 3);
        imagepng($img, $marker);
        $fake = $dir . '/pdftoppm';
        // Poslední argument je prefix výstupu → vyrobí „<prefix>-1.png" se známým obsahem.
        file_put_contents($fake, "#!/bin/sh\nfor last; do :; done\ncp " . escapeshellarg($marker) . " \"\$last-1.png\"\n");
        chmod($fake, 0755);
        try {
            $pages = (new PdfPageRasterizer(new NullLogger(), useImagick: true, pdftoppmBinary: $fake))->rasterize(self::pdf(1), 6);
            self::assertSame([(string) file_get_contents($marker)], $pages, 'stránky musí pocházet z pdftoppm, ne z Imagicku');
        } finally {
            @unlink($fake);
            @unlink($marker);
            @rmdir($dir);
        }
    }
}
