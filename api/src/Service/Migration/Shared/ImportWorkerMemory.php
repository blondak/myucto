<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Shared;

/**
 * Paměťový strop workeru převodu.
 *
 * Worker startuje webový požadavek, takže na sdíleném hostingu zdědí `PHPRC`
 * a s ním `php.ini` webu: místo limitu příkazové řádky dostane limit určený pro
 * běžné stránky (typicky 256 MB). Převod celé agendy se do něj nevejde a spadne
 * uprostřed. Proto si worker strop zvedá sám, jednotně pro všechny zdroje.
 *
 * Platí jen pro CLI: webový požadavek si limit nezvedá nikdy.
 */
final class ImportWorkerMemory
{
    public const LIMIT = '1024M';

    public static function raise(): void
    {
        if (PHP_SAPI !== 'cli') return;
        $target = self::target((string) ini_get('memory_limit'));
        if ($target !== null) ini_set('memory_limit', $target);
    }

    /**
     * Nový limit, nebo null, když se stávající nemá měnit: je neomezený,
     * nečitelný, nebo už aspoň tak vysoký.
     */
    public static function target(string $current): ?string
    {
        $bytes = self::bytes($current);
        return $bytes !== null && $bytes < self::bytes(self::LIMIT) ? self::LIMIT : null;
    }

    private static function bytes(string $limit): ?int
    {
        if (preg_match('/^([1-9]\d*)([KMG]?)$/iD', $limit, $match) !== 1) return null;
        return (int) $match[1] * match (strtoupper($match[2])) {
            'G' => 1073741824, 'M' => 1048576, 'K' => 1024, default => 1,
        };
    }
}
