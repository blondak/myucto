<?php

declare(strict_types=1);

namespace MyInvoice\Support;

/**
 * Fail-closed normalizace uživatelsky zadaných webových adres (SEC-10).
 *
 * Hodnota se na frontendu renderuje do `href`, takže musí projít jen absolutní
 * http(s) URL. Všechno ostatní (javascript:, data:, vbscript:, file:, relativní
 * i protokolově-relativní odkazy) vrací null a volající pole neuloží.
 *
 * TS zrcadlo je `web/src/utils/safeUrl.ts` — obě verze musí zůstat 1:1
 * (stejný přístup jako u varsymbol.ts ↔ VarsymbolGenerator).
 */
final class SafeUrl
{
    /** Délka sloupce, do kterého se URL ukládá (např. eshop_manufacturers.website). */
    public const MAX_LENGTH = 255;

    /**
     * Vrátí normalizovanou absolutní http(s) URL, nebo null když je vstup nepoužitelný.
     */
    public static function normalizeWebUrl(mixed $raw): ?string
    {
        if (!is_string($raw)) {
            return null;
        }
        $url = trim($raw);
        if ($url === '') {
            return null;
        }

        // Řídicí znaky a mezery kdekoliv uvnitř. Prohlížeče \t\n\r před navigací
        // zahazují, takže "java\tscript:alert(1)" by se v href stalo funkčním
        // "javascript:alert(1)". Odmítáme celý řetězec, nesanitizujeme ho.
        if (preg_match('/[\x00-\x20\x7F]/', $url) === 1) {
            return null;
        }

        // Zpětné lomítko prohlížeče normalizují na "/", čímž se dá obejít kontrola
        // autority ("https://evil.com\@duvery-hodna.cz"). V URL nemá nekódované co dělat.
        if (str_contains($url, '\\')) {
            return null;
        }

        // Relativní ("/foo") ani protokolově-relativní ("//evil.com") odkazy nechceme.
        if (str_starts_with($url, '/')) {
            return null;
        }

        // Když uživatel schéma nenapsal, doplníme https:// (shodně s veřejným
        // náhledem faktury). Když napsal jakékoliv jiné schéma, odmítáme —
        // porovnání je case-insensitive, takže "JaVaScRiPt:" neprojde.
        // Procentem kódované schéma ("%6aavascript:") sem nespadne, protože
        // neodpovídá tvaru schématu, a jako host také neobstojí.
        if (preg_match('~^[a-zA-Z][a-zA-Z0-9+.\-]*:~', $url) === 1) {
            if (preg_match('~^https?://~i', $url) !== 1) {
                return null;
            }
        } else {
            $url = 'https://' . $url;
        }

        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'])) {
            return null;
        }
        $scheme = strtolower((string) $parts['scheme']);
        if ($scheme !== 'http' && $scheme !== 'https') {
            return null;
        }

        // userinfo ("https://podvod.cz:x@evil.com") mate uživatele o cílové doméně.
        // Kontrolujeme jak přes parse_url, tak přímo v autoritě — ať se nespoléháme
        // na jedinou implementaci parsování.
        if (isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }
        $authority = explode('/', substr($url, strlen($scheme) + 3), 2)[0];
        if (str_contains($authority, '@')) {
            return null;
        }

        $host = (string) ($parts['host'] ?? '');
        if ($host === '') {
            return null;
        }
        // Povolujeme písmena, číslice, tečku, pomlčku, IPv6 v hranatých závorkách
        // a bajty >= 0x80 kvůli IDN doménám ("https://mojedoména.cz").
        if (preg_match('~^[\[\]a-zA-Z0-9.\-:\x80-\xFF]+$~', $host) !== 1) {
            return null;
        }
        // Doména musí mít tečku nebo jít o IPv6/localhost — chrání před tím, aby se
        // z překlepu bez schématu ("javascript") stalo "https://javascript".
        if (!str_contains($host, '.') && !str_starts_with($host, '[') && strtolower($host) !== 'localhost') {
            return null;
        }

        // Schéma normalizujeme na malá písmena, zbytek URL necháváme beze změny
        // (cesta a query mohou být case-sensitive).
        $normalized = $scheme . substr($url, strlen($scheme));

        // Až po doplnění schématu — normalizovaná hodnota se musí vejít do sloupce.
        if (strlen($normalized) > self::MAX_LENGTH) {
            return null;
        }

        return $normalized;
    }
}
