<?php

declare(strict_types=1);

namespace MyInvoice\Infrastructure\Database;

use MyInvoice\Infrastructure\Cache\EntityCache;
use Throwable;

/**
 * Most mezi PDO vrstvou a {@see EntityCache}.
 *
 * Sedí co nejníž schválně: každý zápis do databáze projde přes `PDO::exec()`,
 * `PDO::query()` nebo `PDOStatement::execute()`, takže invalidaci cache nejde
 * obejít ani na ni zapomenout. Alternativa — invalidovat ručně na každém místě,
 * kde se zapisuje uživatel — by znamenala udržovat seznam desítek call sites
 * a spoléhat, že na žádné další nikdo nezapomene.
 *
 * Registr je statický, protože PDO instancuje `LoggingPdoStatement` samo a
 * protáhnout do něj závislost jde jen přes `ATTR_STATEMENT_CLASS` argumenty.
 * Stav je vázaný na proces (= request), ne na uživatele.
 */
final class WriteWatcher
{
    private static ?EntityCache $cache = null;

    /** Zapnuto až když někdo zaregistruje cache — bez ní se nic nedetekuje. */
    public static function attach(?EntityCache $cache): void
    {
        self::$cache = $cache;
    }

    public static function detach(): void
    {
        self::$cache = null;
    }

    public static function isAttached(): bool
    {
        return self::$cache !== null;
    }

    /**
     * Zavolej po KAŽDÉM vykonaném SQL. Čtecí příkazy odfiltruje
     * {@see EntityCache::groupsForSql()} hned na prvním regexu.
     */
    public static function noteStatement(string $sql): void
    {
        $cache = self::$cache;
        if ($cache === null) {
            return;
        }

        try {
            foreach (EntityCache::groupsForSql($sql) as $group) {
                $cache->invalidateGroup($group);
            }
        } catch (Throwable) {
            // Invalidace nikdy nesmí shodit zápis, který už proběhl.
        }
    }
}
