<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Pdf;

use Mpdf\Mpdf;
use Mpdf\MpdfException;
use MyInvoice\Service\Pdf\ChunkedHtmlWriter;
use MyInvoice\Service\Pdf\MpdfFontConfig;
use PHPUnit\Framework\TestCase;

/**
 * Regresní test na produkční pád exportu účetního deníku velké firmy
 * (~25 tis. řádků deníku/rok): jedno volání Mpdf::WriteHTML() na celý
 * dokument narazí na `pcre.backtrack_limit` a shodí export s chybou
 * "HTML code size is larger than pcre.backtrack_limit". Test to ověřuje
 * rychle — místo tisíců skutečných řádků dočasně STÁHNE `pcre.backtrack_limit`
 * na malou hodnotu, takže i několik zápisů spolehlivě reprodukuje stejnou
 * chybu, a ověří, že {@see ChunkedHtmlWriter} (WriteHTML po dávkách řádků,
 * CSS zvlášť přes mode HEADER_CSS) stejné HTML zvládne bez chyby.
 */
final class ChunkedHtmlWriterTest extends TestCase
{
    private ?string $originalBacktrackLimit = null;

    protected function tearDown(): void
    {
        if ($this->originalBacktrackLimit !== null) {
            ini_set('pcre.backtrack_limit', $this->originalBacktrackLimit);
        }
    }

    public function testSingleWriteHtmlCallFailsOnLowBacktrackLimitButChunkedWriteSucceeds(): void
    {
        $html = self::syntheticJournalHtml(8);

        $this->originalBacktrackLimit = (string) ini_get('pcre.backtrack_limit');
        ini_set('pcre.backtrack_limit', '2000');

        $failingMpdf = self::mpdf();
        $threw = false;
        try {
            $failingMpdf->WriteHTML($html);
        } catch (MpdfException $e) {
            self::assertStringContainsString('pcre.backtrack_limit', $e->getMessage());
            $threw = true;
        }
        self::assertTrue($threw, 'Test musí nejdřív ověřit, že syntetické HTML za sníženého pcre.backtrack_limit skutečně reprodukuje původní pád — jinak test nic neříká.');

        $okMpdf = self::mpdf();
        ChunkedHtmlWriter::write($okMpdf, $html, rowsPerChunk: 3);
        $pdf = $okMpdf->Output('', 'S');

        self::assertStringStartsWith('%PDF', $pdf);
    }


    /**
     * mPDF drží otevřenou <table> v paměti celou (deník 13 tis. zápisů = 3,7 GB), proto
     * se dlouhá tabulka zapisuje jako řada uzavřených tabulek a hlavička sloupců přejde
     * do hlavičky stránky. Zápis deníku se svými řádky se nerozdělí.
     */
    public function testLongTableIsWrittenAsClosedPiecesWithColumnHeaderAsPageHeader(): void
    {
        $mpdf = self::recordingMpdf();

        ChunkedHtmlWriter::write($mpdf, self::syntheticJournalHtml(30), 10, '/^<tr class="entry-head"/');

        self::assertGreaterThan(3, count($mpdf->bodyWrites));
        foreach (array_slice($mpdf->bodyWrites, 0, -1) as $i => $write) {
            self::assertSame(
                substr_count($write, '<table'),
                substr_count($write, '</table>'),
                "Kus #{$i} musí tabulku uzavřít, jinak ji mPDF drží v paměti dál."
            );
        }
        foreach (array_slice($mpdf->bodyWrites, 1) as $write) {
            self::assertStringNotContainsString('<thead>', $write, 'Hlavičku sloupců nese hlavička stránky, ne každý kus.');
            if (str_contains($write, 'class="entry-')) {
                self::assertMatchesRegularExpression('/^<table class="entries"><tbody><tr class="entry-head"/', $write, 'Kus začíná zápisem, ne jeho řádkem.');
            }
        }
        self::assertCount(2, $mpdf->headers);
        self::assertStringContainsString('<thead><tr><th>Datum</th>', $mpdf->headers[0]);
        self::assertSame('', $mpdf->headers[1], 'Za koncem tabulky se hlavička stránky zase ruší.');
        self::assertStringContainsString('Počet zápisů: 30', end($mpdf->bodyWrites));
        self::assertSame(30, substr_count(implode('', $mpdf->bodyWrites), 'class="entry-head"'));
    }

    /** Součtový řádek v <tfoot> zůstane v posledním kusu, i když na něj připadne hranice dávky. */
    public function testTableFooterStaysInLastPiece(): void
    {
        $rows = '';
        for ($i = 1; $i <= 10; $i++) {
            $rows .= '<tr><td class="c1">' . $i . '</td><td class="c2">řádek</td></tr>';
        }
        $html = '<html><head><style>.c1{width:30%;} .c2{width:70%;}</style></head><body>'
            . '<table class="book"><thead><tr><th class="c1">A</th><th class="c2">B</th></tr></thead>'
            . '<tbody>' . $rows . '</tbody><tfoot><tr><td>CELKEM</td><td>10</td></tr></tfoot></table>'
            . '<p>konec</p></body></html>';
        $mpdf = self::recordingMpdf();

        ChunkedHtmlWriter::write($mpdf, $html, 5);

        self::assertCount(3, $mpdf->bodyWrites);
        self::assertSame('<p>konec</p>', $mpdf->bodyWrites[2]);
        foreach ($mpdf->bodyWrites as $write) {
            self::assertSame(substr_count($write, '<table'), substr_count($write, '</table>'));
            self::assertStringNotContainsString('<tfoot></tbody>', $write);
        }
        self::assertMatchesRegularExpression('/<tr><td class="c1">10<\/td>.*<\/tbody><tfoot><tr><td>CELKEM<\/td>/', $mpdf->bodyWrites[1]);
        self::assertSame(['<table class="book"><thead><tr><th class="c1">A</th><th class="c2">B</th></tr></thead></table>', ''], $mpdf->headers);
    }

    /** HTML bez <body> (sestavy skládané v PHP): styl jde do CSS, ne jako text do těla. */
    public function testHtmlWithoutBodyDoesNotWriteStyleIntoBody(): void
    {
        $mpdf = self::recordingMpdf();
        ChunkedHtmlWriter::write($mpdf, '<style>td{color:#000}</style><h1>Kniha</h1><table><tr><td>1</td></tr></table>');
        self::assertSame(['<h1>Kniha</h1><table><tr><td>1</td></tr></table>'], $mpdf->bodyWrites);
    }

    public function testShortTableIsWrittenUntouched(): void
    {
        $html = self::syntheticJournalHtml(5);
        $mpdf = self::recordingMpdf();
        ChunkedHtmlWriter::write($mpdf, $html, 400);
        self::assertCount(1, $mpdf->bodyWrites);
        self::assertStringContainsString('<thead>', $mpdf->bodyWrites[0]);
        self::assertSame([], $mpdf->headers);
    }

    public function testEmptyBodyDoesNotThrow(): void
    {
        $html = '<html><head><style>body{color:#000;}</style></head><body></body></html>';
        $mpdf = self::mpdf();
        ChunkedHtmlWriter::write($mpdf, $html);
        self::assertStringStartsWith('%PDF', $mpdf->Output('', 'S'));
    }

    /** mPDF, které zápisy těla a hlavičky stránky jen zaznamená. */
    private static function recordingMpdf(): Mpdf
    {
        return new class ([
            'mode' => 'utf-8',
            'tempDir' => sys_get_temp_dir() . '/mi-mpdf-test-' . bin2hex(random_bytes(4)),
        ] + MpdfFontConfig::options()) extends Mpdf {
            /** @var list<string> */
            public array $bodyWrites = [];
            /** @var list<string> */
            public array $headers = [];

            public function WriteHTML($html, $mode = \Mpdf\HTMLParserMode::DEFAULT_MODE, $init = true, $close = true): void
            {
                if ($mode === \Mpdf\HTMLParserMode::HTML_BODY) {
                    $this->bodyWrites[] = $html;
                }
            }

            public function SetHTMLHeader($header = '', $OE = '', $write = false): void
            {
                $this->headers[] = $header;
            }
        };
    }

    private static function mpdf(): Mpdf
    {
        $tmpDir = sys_get_temp_dir() . '/mi-mpdf-test-' . bin2hex(random_bytes(4));
        @mkdir($tmpDir, 0777, true);

        return new Mpdf(array_replace([
            'mode'          => 'utf-8',
            'format'        => 'A4-L',
            'orientation'   => 'L',
            'margin_left'   => 8,
            'margin_right'  => 8,
            'margin_top'    => 12,
            'margin_bottom' => 12,
            'tempDir'       => $tmpDir,
            'autoPageBreak' => true,
        ], MpdfFontConfig::options()));
    }

    private static function syntheticJournalHtml(int $entryCount): string
    {
        $rows = '';
        for ($i = 1; $i <= $entryCount; $i++) {
            $rows .= sprintf(
                '<tr class="entry-head"><td>%1$d.1.2024</td><td>DOC-%1$d</td>'
                . '<td class="col-desc">Testovací zápis číslo %1$d s trochu delším popisem kvůli objemu HTML</td>'
                . '<td>ručně</td><td colspan="2"></td><td class="num">%2$s</td><td class="num">%2$s</td></tr>',
                $i,
                number_format($i * 10.5, 2, ',', ' '),
            );
            for ($line = 1; $line <= 3; $line++) {
                $rows .= sprintf(
                    '<tr class="entry-line"><td></td><td></td><td></td><td></td>'
                    . '<td>%1$d%2$d</td><td>Analytický účet %1$d%2$d se dlouhým názvem</td>'
                    . '<td class="num">%3$s</td><td class="num"></td></tr>',
                    100 + $line,
                    $i % 10,
                    number_format($i * 3.5, 2, ',', ' '),
                );
            }
        }

        return '<!DOCTYPE html><html lang="cs"><head><meta charset="utf-8"><title>Test</title>'
            . '<style>body{font-family:sans-serif;font-size:8pt;} table{width:100%;border-collapse:collapse;} '
            . 'td{padding:0.6mm 1mm;border-bottom:0.2pt solid #eee;} .num{text-align:right;} .col-desc{width:48mm;}</style>'
            . '</head><body>'
            . '<div class="entity">Testovací firma s.r.o. — IČO: 12345678</div>'
            . '<table class="entries"><thead><tr>'
            . '<th>Datum</th><th>Doklad</th><th class="col-desc">Popis</th><th>Původ</th>'
            . '<th>Účet</th><th>Název</th><th>MD</th><th>Dal</th>'
            . '</tr></thead><tbody>' . $rows . '</tbody></table>'
            . '<table class="totals"><tr><td>Počet zápisů: ' . $entryCount . '</td>'
            . '<td class="num">100,00</td><td class="num">100,00</td></tr></table>'
            . '</body></html>';
    }
}
