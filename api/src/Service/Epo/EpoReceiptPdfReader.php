<?php

declare(strict_types=1);

namespace MyInvoice\Service\Epo;

use Smalot\PdfParser\Parser as PdfTextParser;

/**
 * Přečte podací číslo a rozhodný čas z PDF opisu/potvrzení staženého z Daňového portálu.
 *
 * Je to ZÁLOŽNÍ zdroj, ne důkaz. Průkazná je dodejka P7S — ta je podepsaná pečetí správce
 * daně a dá se ověřit i za deset let. PDF je jen tisk, jenže právě ten si část účetních
 * z portálu odnese (dodejku si stáhnout nevzpomenou nebo k ní přes asistované podání
 * vůbec nedojdou). Bez tohohle čtení pak aplikace o podání neví nic, přestože podací
 * číslo má na obrazovce před sebou — a účetní ho přepisuje ručně.
 *
 * Výsledek proto putuje do metadat artefaktu jako `hint` a používá se výhradně
 * k předvyplnění formuláře „Označit jako podané". Stav podání sám neposune.
 */
final class EpoReceiptPdfReader
{
    /** Nad tuhle mez to není opis podání, ale scan celého účetnictví. */
    private const MAX_INPUT_BYTES = 20 * 1024 * 1024;

    /** Záloha pro běh bez ext-intl; pokrývá češtinu, o víc tu nejde. */
    private const FOLD_MAP = [
        'á' => 'a', 'ä' => 'a', 'č' => 'c', 'ď' => 'd', 'é' => 'e', 'ě' => 'e', 'ë' => 'e',
        'í' => 'i', 'ň' => 'n', 'ó' => 'o', 'ö' => 'o', 'ř' => 'r', 'š' => 's', 'ť' => 't',
        'ú' => 'u', 'ů' => 'u', 'ü' => 'u', 'ý' => 'y', 'ž' => 'z',
        'Á' => 'a', 'Ä' => 'a', 'Č' => 'c', 'Ď' => 'd', 'É' => 'e', 'Ě' => 'e', 'Ë' => 'e',
        'Í' => 'i', 'Ň' => 'n', 'Ó' => 'o', 'Ö' => 'o', 'Ř' => 'r', 'Š' => 's', 'Ť' => 't',
        'Ú' => 'u', 'Ů' => 'u', 'Ü' => 'u', 'Ý' => 'y', 'Ž' => 'z',
    ];

    /**
     * @return array{
     *   text_available:bool,reference:?string,submitted_at:?string,
     *   checksum:?string,office_code:?string
     * }
     */
    public function read(string $path): array
    {
        $empty = [
            'text_available' => false,
            'reference' => null,
            'submitted_at' => null,
            'checksum' => null,
            'office_code' => null,
        ];
        if (!is_file($path)) {
            return $empty;
        }
        $size = (int) filesize($path);
        if ($size <= 0 || $size > self::MAX_INPUT_BYTES) {
            return $empty;
        }

        try {
            $text = (new PdfTextParser())->parseFile($path)->getText();
        } catch (\Throwable) {
            // Skenovaný nebo chráněný opis nemá textovou vrstvu. Není to chyba nahrání —
            // soubor se archivuje dál, jen z něj nic nepřečteme.
            return $empty;
        }
        $text = trim(preg_replace('/[ \t\x{00a0}]+/u', ' ', $text) ?? $text);
        if ($text === '') {
            return $empty;
        }

        $folded = $this->fold($text);

        return [
            'text_available' => true,
            'reference' => $this->reference($folded),
            'submitted_at' => $this->submittedAt($folded),
            'checksum' => $this->checksum($folded),
            'office_code' => $this->officeCode($folded),
        ];
    }

    /**
     * Text bez diakritiky a v malých písmenech.
     *
     * Popisky v opisu se liší podle formuláře i verze portálu („Podací číslo", „Číslo
     * podání", „ID podání") a v textové vrstvě PDF navíc někdy dorazí diakritika
     * rozložená na kombinující znaky. Hledat rovnou v původním textu proto znamená minout
     * půlku variant; hodnoty samotné jsou čísla a data, takže složením o nic nepřijdou.
     *
     * `iconv` s `//TRANSLIT` se tu použít NEDÁ: na Windows z „í" udělá „'i", takže
     * z „Podací číslo" vznikne „podac'i c'islo" a žádný vzor to netrefí. Proto rozklad
     * na písmeno + diakritické znaménko a zahození znamének.
     */
    private function fold(string $text): string
    {
        if (class_exists(\Normalizer::class)) {
            $decomposed = \Normalizer::normalize($text, \Normalizer::FORM_D);
            $stripped = is_string($decomposed)
                ? preg_replace('/\p{Mn}+/u', '', $decomposed)
                : null;
            if (is_string($stripped) && $stripped !== '') {
                return mb_strtolower($stripped);
            }
        }
        return mb_strtolower(strtr($text, self::FOLD_MAP));
    }

    private function reference(string $text): ?string
    {
        $patterns = [
            '/(?:podaci\s+cislo|cislo\s+podani|id\s+podani|c\.\s*podani)\s*[:\-]?\s*([a-z0-9][a-z0-9\-\/]{4,39})/u',
            '/podani\s+bylo\s+prijato\s+pod\s+cislem\s*[:\-]?\s*([a-z0-9][a-z0-9\-\/]{4,39})/u',
        ];
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $m) === 1) {
                return mb_strtoupper(trim($m[1], '-/'));
            }
        }
        return null;
    }

    private function submittedAt(string $text): ?string
    {
        $date = '(\d{1,2}\.\s?\d{1,2}\.\s?\d{4})';
        $time = '(\d{1,2}:\d{2}(?::\d{2})?)';
        $patterns = [
            '/(?:datum\s+a\s+cas\s+podani|datum\s+podani|podano\s+dne|prijato\s+dne)\s*[:\-]?\s*'
                . $date . '(?:[\s,]+(?:v\s+)?' . $time . ')?/u',
            '/' . $date . '\s+' . $time . '/u',
        ];
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $m) !== 1) {
                continue;
            }
            $value = str_replace(' ', '', $m[1]) . (isset($m[2]) && $m[2] !== '' ? ' ' . $m[2] : '');
            $timestamp = strtotime($value);
            if ($timestamp !== false) {
                return date('Y-m-d H:i:s', $timestamp);
            }
        }
        return null;
    }

    private function checksum(string $text): ?string
    {
        return preg_match(
            '/(?:kontrolni\s+soucet|kc|md5)\s*[:\-]?\s*([0-9a-f]{32})\b/u',
            $text,
            $m,
        ) === 1 ? $m[1] : null;
    }

    private function officeCode(string $text): ?string
    {
        return preg_match('/(?:c\.?\s*ufo|kod\s+ufo|financni\s+urad)\s*[:\-]?\s*(\d{3})\b/u', $text, $m) === 1
            ? $m[1]
            : null;
    }
}
