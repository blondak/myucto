<?php

declare(strict_types=1);

namespace MyInvoice\Service\Update;

/**
 * Značka „aplikace se právě přepisuje" pro okno swapu a migrací.
 *
 * PROČ: {@see NativeUpdateService} vyměňuje přes deset tisíc souborů in-place
 * nad běžící instalací a hned poté posouvá schéma databáze. Celé to okno trvá
 * jednotky minut a po tu dobu je instalace vnitřně nekonzistentní — nový
 * `Config.php` už odkazuje na třídu, jejíž soubor ještě nedorazil, nový
 * middleware chce službu, kterou starý kontejner nezná, a nový kód se ptá na
 * tabulku, kterou založí až migrace. Každý request, který do toho okna spadne,
 * skončí fatálem a uživateli se ukáže „Backend selhal při startu".
 *
 * Řešení je bránit se dřív, než se vůbec začne autoloadovat: přítomnost tohohle
 * souboru znamená 503 „probíhá aktualizace". Čtenář je schválně inline v
 * `api/public/index.php` a nesmí sahat na žádnou třídu — kdyby brána sama
 * potřebovala autoloader, selhala by přesně tam, kde má chránit.
 *
 * Značka MÁ EXPIRACI, a to je funkční požadavek: kdyby worker spadl uprostřed
 * swapu (kill, výpadek proudu), zůstal by soubor ležet a instalace by hlásila
 * údržbu navěky. Po expiraci brána mlčí a případný rozbitý stav se projeví
 * normální chybou, kterou už je vidět v logu.
 *
 * Formát i cestu sdílí s předchůdcem. Instalace přicházející z MyInvoice.cz
 * zakládá tutéž značku ještě svým kódem, pak swap vymění index.php za tenhle —
 * a ten musí okno dodržet až do konce migrací, které běží dlouho po swapu.
 * Proto se tu nesmí měnit ani jméno souboru, ani klíče, aniž by se to změnilo
 * i v MyInvoice.
 */
final class MaintenanceMode
{
    /** Jméno značky ve `storage/`. Sdílené s MyInvoice — viz PHPDoc třídy. */
    public const FILE = 'maintenance.json';

    /**
     * Po jak dlouhém tichu se značka ignoruje — odpověď na otázku „kdy je
     * běžící nasazení nejspíš mrtvé". Drž shodně s MyInvoice, jinak by se
     * okno přechodu zavřelo dřív, než ho nástupce převezme.
     */
    public const TTL_SECONDS = 1800;

    private function __construct() {}

    public static function path(string $stateDir): string
    {
        return rtrim($stateDir, '/\\') . '/storage/' . self::FILE;
    }

    /**
     * Zapíše (nebo prodlouží) značku. Volá se před swapem a znovu při každém
     * hlášení průběhu, aby dlouhý přechod nepropadl expirací zrovna uprostřed.
     */
    public static function begin(string $stateDir, string $product, string $target): void
    {
        $path = self::path($stateDir);
        $dir  = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $now      = time();
        $existing = self::read($path);

        @file_put_contents($path, json_encode([
            'reason'     => 'update',
            'product'    => $product,
            'target'     => $target,
            'started_at' => $existing['started_at'] ?? date(\DateTimeInterface::ATOM, $now),
            'expires_at' => date(\DateTimeInterface::ATOM, $now + self::TTL_SECONDS),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    /**
     * Uklidí značku. Volá se ve `finally` — i update, který skončil chybou,
     * musí instalaci vrátit do provozu, jinak ji drží dole až do expirace.
     */
    public static function end(string $stateDir): void
    {
        @unlink(self::path($stateDir));
    }

    /** @return array<string,mixed>|null */
    private static function read(string $path): ?array
    {
        $raw = @file_get_contents($path);
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $data = json_decode($raw, true);

        return is_array($data) ? $data : null;
    }
}
