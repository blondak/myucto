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
 * řádků (mode HTML_BODY).
 *
 * Samotné dávkování nestačí na paměť: mPDF drží otevřenou <table> celou a rozkládá
 * ji až při </table>, takže deník s 13 tis. zápisy (55 tis. řádků tabulky) spotřeboval
 * 3,7 GB. Tabulka delší než $rowsPerChunk se proto na hranici dávky UZAVŘE a otevře
 * znovu se stejným otevíracím tagem. Každý kus se vysází a uvolní hned. Hlavičku
 * sloupců (<thead>) pak nese hlavička stránky, takže je na každé stránce jednou a šev
 * mezi kusy není vidět. Aby sloupce kusů i hlavičky lícovaly, musí mít šablona pevné
 * šířky všech sloupců v procentech (součet 100 %). `$splitBefore` (regex na otevírací <tr>)
 * dovolí dělit jen před řádkem, který začíná logický celek (zápis deníku s jeho
 * řádky zůstane v jednom kusu); celek delší než čtyři dávky se rozdělí i uprostřed,
 * jinak by jeden obří zápis (počáteční stavy) vrátil problém s pamětí.
 *
 * Použitelné jen na šablony bez vnořených <table> uvnitř <tr> (žádná z
 * report šablon to nemá — řádky mají jen <td>).
 */
final class ChunkedHtmlWriter
{
    /**
     * @param string      $html         kompletní HTML dokument (výstup Twig šablony, s <style> v <head>)
     * @param int         $rowsPerChunk kolik <tr> elementů poslat do mPDF v jednom WriteHTML() volání
     * @param string|null $splitBefore  regex, kterému musí odpovídat <tr>, před nímž se smí tabulka rozdělit
     */
    public static function write(Mpdf $mpdf, string $html, int $rowsPerChunk = 400, ?string $splitBefore = null): void
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

        if (preg_match('/<body\b[^>]*>(.*)<\/body>/is', $html, $bodyMatch)) {
            $body = $bodyMatch[1];
        } else {
            $body = (string) preg_replace('/<style\b[^>]*>.*?<\/style>/is', '', $html);
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
        // Stav právě otevřené tabulky: otevírací tag, <thead> (i s řádky) a zda má <tbody>.
        $tableOpen = null;
        $thead = '';
        $inThead = false;
        $hasTbody = false;
        // Za </tbody> (tedy v <tfoot>) se už nedělí: součtový řádek patří k poslednímu kusu.
        $sealed = false;
        // Rozdělená tabulka nese hlavičku sloupců jako hlavičku stránky, ne jako <thead>
        // každého kusu, jinak by se hlavička opakovala uprostřed stránky na každém švu.
        $pagedHeader = false;

        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }
            $isRow = preg_match('/^<tr\b/i', $part) === 1;
            if (!$isRow) {
                $close = $pagedHeader ? stripos($part, '</table>') : false;
                if ($close !== false) {
                    $head = substr($part, 0, $close + 8);
                    $mpdf->WriteHTML($buffer . $head, HTMLParserMode::HTML_BODY);
                    $mpdf->SetHTMLHeader('');
                    $pagedHeader = false;
                    self::trackStructure($head, $tableOpen, $thead, $inThead, $hasTbody, $sealed);
                    $part = substr($part, $close + 8);
                    $buffer = '';
                    $rowsInBuffer = 0;
                }
                self::trackStructure($part, $tableOpen, $thead, $inThead, $hasTbody, $sealed);
                $buffer .= $part;
                continue;
            }
            if ($inThead) {
                $thead .= $part;
                $buffer .= $part;
                continue;
            }
            if ($rowsInBuffer >= $rowsPerChunk) {
                if ($tableOpen === null) {
                    $mpdf->WriteHTML($buffer, HTMLParserMode::HTML_BODY);
                    $buffer = '';
                    $rowsInBuffer = 0;
                } elseif (!$sealed && ($splitBefore === null || preg_match($splitBefore, $part) === 1 || $rowsInBuffer >= 4 * $rowsPerChunk)) {
                    $mpdf->WriteHTML($buffer . ($hasTbody ? '</tbody>' : '') . '</table>', HTMLParserMode::HTML_BODY);
                    if (!$pagedHeader && $thead !== '') {
                        // Platí od další stránky; ta rozepsaná už hlavičku sloupců má z <thead>.
                        $mpdf->setAutoTopMargin = 'stretch';
                        // Tělo navazuje těsně pod hlavičkou sloupců jako pod <thead>, bez mezery.
                        $mpdf->autoMarginPadding = 0;
                        $mpdf->SetHTMLHeader($tableOpen . $thead . '</table>');
                        $pagedHeader = true;
                    }
                    $buffer = $tableOpen . ($hasTbody ? '<tbody>' : '');
                    $rowsInBuffer = 0;
                }
            }
            $buffer .= $part;
            $rowsInBuffer++;
        }

        if ($buffer !== '') {
            $mpdf->WriteHTML($buffer, HTMLParserMode::HTML_BODY);
        }
    }

    /** Projde tagy tabulky ve strukturní části (ta je malá — mezi řádky) a posune stav. */
    private static function trackStructure(string $part, ?string &$tableOpen, string &$thead, bool &$inThead, bool &$hasTbody, bool &$sealed): void
    {
        if (!preg_match_all('/<table\b[^>]*>|<\/table>|<thead\b[^>]*>|<\/thead>|<tbody\b[^>]*>|<\/tbody>|<tfoot\b[^>]*>/i', $part, $m, PREG_OFFSET_CAPTURE)) {
            if ($inThead) {
                $thead .= $part;
            }
            return;
        }
        $pos = 0;
        foreach ($m[0] as [$tag, $offset]) {
            if ($inThead) {
                $thead .= substr($part, $pos, $offset - $pos);
            }
            $pos = $offset + strlen($tag);
            $lower = strtolower($tag);
            if (str_starts_with($lower, '<table')) {
                $tableOpen = $tag;
                $thead = '';
                $inThead = false;
                $hasTbody = false;
                $sealed = false;
            } elseif ($lower === '</table>') {
                $tableOpen = null;
                $thead = '';
                $inThead = false;
                $hasTbody = false;
                $sealed = false;
            } elseif (str_starts_with($lower, '<thead')) {
                $inThead = true;
                $thead = $tag;
            } elseif ($lower === '</thead>') {
                $thead .= $tag;
                $inThead = false;
            } elseif ($lower === '</tbody>' || str_starts_with($lower, '<tfoot')) {
                $sealed = true;
            } else {
                $hasTbody = true;
            }
        }
        if ($inThead) {
            $thead .= substr($part, $pos);
        }
    }
}
