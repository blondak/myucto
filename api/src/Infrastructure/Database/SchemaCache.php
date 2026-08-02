<?php

declare(strict_types=1);

namespace MyInvoice\Infrastructure\Database;

use Throwable;

/**
 * Sdílená cache výsledků `Connection::hasTable()` / `hasColumn()` mezi requesty.
 *
 * PROČ: feature-detekce schématu se ptá `information_schema`, a ta je řádově dražší
 * než běžný indexovaný dotaz — naměřeno na dev DB:
 *
 *     information_schema.COLUMNS   2,449 ms   (2× na request)
 *     information_schema.TABLES    0,113 ms   (4× na request)
 *     běžný indexovaný SELECT      0,15  ms
 *
 * Dohromady ~5,35 ms na KAŽDÝ API request u dat, která se mezi migracemi nemůžou
 * změnit. `Connection` už si výsledky pamatuje v rámci jednoho requestu; tohle je
 * ta chybějící vrstva mezi requesty.
 *
 * PROČ SOUBOR A NE REDIS: schéma je pár set bajtů a mění se jen při migraci.
 * Načtení malého JSONu stojí 0,127 ms, což je méně než round-trip na Redis — a
 * hlavně to nezavádí závislost na komponentě, která je v aplikaci volitelná
 * (`redis.enabled` je default false). Redis by tady byl pomalejší i křehčí.
 *
 * INVALIDACE, dvě nezávislé cesty:
 *   1) explicitně — `bin/migrate.php` po aplikaci migrací zavolá {@see invalidate()},
 *      což je normální a spolehlivá cesta,
 *   2) TTL — pojistka pro případ, že někdo sáhne do schématu ručně mimo migrace.
 *      Bez ní by taková změna zůstala neviditelná navždy.
 *
 * Soubor je klíčovaný jménem databáze: testovací a ostrá DB si nesmí cache míchat.
 */
final class SchemaCache
{
    private const FORMAT = 1;

    /** @var array<string,bool>|null */
    private ?array $entries = null;
    private bool $dirty = false;
    private bool $loadFailed = false;

    public function __construct(
        private readonly string $path,
        private readonly string $database,
        private readonly int $ttlSeconds = 300,
    ) {}

    /**
     * Vrátí cestu k souboru cache pro danou databázi, nebo null když persistence
     * není možná (není kam psát).
     */
    public static function pathFor(?string $baseDir, string $database): ?string
    {
        if ($baseDir === null || trim($baseDir) === '' || trim($database) === '') {
            return null;
        }
        $dir = rtrim($baseDir, "\\/") . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'cache';
        // Jméno DB do názvu souboru — jen bezpečné znaky, ať se z něj nedá vyrobit cesta.
        $safe = preg_replace('/[^A-Za-z0-9_-]/', '_', $database) ?? 'db';

        return $dir . DIRECTORY_SEPARATOR . 'schema-' . $safe . '.json';
    }

    /**
     * Známý výsledek pro klíč, nebo null když ho cache nemá.
     */
    public function get(string $key): ?bool
    {
        $entries = $this->entries();

        return array_key_exists($key, $entries) ? $entries[$key] : null;
    }

    public function put(string $key, bool $value): void
    {
        $entries = $this->entries();
        if (array_key_exists($key, $entries) && $entries[$key] === $value) {
            return;
        }
        $this->entries[$key] = $value;
        $this->dirty = true;
    }

    /**
     * Zapíše cache na disk, pokud se od načtení něco změnilo.
     *
     * Volá se na konci requestu (shutdown handler v {@see Connection}), ne po každém
     * zápisu — jinak by první request po invalidaci zapisoval šestkrát za sebou.
     *
     * ⚠️ SLUČUJE, nepřepisuje. Každý endpoint se ptá na jinou podmnožinu schématu:
     * `/api/auth/me` na jednu, dashboard na jinou. Kdyby request zapsal jen to, co
     * sám objevil, sebral by cache klíče, na které se zrovna neptal — a další
     * request by je musel znovu vytáhnout z information_schema. Dvojice requestů
     * s různými potřebami by si tak cache donekonečna přepisovala a celá
     * optimalizace by se rozpadla. Slučování zároveň řeší souběh: dva requesty
     * píšící naráz o sebe nepřijdou.
     */
    public function flush(): void
    {
        if (!$this->dirty || $this->entries === null || $this->loadFailed) {
            return;
        }

        try {
            $dir = dirname($this->path);
            if (!is_dir($dir) && !@mkdir($dir, 0o775, true) && !is_dir($dir)) {
                return;
            }

            // Znovu načti aktuální obsah — mezitím ho mohl doplnit jiný request.
            // Naše hodnoty vyhrávají: pocházejí z právě proběhlého dotazu do DB.
            //
            // Čte se PŘES TTL, takže z prošlého souboru se nepřevezme nic. Jinak by
            // se staré klíče při každém zápisu „omladily" na aktuální written_at a
            // TTL by přestalo existovat jako pojistka — schéma změněné mimo migrace
            // by se nikdy neprojevilo.
            $merged = self::readEntries($this->path, $this->database, $this->ttlSeconds);
            foreach ($this->entries as $key => $value) {
                $merged[$key] = $value;
            }
            $this->entries = $merged;

            $payload = json_encode([
                'format'     => self::FORMAT,
                'database'   => $this->database,
                'written_at' => time(),
                'entries'    => $merged,
            ], JSON_UNESCAPED_SLASHES);

            if ($payload === false) {
                return;
            }

            // Atomicky: zápis do dočasného souboru v témže adresáři + rename. Souběžný
            // request tak nikdy nepřečte half-written JSON.
            $tmp = $this->path . '.' . getmypid() . '.tmp';
            if (@file_put_contents($tmp, $payload, LOCK_EX) === false) {
                return;
            }
            if (!@rename($tmp, $this->path)) {
                @unlink($tmp);
                return;
            }
            $this->dirty = false;
        } catch (Throwable) {
            // Cache je optimalizace — její selhání nesmí shodit request.
        }
    }

    /**
     * Zahodí cache. Volá se po migracích.
     */
    public static function invalidate(?string $path): bool
    {
        if ($path === null || !is_file($path)) {
            return false;
        }

        return @unlink($path);
    }

    /** @return array<string,bool> */
    private function entries(): array
    {
        if ($this->entries !== null) {
            return $this->entries;
        }

        try {
            $this->entries = self::readEntries($this->path, $this->database, $this->ttlSeconds);
        } catch (Throwable) {
            $this->entries = [];
            $this->loadFailed = true;
        }

        return $this->entries;
    }

    /**
     * Přečte platné položky ze souboru. Neplatný formát, jiná databáze, prošlé TTL
     * nebo poškozený JSON = prázdné pole, nikdy výjimka — cache je optimalizace.
     *
     * @return array<string,bool>
     */
    private static function readEntries(string $path, string $database, int $ttlSeconds): array
    {
        if (!is_file($path)) {
            return [];
        }
        $raw = @file_get_contents($path);
        if ($raw === false || $raw === '') {
            return [];
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return [];
        }
        if ((int) ($data['format'] ?? 0) !== self::FORMAT) {
            return [];
        }
        if ((string) ($data['database'] ?? '') !== $database) {
            return [];
        }
        if ($ttlSeconds > 0 && time() - (int) ($data['written_at'] ?? 0) > $ttlSeconds) {
            return [];
        }

        $entries = $data['entries'] ?? null;
        if (!is_array($entries)) {
            return [];
        }

        $out = [];
        foreach ($entries as $key => $value) {
            if (is_string($key) && is_bool($value)) {
                $out[$key] = $value;
            }
        }

        return $out;
    }
}
