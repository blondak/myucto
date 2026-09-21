<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Pdf;

use Mpdf\Mpdf;
use MyInvoice\Service\Pdf\ChunkedHtmlWriter;
use MyInvoice\Service\Pdf\MpdfFontConfig;
use PHPUnit\Framework\TestCase;

/**
 * Regresní test na produkční pád exportu účetního deníku velké firmy
 * (~25 tis. řádků deníku/rok): jedno volání Mpdf::WriteHTML() na celý
 * dokument narazí na `pcre.backtrack_limit` a shodí export s chybou
 * "HTML code size is larger than pcre.backtrack_limit". Test to ověřuje
 * rychle — místo tisíců skutečných řádků dočasně STÁHNE `pcre.backtrack_limit`
 * na malou hodnotu, takže i pár stovek řádků spolehlivě reprodukuje stejnou
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
        $html = self::syntheticJournalHtml(600);

        $this->originalBacktrackLimit = (string) ini_get('pcre.backtrack_limit');
        ini_set('pcre.backtrack_limit', '2000');

        $failingMpdf = self::mpdf();
        $threw = false;
        try {
            $failingMpdf->WriteHTML($html);
        } catch (\Throwable $e) {
            $threw = true;
        }
        self::assertTrue($threw, 'Test musí nejdřív ověřit, že syntetické HTML za sníženého pcre.backtrack_limit skutečně reprodukuje původní pád — jinak test nic neříká.');

        $okMpdf = self::mpdf();
        ChunkedHtmlWriter::write($okMpdf, $html, rowsPerChunk: 3);
        $pdf = $okMpdf->Output('', 'S');

        self::assertStringStartsWith('%PDF', $pdf);
    }

    public function testChunkedOutputContainsAllRowsAndBothTotals(): void
    {
        $html = self::syntheticJournalHtml(120);
        $mpdf = self::mpdf();
        ChunkedHtmlWriter::write($mpdf, $html, rowsPerChunk: 25);
        $pdf = $mpdf->Output('', 'S');

        self::assertStringStartsWith('%PDF', $pdf);
        self::assertGreaterThan(1000, strlen($pdf));
    }

    public function testEmptyBodyDoesNotThrow(): void
    {
        $html = '<html><head><style>body{color:#000;}</style></head><body></body></html>';
        $mpdf = self::mpdf();
        ChunkedHtmlWriter::write($mpdf, $html);
        self::assertStringStartsWith('%PDF', $mpdf->Output('', 'S'));
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
