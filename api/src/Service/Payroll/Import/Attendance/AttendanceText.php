<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Import\Attendance;

/**
 * Normalizace textů z podkladů: hlavičky, názvy listů a jména osob.
 *
 * Mezi verzemi šablony se liší diakritika, velikost písmen, zalomení řádku
 * v buňce hlavičky i pevné mezery — nic z toho nesmí rozhodnout o významu.
 */
final class AttendanceText
{
    private const TRANSLITERATION = [
        'á' => 'a', 'ä' => 'a', 'č' => 'c', 'ď' => 'd', 'é' => 'e', 'ě' => 'e',
        'ë' => 'e', 'í' => 'i', 'ĺ' => 'l', 'ľ' => 'l', 'ň' => 'n', 'ó' => 'o',
        'ô' => 'o', 'ö' => 'o', 'ő' => 'o', 'ŕ' => 'r', 'ř' => 'r', 'š' => 's',
        'ť' => 't', 'ú' => 'u', 'ů' => 'u', 'ü' => 'u', 'ű' => 'u', 'ý' => 'y',
        'ž' => 'z', 'ł' => 'l', 'ß' => 'ss', 'ą' => 'a', 'ę' => 'e', 'ś' => 's',
        'ć' => 'c', 'ń' => 'n', 'ź' => 'z', 'ż' => 'z',
    ];

    /** Tituly před a za jménem — do klíče osoby nepatří. `mqa` je častý překlep MgA. */
    private const TITLES = [
        'ing', 'bc', 'bca', 'mgr', 'mga', 'mqa', 'mudr', 'mvdr', 'judr', 'phdr', 'rndr', 'paedr', 'paeddr',
        'thdr', 'icdr', 'rsdr', 'doc', 'prof', 'phd', 'csc', 'drsc', 'dis', 'mba', 'bba', 'arch',
        'ingarch', 'dr', 'mddr', 'pharmdr', 'thlic', 'llm', 'msc', 'ba', 'ma', 'artd', 'dipl',
    ];

    /**
     * Závorka s druhem vztahu nebo kódem („(DPP)", „(Z0042)") je poznámka ke
     * jménu, ne jeho část. „(ml.)" a „(st.)" zůstávají — rozlišují otce a syna.
     */
    private const NOTE_IN_BRACKETS = '/[(\[]\s*(?:dpp|dpc|hpp|vpp|zmr|brigad[a-z]*|dohod[a-z ]*|[a-z]{0,4}\d[a-z0-9\/.-]*)\s*[)\]]/u';

    public static function normalize(string $value): string
    {
        $value = str_replace(["\u{00A0}", "\u{202F}", "\u{2007}", "\r", "\n", "\t"], ' ', $value);
        $value = mb_strtolower(trim($value), 'UTF-8');
        $value = strtr($value, self::TRANSLITERATION);
        $value = (string) preg_replace('/\s+/u', ' ', $value);

        return trim($value);
    }

    /**
     * Klíč osoby stabilní napříč soubory: bez diakritiky, bez titulů,
     * slova seřazená — „Novák Jan" i „Ing. Jan Novák" dají totéž.
     */
    public static function personKey(string $name): string
    {
        $words = self::nameWords($name);
        sort($words, SORT_STRING);

        return implode(' ', $words);
    }

    /** @return list<string> */
    public static function nameWords(string $name): array
    {
        $normalized = (string) preg_replace(self::NOTE_IN_BRACKETS, ' ', self::normalize($name));
        $normalized = (string) preg_replace('/[^\p{L}\p{N}\s-]+/u', ' ', $normalized);
        $words = [];
        foreach (preg_split('/[\s]+/u', $normalized) ?: [] as $word) {
            $word = trim($word, '-');
            if ($word === '' || in_array($word, self::TITLES, true)) {
                continue;
            }
            $words[] = $word;
        }

        return $words;
    }

    /**
     * Nástup a ukončení z poznámky („nový nástup 15.6.2026", „ukončení k 3. 6. 2026").
     * Datum bez slova nástup nebo ukončení se nebere — nevíme, co znamená.
     *
     * @return array{start_on:?string,end_on:?string}
     */
    public static function noteDates(string $note): array
    {
        $normalized = self::normalize($note);
        $find = static function (string $keywords) use ($normalized): ?string {
            if (preg_match('/(?:' . $keywords . ')\D{0,15}?(\d{1,2})\.\s*(\d{1,2})\.\s*(\d{4})/u', $normalized, $match) !== 1) {
                return null;
            }
            [$day, $month, $year] = [(int) $match[1], (int) $match[2], (int) $match[3]];

            return checkdate($month, $day, $year) ? sprintf('%04d-%02d-%02d', $year, $month, $day) : null;
        };

        return [
            'start_on' => $find('nastup|zahajeni'),
            'end_on' => $find('ukonc|skonc|vystup|odchod|konec'),
        ];
    }

    /**
     * Hodnota, která vypadá jako jméno osoby: aspoň dvě slova, z toho aspoň
     * jedno čistě z písmen. Jedno slovo smí nést číslice — exporty docházky
     * často píšou osobní číslo ke jménu („Novák Jan (Z0042)"). Čisté číslo,
     * čas ani dvě slova s číslicemi jménem nejsou.
     */
    public static function looksLikePersonName(string $value): bool
    {
        $value = trim($value);
        if ($value === '' || mb_strlen($value) > 120 || preg_match('/^[\d\s.,:\/+-]+$/u', $value) === 1) {
            return false;
        }
        $words = self::nameWords($value);
        $letters = 0;
        $withDigits = 0;
        foreach ($words as $word) {
            if (preg_match('/^\p{L}[\p{L}-]*$/u', $word) === 1) {
                ++$letters;
            } elseif (preg_match('/^\p{L}[\p{L}\d-]*$/u', $word) === 1 && preg_match('/\d/', $word) === 1) {
                ++$withDigits;
            } else {
                return false;
            }
        }

        return count($words) >= 2 && $letters >= 1 && $withDigits <= 1;
    }

    /** Řádek součtů (Celkem, Součet…) není osoba. */
    public static function isTotalsLabel(string $value): bool
    {
        return preg_match('/^(celkem|soucet|suma|mezisoucet|total)\b/u', self::normalize($value)) === 1;
    }
}
