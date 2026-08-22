<?php

declare(strict_types=1);

namespace MyInvoice\Infrastructure\Cache;

use MyInvoice\Infrastructure\Config\Config;

/**
 * H-08 — izolace instancí v Redisu stojí ČISTĚ na našem prefixu.
 *
 * Hosting dává všem instancím `db 0` a odlišuje je prefixem
 * (`myucto:i<id>:`). Není tam tedy žádná druhá pojistka: jediný klíč zapsaný
 * bez prefixu — nebo se stejným prefixem jako soused — znamená, že si dva
 * zákazníci vidí do cache. U `EntityCache` je v tom blobu seznam firem,
 * u `ApiTokenService` čerstvost tokenu, u `BruteForceGuard` počítadlo pokusů.
 *
 * Prefix se proto nesmí počítat na dvou místech. Tahle třída je JEDINÝ zdroj
 * pravdy a používá ji jak {@see RedisFactory} (skutečné klíče), tak
 * {@see RedisProbe} (diagnostika) — kdyby se rozešly, hlásila by diagnostika
 * „Redis běží" o keyspace, do kterého aplikace nesahá.
 *
 * DVĚ PRAVIDLA:
 *
 *  1. **Prázdný prefix se nikdy nepošle do Predisu.** `''` znamená BEZ
 *     prefixování, tedy holé klíče `bf:…`, `rl:…` ve sdíleném `db 0`.
 *     Prázdná hodnota se proto tiše nahradí výchozí — degradace na sdílenou
 *     cache je horší než ignorovaný překlep v cfg.
 *  2. **Ve spravovaném provozu (`app.managed`) je výchozí prefix ZAKÁZANÝ.**
 *     `myinvoice:` má každá instalace stejný, takže na flotile je to totéž
 *     jako žádný prefix. Tam Redis raději vůbec nepoužijeme — aplikace bez
 *     cache jen zpomalí, kdežto sdílená cache je únik mezi zákazníky.
 */
final class RedisKeyspace
{
    public const DEFAULT_PREFIX = 'myinvoice:';

    /**
     * Index databáze, který jediný dává na spravovaném hostingu smysl.
     * NENÍ zadrátovaný v klientovi — `redis.db` zůstává konfigurovatelný kvůli
     * self-hostu, kde má zákazník vlastní Redis a klidně i jinou databázi.
     */
    public const MANAGED_DB = 0;

    /** Prefix, který se skutečně předá Predisu. Nikdy prázdný. */
    public static function prefix(Config $config): string
    {
        $prefix = trim((string) $config->get('redis.prefix', self::DEFAULT_PREFIX));

        return $prefix !== '' ? $prefix : self::DEFAULT_PREFIX;
    }

    /**
     * Smí se na tuhle instanci Redis vůbec použít?
     *
     * Vrací null, když je vše v pořádku, jinak důvod k zalogování.
     * Volající při nenulovém důvodu Redis NEPOUŽIJE.
     */
    public static function unsafeReason(Config $config): ?string
    {
        if (!(bool) $config->get('app.managed', false)) {
            return null;
        }

        if (self::prefix($config) === self::DEFAULT_PREFIX) {
            return 'redis.prefix je výchozí (' . self::DEFAULT_PREFIX . '), a ten má na flotile každá '
                . 'instance stejný — izolace zákazníků v Redisu by neplatila. '
                . 'Nastav prefix jedinečný pro instanci (např. myucto:i<id>:). '
                . 'Do té doby běží aplikace bez Redisu.';
        }

        return null;
    }

    /**
     * Diagnostické varování, které nebrání běhu. Spravovaný hosting sdílí
     * `db 0` napříč instancemi; jiný index se tam nezřizuje, takže spojení
     * skončí chybou nebo — hůř — v cizí databázi.
     */
    public static function databaseWarning(Config $config): ?string
    {
        if (!(bool) $config->get('app.managed', false)) {
            return null;
        }

        $db = (int) $config->get('redis.db', self::MANAGED_DB);
        if ($db === self::MANAGED_DB) {
            return null;
        }

        return sprintf(
            'redis.db = %d, ale spravovaný hosting dává všem instancím db %d a odlišuje je prefixem.',
            $db,
            self::MANAGED_DB,
        );
    }
}
