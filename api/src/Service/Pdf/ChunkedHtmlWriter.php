<?php

declare(strict_types=1);

namespace MyInvoice\Service\Pdf;

use Mpdf\HTMLParserMode;
use Mpdf\Mpdf;

/**
 * Zápis velkého HTML do mPDF po částech (bug #… — export účetního deníku pro
 * velkou firmu ~25 tis. zápisů/rok padal na "HTML code size is larger than
 * pcre.backtrack_limit"). mPDF má tenhle limit na jedno volání WriteHTML() —
 * chybová hláška sama radí "Pass your HTML in smaller chunks", což je přesně
 * tohle: CSS pošleme zvlášť (mode HEADER_CSS), tělo dokumentu pak po dávkách
 * řádků (mode HTML_BODY). mPDF si mezi voděními WriteHTML() drží otevřený
 * stav parseru (otevřenou <table>/<tbody>), takže rozdělení přesně na hranici
 * `<tr>…</tr>` je bezpečné a výsledné PDF je vizuálně shodné s jedním
 * voláním — jen bez rizika, že se to zhroutí na regexovém limitu.
 *
 * Použitelné jen na šablony bez vnořených <table> uvnitř <tr> (žádná z
 * report šablon to nemá — řádky mají jen <td>).
 */
final class ChunkedHtmlWriter
{
    /**
     * @param string $html   kompletní HTML dokument (výstup Twig šablony, s <style> v <head>)
     * @param int    $rowsPerChunk kolik <tr> elementů poslat do mPDF v jednom WriteHTML() volání
     */
    public static function write(Mpdf $mpdf, string $html, int $rowsPerChunk = 400): void
    {
        if ($rowsPerChunk < 1) {
            $rowsPerChunk = 1;
        }

        if (preg_match_all('/<style\b[^>]*>(.*?)<\/style>/is', $html, $styleMatches)) {
            $css = implode("\n", $styleMatches[1]);
            if (trim($css) !== '') {
                $mpdf->WriteHTML($css, HTMLParserMode::HEADER_CSS);
            }
        }

        $body = $html;
        if (preg_match('/<body\b[^>]*>(.*)<\/body>/is', $html, $bodyMatch)) {
            $body = $bodyMatch[1];
        }

        // Rozdělí tělo na střídavá "strukturní" a "<tr>…</tr>" místa se zachováním
        // pořadí (PREG_SPLIT_DELIM_CAPTURE) — jen tak lze bezpečně určovat hranice
        // dávek přesně na konci řádku, ne uprostřed tagu.
        $parts = preg_split('/(<tr\b.*?<\/tr>)/is', $body, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($parts === false) {
            // Fallback — nemělo by nastat, ale jistota je jistota.
            $mpdf->WriteHTML($body, HTMLParserMode::HTML_BODY);
            return;
        }

        $buffer = '';
        $rowsInBuffer = 0;

        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }
            $buffer .= $part;
            if (preg_match('/^<tr\b/i', $part) === 1) {
                $rowsInBuffer++;
            }
            if ($rowsInBuffer >= $rowsPerChunk) {
                $mpdf->WriteHTML($buffer, HTMLParserMode::HTML_BODY);
                $buffer = '';
                $rowsInBuffer = 0;
            }
        }

        if ($buffer !== '') {
            $mpdf->WriteHTML($buffer, HTMLParserMode::HTML_BODY);
        }
    }
}
