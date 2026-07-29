<?php

declare(strict_types=1);

namespace MyInvoice\Support;

/**
 * Sdílený slug / transliterátor diakritiky (jediný zdroj pravdy).
 *
 * Sjednocuje dřív roztroušené kopie:
 *   - Service\Export\ExportFilename::transliterate  (názvy souborů, CZ+SK+DE+PL)
 *   - Service\Bank\EmailNotice\Parser\AbstractBankEmailNoticeParser::foldDiacritics
 *     (diakritiku-tolerantní matchování avíz)
 *   - Action\Stock\StockItemAction::slugFromName    (SKU fallback, VELKÁ)
 *   - Repository\TripCategoryRepository::slug        (kód kategorie cesty)
 *   - Service\Export\MonthlyExportService::asciiSlug (slug názvu v exportu)
 *
 * Mapa je **superset** všech předchozích (žádné cílové písmeno se neliší), takže
 * náhrada zachovává výstup a jen doplňuje pár znaků navíc (ą, ę, à, â, …).
 */
final class Slugifier
{
    /** @var array<string,string>|null cache: lowercase mapa + auto-dogenerované verzálky */
    private static ?array $map = null;

    /** @var array<string,string> diakritika (malá) → ASCII; verzálky se dopočítají */
    private const LOWER = [
        // a
        'á'=>'a','à'=>'a','â'=>'a','ä'=>'a','ã'=>'a','å'=>'a','ą'=>'a',
        // c
        'č'=>'c','ć'=>'c','ç'=>'c',
        // d
        'ď'=>'d','đ'=>'d',
        // e
        'é'=>'e','è'=>'e','ê'=>'e','ë'=>'e','ě'=>'e','ę'=>'e',
        // i
        'í'=>'i','ì'=>'i','î'=>'i','ï'=>'i',
        // l
        'ĺ'=>'l','ľ'=>'l','ł'=>'l',
        // n
        'ň'=>'n','ń'=>'n','ñ'=>'n',
        // o
        'ó'=>'o','ò'=>'o','ô'=>'o','ö'=>'o','õ'=>'o','ø'=>'o',
        // r
        'ŕ'=>'r','ř'=>'r',
        // s (+ ß→ss)
        'š'=>'s','ś'=>'s','ß'=>'ss',
        // t
        'ť'=>'t',
        // u
        'ú'=>'u','ù'=>'u','û'=>'u','ü'=>'u','ů'=>'u',
        // y
        'ý'=>'y','ÿ'=>'y',
        // z
        'ž'=>'z','ź'=>'z','ż'=>'z',
    ];

    /** Přepíše diakritiku na ASCII (č→c, ě→e, ž→z, ö→o, ß→ss, ł→l, …), case-preserving. */
    public static function transliterate(string $s): string
    {
        if (self::$map === null) {
            $map = self::LOWER;
            foreach (self::LOWER as $from => $to) {
                $map[mb_strtoupper($from, 'UTF-8')] = mb_strtoupper($to, 'UTF-8');
            }
            self::$map = $map;
        }
        return strtr($s, self::$map);
    }

    /**
     * Slug/identifikátor z libovolného textu: transliterace diakritiky → sloučení
     * nepovolených znaků do separátoru → trim → volitelně délka a fallback.
     *
     * @param string $separator znak mezi slovy ('-' pro SKU/kód, '_' pro kategorie)
     * @param 'lower'|'upper'|'keep' $case  výsledné písmo
     * @param int    $maxLen  ořez (0 = bez limitu)
     * @param string $fallback vrácený řetězec, když slug vyjde prázdný
     */
    public static function slug(
        string $input,
        string $separator = '-',
        string $case = 'lower',
        int $maxLen = 0,
        string $fallback = '',
    ): string {
        $s = self::transliterate($input);
        if ($case === 'lower') {
            $s = mb_strtolower($s, 'UTF-8');
        } elseif ($case === 'upper') {
            $s = mb_strtoupper($s, 'UTF-8');
        }
        $s = (string) preg_replace('/[^A-Za-z0-9]+/', $separator, $s);
        $s = trim($s, $separator);
        if ($s === '') {
            return $fallback;
        }
        return $maxLen > 0 ? mb_substr($s, 0, $maxLen) : $s;
    }
}
