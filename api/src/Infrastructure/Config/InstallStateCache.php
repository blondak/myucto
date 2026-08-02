<?php

declare(strict_types=1);

namespace MyInvoice\Infrastructure\Config;

use MyInvoice\Bootstrap;
use Throwable;

/**
 * Značka „instalace je inicializovaná" (existuje aspoň jeden aktivní uživatel).
 *
 * PROČ: {@see \MyInvoice\Middleware\FirstRunLockMiddleware} je jedna z nejvnějších
 * vrstev, takže se `SELECT COUNT(*) FROM users WHERE is_active = 1` posílal na
 * KAŽDÝ request — včetně 404 a requestů, které by jinak DB vůbec nepotřebovaly.
 * Samotný dotaz je levný (0,15 ms), ale vynutí navázání spojení (2–15 ms naměřeno),
 * a odpovídá na otázku, která se za život instalace změní jednou.
 *
 * ⚠️ CACHUJE SE JEN JEDEN SMĚR. Značka znamená „setup je hotový" a zapisuje se až
 * ve chvíli, kdy to databáze potvrdí. Opačný stav („setup ještě chybí") se
 * necachuje NIKDY — musí se přečíst z DB, protože se překlopí v momentě, kdy si
 * admin založí účet, a zamrzlá „potřebuje setup" by instalaci nechala zamčenou.
 *
 * Zastaralá značka (někdo smazal uživatele mimo aplikaci) není bezpečnostní
 * problém: FirstRunLock pak request pustí dál, ale AuthMiddleware ho stejně
 * odmítne — jediný důsledek je, že uživatel nedostane přesměrování na /setup.
 * `bin/reset.php` značku ruší, takže běžná cesta k prázdné DB ji uklidí sama.
 */
final class InstallStateCache
{
    /**
     * Cesta ke značce, nebo null když není kam psát.
     *
     * Pod PHPUnitem vrací null (pokud si volající cestu nevynutí parametrem):
     * testy si stav instalace nastavují samy a nesmí číst ani přepisovat značku
     * skutečného vývojového stroje — jinak by výsledek testu závisel na tom,
     * jestli na něm někdy proběhl setup.
     */
    public static function markerPath(?string $baseDir = null): ?string
    {
        if ($baseDir === null && defined('PHPUNIT_COMPOSER_INSTALL')) {
            return null;
        }
        $baseDir ??= Config::resolveDataDir() ?? Bootstrap::rootDir();
        if (trim($baseDir) === '') {
            return null;
        }

        return rtrim($baseDir, "\\/")
            . DIRECTORY_SEPARATOR . 'storage'
            . DIRECTORY_SEPARATOR . 'cache'
            . DIRECTORY_SEPARATOR . 'setup-complete.marker';
    }

    public static function isSetupComplete(?string $baseDir = null): bool
    {
        $path = self::markerPath($baseDir);

        return $path !== null && is_file($path);
    }

    public static function markSetupComplete(?string $baseDir = null): void
    {
        $path = self::markerPath($baseDir);
        if ($path === null || is_file($path)) {
            return;
        }

        try {
            $dir = dirname($path);
            if (!is_dir($dir) && !@mkdir($dir, 0o775, true) && !is_dir($dir)) {
                return;
            }
            @file_put_contents($path, (string) time(), LOCK_EX);
        } catch (Throwable) {
            // Značka je optimalizace — když ji nejde zapsat, jen se dál ptáme DB.
        }
    }

    public static function invalidate(?string $baseDir = null): bool
    {
        $path = self::markerPath($baseDir);
        if ($path === null || !is_file($path)) {
            return false;
        }

        return @unlink($path);
    }
}
