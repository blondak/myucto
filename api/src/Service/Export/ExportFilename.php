<?php

declare(strict_types=1);

namespace MyInvoice\Service\Export;

use MyInvoice\Support\Slugifier;

/**
 * Sdílený helper pro bezpečné názvy souborů / ZIP entry v exportech.
 *
 * Místo aby se diakritika (č, ě, ž, …) ve jménu firmy nahradila podtržítkem
 * (`Prijata-2025001-Z_lut_ k_ _.pdf`), nejdřív ji **přepíšeme na ASCII**
 * (č→c, ě→e, ž→z, ö→o, ß→ss, ł→l, …) a teprve zbytek nepovolených znaků nahradíme
 * podtržítkem. Výsledek je čitelný a přitom bezpečný proti zip-slipu / problémovým
 * znakům na FAT/NTFS (`Prijata-2025001-Zluty-kun.pdf`).
 *
 * Transliterace i mapa (CZ+SK+DE/AT+PL a další) žijí v {@see Slugifier} — jednom
 * sdíleném zdroji pravdy; tady zůstává jen filename-specifická sanitizace.
 */
final class ExportFilename
{
    /** Přepíše diakritiku na ASCII (č→c, ě→e, ž→z, ö→o, ß→ss, ł→l, …). */
    public static function transliterate(string $s): string
    {
        return Slugifier::transliterate($s);
    }

    /**
     * Bezpečný název souboru / ZIP entry: nejdřív transliterace diakritiky, pak
     * zbývající nepovolené znaky → podtržítko. Zachová `. - _` a alfanumeriku.
     */
    public static function sanitize(string $s, string $fallback = 'soubor'): string
    {
        $s = self::transliterate($s);
        $out = preg_replace('/[^A-Za-z0-9._\-]/u', '_', $s);
        return ($out === null || $out === '') ? $fallback : $out;
    }
}
