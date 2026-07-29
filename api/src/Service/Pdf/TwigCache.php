<?php

declare(strict_types=1);

namespace MyInvoice\Service\Pdf;

use MyInvoice\Infrastructure\Config\RuntimePaths;

/**
 * Společné nastavení kompilační cache Twigu pro PDF renderery.
 *
 * Bez cache Twig při každém renderu šablonu znovu lexuje, parsuje, kompiluje
 * a spouští přes `eval()`. Se zapnutou cache se zkompilovaná šablona uloží na
 * disk a načte přes `include`, takže ji chytne i OPcache.
 *
 * Nepatří do Redisu — je to lokální soubor, ne sdílený stav. Cache je obsahově
 * adresovaná (Twig si klíčuje sám podle jména a zdroje šablony + verze Twigu),
 * takže nemá TTL ani invalidaci; smazání adresáře je vždy bezpečné.
 */
final class TwigCache
{
    /**
     * Volby do konstruktoru `Twig\Environment`.
     *
     * `auto_reload` nastavujeme EXPLICITNĚ — jinak si ho Twig odvodí od `debug`,
     * které tu nikde nezapínáme, a vývojář by po editaci šablony viděl pořád
     * starou zkompilovanou verzi.
     *
     * @return array{cache:string|false,auto_reload:bool}
     */
    public static function options(string $namespace): array
    {
        $dir = RuntimePaths::storage('cache/twig/' . $namespace);

        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            // Neexistuje a nejde vyrobit (read-only FS, práva) — jeď bez cache.
            return ['cache' => false, 'auto_reload' => true];
        }

        return [
            'cache'       => $dir,
            'auto_reload' => self::isDev(),
        ];
    }

    private static function isDev(): bool
    {
        $env = getenv('MYINVOICE_APP_ENV');
        return !is_string($env) || $env === '' || $env !== 'production';
    }
}
