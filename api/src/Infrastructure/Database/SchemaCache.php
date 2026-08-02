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

            $payload = json_encode([
                'format'     => self::FORMAT,
                'database'   => $this->database,
                'written_at' => time(),
                'entries'    => $this->entries,
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

        $this->entries = [];

        try {
            if (!is_file($this->path)) {
                return $this->entries;
            }
            $raw = @file_get_contents($this->path);
            if ($raw === false || $raw === '') {
                return $this->entries;
            }
            $data = json_decode($raw, true);
            if (!is_array($data)) {
                return $this->entries;
            }

            // Nesouhlasící formát nebo databáze → ignoruj (přepíše se novým zápisem).
            if ((int) ($data['format'] ?? 0) !== self::FORMAT) {
                return $this->entries;
            }
            if ((string) ($data['database'] ?? '') !== $this->database) {
                return $this->entries;
            }
            if ($this->ttlSeconds > 0 && time() - (int) ($data['written_at'] ?? 0) > $this->ttlSeconds) {
                return $this->entries;
            }

            $entries = $data['entries'] ?? null;
            if (!is_array($entries)) {
                return $this->entries;
            }
            foreach ($entries as $key => $value) {
                if (is_string($key) && is_bool($value)) {
                    $this->entries[$key] = $value;
                }
            }
        } catch (Throwable) {
            $this->loadFailed = true;
        }

        return $this->entries;
    }
}
