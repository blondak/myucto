<?php

declare(strict_types=1);

namespace MyInvoice\Support;

/**
 * Hlavička PDF nemusí být na prvním bajtu.
 *
 * ISO 32000 i všechny běžné prohlížeče berou `%PDF-` kdekoli v prvních 1024 bajtech.
 * Generátory to využívají nechtěně: PHP skript s mezerou před `<?php` pošle před
 * dompdf výstup prázdný řádek, jiné systémy přidají BOM. Takové PDF se v prohlížeči
 * otevře normálně, ale kontrola `str_starts_with($bytes, '%PDF')` ho odmítne
 * („Obsah souboru neodpovídá jeho příponě").
 *
 * {@see normalize()} úvodní bajty před hlavičkou odřízne. PDF to spíš opraví, než
 * poškodí: generátor, který před výstup něco přilepil, počítal offsety v xref od
 * hlavičky, ne od začátku souboru. Používá se na VSTUPU dokladu, takže dál (ISDOC,
 * AI, archivace) už teče PDF, které začíná `%PDF-`.
 */
final class PdfBytes
{
    /** ISO 32000-1, příloha H.3 (implementační poznámka 13): hlavička v prvních 1024 bajtech. */
    private const HEADER_WINDOW = 1024;

    /** Pozice hlavičky `%PDF-`, nebo null, když v okně není (soubor není PDF). */
    public static function headerOffset(string $bytes): ?int
    {
        $pos = strpos(substr($bytes, 0, self::HEADER_WINDOW), '%PDF-');
        return $pos === false ? null : $pos;
    }

    public static function isPdf(string $bytes): bool
    {
        return self::headerOffset($bytes) !== null;
    }

    /** PDF s hlavičkou na začátku; ne-PDF vstup vrací beze změny. */
    public static function normalize(string $bytes): string
    {
        $offset = self::headerOffset($bytes);
        return $offset === null || $offset === 0 ? $bytes : substr($bytes, $offset);
    }
}
