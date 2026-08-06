<?php

declare(strict_types=1);

namespace MyInvoice\Support;

/**
 * Zdroj kurzu na přijaté faktuře (`purchase_invoices.exchange_rate_source`, migrace 1303)
 * — jediný zdroj pravdy pro doménu hodnot i pro to, kdo smí kurz přepsat.
 *
 * Kurz se automaticky přenačítá při změně rozhodného dne ({@see ExchangeRateDate}) nebo
 * měny. Přepsat se přitom smí jen hodnota, která je FUNKCÍ TOHO DATA — tedy denní kurz
 * ČNB nebo pevný kurz období (§ 24/7 ZoÚ). Všechno ostatní je fakt o dokladu, ne
 * odvozenina, a automatika ho nesmí ztratit:
 *
 *     user (3)  >  import / idoklad / fakturoid / manual (2)  >  cnb / fixed (1)
 *
 *   - `cnb`   — denní kurz ČNB k rozhodnému dni. Uživatel si ho objednal tlačítkem
 *               „Načíst z ČNB", takže přenačtení k novému datu je přesně to, co chce.
 *   - `fixed` — pevný kurz období (§ 24/7 ZoÚ). Taky funkce data → přenačte se.
 *   - `import`      — kurz přinesl cizí systém nebo doklad dodavatele (ISDOC, AI extrakce
 *                     z PDF, iDoklad, Fakturoid). Není odvozený z data, nepřepisuje se.
 *   - `idoklad`, `fakturoid` — DEPRECATED. Nikdy se nezapsaly (importy plnily 'manual');
 *                     v enumu zůstávají kvůli historickým datům a chovají se jako `import`.
 *   - `manual`      — DEPRECATED = „neznámý / historický zápis". Do migrace 1303 to byla
 *                     hodnota, kterou zapisovaly VŠECHNY importy, takže o původu kurzu
 *                     neříká nic. Nově se NEZAPISUJE (viz architektonický guard
 *                     v ExchangeRateGuardTest). Fail-safe je nepřepisovat → rank 2.
 *   - `user`        — člověk vepsal kurz do formuláře. Nepřepisuje se nikdy.
 *
 * Proč se `manual` nepřejmenoval na `unknown`: migrace musí být idempotentní. Přejmenovací
 * UPDATE by při druhém běhu smazal i hodnoty, které mezitím vznikly legitimně — proto se
 * enum rozšířil jen ADITIVNĚ a `manual` nese varování v COLUMN COMMENT, tady a v i18n
 * labelu („neznámý / historický").
 */
final class ExchangeRateSources
{
    /**
     * DB default (migrace 1303). Záměrně NENÍ 'cnb': volající, který pole nepošle, by se
     * jinak označil za „systémem odvozený" a přenačtení by mu kurz smělo přepsat.
     * Fail-safe je opačný směr — neznámý původ se nepřepisuje.
     */
    public const DEFAULT = 'manual';

    /** @var list<string> */
    public const ALL = ['cnb', 'manual', 'idoklad', 'fakturoid', 'fixed', 'import', 'user'];

    /**
     * Rank 1 = odvozeno z data (přenačtení smí přepsat), 2 = fakt o dokladu / neznámo,
     * 3 = vědomé rozhodnutí člověka. Viz docblock třídy.
     */
    private const RANK = [
        'cnb'       => 1,
        'fixed'     => 1,
        'import'    => 2,
        'idoklad'   => 2,
        'fakturoid' => 2,
        'manual'    => 2,
        'user'      => 3,
    ];

    /** Zdroje, které smí automatické přenačtení přepsat. */
    private const DATE_DERIVED = ['cnb', 'fixed'];

    /** Whitelist + fallback na DEFAULT u neznámé/prázdné hodnoty. */
    public static function normalize(mixed $value, string $fallback = self::DEFAULT): string
    {
        $v = is_string($value) ? trim($value) : '';

        return in_array($v, self::ALL, true) ? $v : $fallback;
    }

    public static function isValid(mixed $value): bool
    {
        return is_string($value) && in_array($value, self::ALL, true);
    }

    public static function rank(mixed $value): int
    {
        return self::RANK[self::normalize($value)];
    }

    /**
     * Smí automatika (přenačtení kurzu po změně rozhodného data / měny) přepsat kurz
     * s tímhle zdrojem? Jen u hodnot, které jsou funkcí data.
     */
    public static function isAutoReloadable(mixed $value): bool
    {
        return in_array(self::normalize($value), self::DATE_DERIVED, true);
    }

    /**
     * Štítek pro kurz vyřešený {@see \MyInvoice\Service\Currency\ExchangeRateApplier}:
     * pevný kurz období → 'fixed', denní/cache/last-known ČNB → 'cnb'.
     */
    public static function fromResolved(mixed $applierSource): string
    {
        return $applierSource === 'fixed' ? 'fixed' : 'cnb';
    }
}
