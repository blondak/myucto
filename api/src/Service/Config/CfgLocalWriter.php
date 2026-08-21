<?php

declare(strict_types=1);

namespace MyInvoice\Service\Config;

use MyInvoice\Infrastructure\Config\Config;

/**
 * Atomický merge a zápis do `cfg.local.php`.
 *
 * `cfg.local.php` je gitignored a `Config::load()` ho slévá přes `cfg.php`
 * pomocí `array_replace_recursive`. Hodí se pro per-instance overrides,
 * které se nastavují přes UI (instalační wizard, admin) a které nemá smysl
 * tlačit do hlavního `cfg.php` (zejména v Dockeru, kde je `cfg.php` jen
 * stub a všechno citlivé jde přes ENV).
 *
 * **Cílový adresář**: pokud je nastavena ENV `MYINVOICE_DATA_DIR` (single-volume
 * Docker layout od 3.6.0), `cfg.local.php` se zapisuje TAM — Config::load()
 * ho odtud i čte. V opačném případě (lokální dev, hostingy bez DATA_DIR) se
 * zapisuje do `rootDir`. Helper {@see resolveTargetDir()} tuto volbu sjednocuje.
 *
 * Použití (manuální cesta):
 *   CfgLocalWriter::setKeys('/var/www/html', ['auth.require_totp' => true]);
 *
 * Použití (auto-detect mezi rootDir a DATA_DIR):
 *   $dir = CfgLocalWriter::resolveTargetDir($rootDir);
 *   CfgLocalWriter::setKeys($dir, [...]);
 *
 * Bezpečnost — na spravovaném hostingu leží v tomhle souboru VŠECHNA tajemství
 * instance (`app.pepper`, `app.secret_encryption_key`, `app.payroll_hash_key`,
 * heslo k DB, SMTP). Poškozený zápis = mrtvá instance a nedešifrovatelná záloha.
 * Proto platí:
 *   - Zápis je **atomický**: obsah jde do dočasného souboru ve **stejném adresáři**
 *     (rename() nepřechází hranice svazku) a teprve `rename()` ho zveřejní.
 *     Selže-li cokoli dřív, na disku zůstane původní soubor beze změny a dočasný
 *     soubor se uklidí. Zámek (LOCK_EX) je tím zbytečný — čtenář vidí vždy jen
 *     starou, nebo celou novou verzi.
 *   - **Práva se dědí**: existující soubor si drží svoje `fileperms()`, nový vzniká
 *     jako `0600`. Mód se nastaví dočasnému souboru ještě PŘED `rename()`, aby
 *     tajemství ani na okamžik neležela s výchozími právy. Vlastníka neměníme —
 *     `rename()` ho zachová, protože dočasný soubor zakládá tentýž účet.
 *   - **Nic z obsahu se nikdy nedostane do výjimky.** `ParseError` z poškozeného
 *     souboru cituje kus zdrojáku (tedy potenciálně hodnotu klíče), takže se
 *     nepředává dál ani jako zpráva, ani jako `previous`. Výjimka nese jen cestu,
 *     typ chyby a případně jméno klíče.
 *   - **Poškozený vstup soubor nepřepíše.** Když existující `cfg.local.php` nejde
 *     načíst nebo nevrací pole, letí výjimka a soubor zůstane ležet — jinak by se
 *     tajemství přepsala prázdným polem.
 *   - var_export má zaručený PHP-readable výstup (round-trip snese vnořená pole,
 *     UTF-8, `'`, `\`, `$` i `\0` uvnitř řetězců), ale ztrácí komentáře
 *     v existujícím souboru. Pro hluboké manuální úpravy doporučujeme
 *     editovat `cfg.php` přímo.
 */
final class CfgLocalWriter
{
    /** Práva nově zakládaného cfg.local.php (obsahuje tajemství instance). */
    private const DEFAULT_MODE = 0600;

    /**
     * Vrací adresář, kam má jít `cfg.local.php` — preferuje `MYINVOICE_DATA_DIR`
     * (persistentní volume v Dockeru), fallback na `rootDir`.
     *
     * Pro single-volume Docker layout (default od 3.6.0) je nutné, aby per-instance
     * konfigurace přežila image update — proto musí ležet ve volumu, ne v image.
     */
    public static function resolveTargetDir(string $rootDir): string
    {
        return Config::resolveDataDir() ?? $rootDir;
    }

    /**
     * Nastaví hodnoty (dot notation klíče) v cfg.local.php a zapíše soubor.
     *
     * Tečka se rozpadá **jen v klíči**, ne v hodnotě: `['app.url' => 'https://a.b']`
     * uloží `['app' => ['url' => 'https://a.b']]`. Důsledek: klíč, který má tečku
     * ve svém jménu (`['a.b' => 1]` jako doslovný top-level klíč), se přes `setKeys()`
     * nastavit NEDÁ — vždy vznikne zanoření. Existující doslovné tečkové klíče
     * v souboru se ale zápisem neztratí, jen jsou touhle cestou neadresovatelné.
     *
     * @param string                $rootDir  Absolutní cesta k repo rootu (kde leží cfg.php).
     * @param array<string,mixed>   $keys     Mapa "a.b.c" => hodnota.
     */
    public static function setKeys(string $rootDir, array $keys): void
    {
        $path = rtrim($rootDir, '/\\') . DIRECTORY_SEPARATOR . 'cfg.local.php';

        $existing = self::readExisting($path);

        foreach ($keys as $dotted => $value) {
            $existing = self::setByPath($existing, (string) $dotted, $value);
        }

        // Hotový obsah souboru se ZÁMĚRNĚ nikam nepředává jako argument — argumenty
        // se objevují ve stack trace (getTraceAsString stringy tiskne, byť zkrácené),
        // zatímco pole se renderuje jako "Array". Renderuje se až uvnitř writeAtomic().
        self::writeAtomic($path, $existing);
    }

    /**
     * Vyrenderuje obsah souboru. Bere pole (ne string), aby se hotový text
     * s tajemstvími nikdy neocitl mezi argumenty ve stack trace.
     *
     * @param array<mixed,mixed> $data
     */
    private static function render(array $data): string
    {
        self::assertExportable($data, '');

        $exported = var_export($data, true);

        return "<?php\n\n"
            . "// cfg.local.php — per-instance overrides (gitignored).\n"
            . "// Config::load() merguje tento soubor přes cfg.php pomocí array_replace_recursive.\n"
            . "// Soubor automaticky generuje setup wizard (CfgLocalWriter); ručně lze editovat.\n\n"
            . "return {$exported};\n";
    }

    /**
     * Načte existující cfg.local.php. Cokoli jiného než pole = výjimka, NIKDY
     * tichý fallback na `[]` (přepsal by tajemství instance prázdnem).
     *
     * @return array<mixed,mixed>
     */
    private static function readExisting(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }

        self::invalidateOpcache($path);

        $level = ob_get_level();
        try {
            // Buffer chytí případný výstup poškozeného souboru, ať neteče do odpovědi.
            ob_start();
            /** @psalm-suppress UnresolvableInclude */
            $loaded = @include $path;
        } catch (\Throwable $e) {
            // ZÁMĚRNĚ bez zprávy i bez `previous`: ParseError cituje doslovný kus
            // zdrojáku, takže by v logu skončila hodnota klíče (třeba pepper).
            throw new \RuntimeException(sprintf(
                'cfg.local.php na %s nelze načíst (%s); soubor zůstal nezměněn.',
                $path,
                self::describeThrowable($e),
            ));
        } finally {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
        }

        if (!is_array($loaded)) {
            throw new \RuntimeException(sprintf(
                'cfg.local.php na %s existuje, ale nevrací pole; soubor zůstal nezměněn.',
                $path,
            ));
        }

        return $loaded;
    }

    /**
     * Ověří, že celá struktura přežije var_export → require. Objekty, resource,
     * closure, INF a NAN by vyrobily soubor, který se nedá načíst — a přepsaly by
     * tím tajemství nenávratně.
     *
     * @param array<mixed,mixed> $data
     */
    private static function assertExportable(array $data, string $prefix): void
    {
        foreach ($data as $key => $value) {
            $keyPath = $prefix === '' ? (string) $key : $prefix . '.' . $key;

            if (is_array($value)) {
                self::assertExportable($value, $keyPath);
                continue;
            }
            if ($value === null || is_bool($value) || is_int($value) || is_string($value)) {
                continue;
            }
            if (is_float($value) && is_finite($value)) {
                continue;
            }

            // Do zprávy jde jen jméno klíče a typ — nikdy hodnota.
            throw new \RuntimeException(sprintf(
                'cfg.local.php: hodnota klíče "%s" je typu %s a nelze ji bezpečně zapsat; soubor zůstal nezměněn.',
                $keyPath,
                get_debug_type($value),
            ));
        }
    }

    /**
     * Atomický zápis: dočasný soubor ve stejném adresáři → práva → fsync → rename.
     * Při jakémkoli selhání zůstane cíl beze změny a dočasný soubor se smaže.
     *
     * @param array<mixed,mixed> $data
     */
    private static function writeAtomic(string $path, array $data): void
    {
        $contents = self::render($data);
        $dir      = \dirname($path);
        $mode     = self::targetMode($path);

        $tmp = @tempnam($dir, 'cfgl');
        if (!is_string($tmp) || $tmp === '') {
            throw new \RuntimeException(sprintf(
                'cfg.local.php: nelze vytvořit dočasný soubor v %s (zkontroluj práva adresáře); soubor zůstal nezměněn.',
                $dir,
            ));
        }

        // tempnam() umí při nezapisovatelném adresáři tiše spadnout do systémového
        // TEMP — odtud by rename() šel přes hranici svazku a přestal být atomický.
        if (!self::pathsEqual(\dirname($tmp), $dir)) {
            @unlink($tmp);
            throw new \RuntimeException(sprintf(
                'cfg.local.php: dočasný soubor nevznikl v %s (adresář není zapisovatelný); soubor zůstal nezměněn.',
                $dir,
            ));
        }

        // Zápis je schválně inline (a ne v pomocné metodě), aby $contents nefiguroval
        // jako argument žádného rámce ve stack trace.
        try {
            $fh = @fopen($tmp, 'wb');
            if ($fh === false) {
                throw new \RuntimeException(sprintf(
                    'cfg.local.php: nelze otevřít dočasný soubor pro %s; soubor zůstal nezměněn.',
                    $path,
                ));
            }

            try {
                $written = @fwrite($fh, $contents);
                if ($written === false || $written !== strlen($contents)) {
                    throw new \RuntimeException(sprintf(
                        'cfg.local.php: dočasný soubor pro %s se nepodařilo zapsat celý (došlo místo?); soubor zůstal nezměněn.',
                        $path,
                    ));
                }
                if (@fflush($fh) === false) {
                    throw new \RuntimeException(sprintf(
                        'cfg.local.php: dočasný soubor pro %s nelze vyprázdnit na disk; soubor zůstal nezměněn.',
                        $path,
                    ));
                }
                // fsync je best-effort — na některých FS/kontejnerech selže, ale to není
                // důvod odmítnout zápis (data už jsou přes fflush předaná OS).
                if (function_exists('fsync')) {
                    @fsync($fh);
                }
            } finally {
                @fclose($fh);
            }

            // Práva PŘED rename(), ať tajemství ani na okamžik neleží s výchozím módem.
            // chmod je na Windows no-op (mění jen read-only flag) — nesmí spadnout ani varovat.
            @chmod($tmp, $mode);
            self::renameWithRetry($tmp, $path);
        } catch (\Throwable $e) {
            @unlink($tmp);
            throw $e;
        }

        self::invalidateOpcache($path);
    }

    /**
     * rename() umí na Windows transientně selhat, když cíl drží otevřený jiný proces
     * (antivir, indexer, souběžný request). Krátká retry smyčka to překlene;
     * atomicita se tím neztrácí — buď projde celý rename, nebo žádný.
     */
    private static function renameWithRetry(string $tmp, string $path): void
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            if (@rename($tmp, $path)) {
                return;
            }
            if ($attempt < 4) {
                usleep(20000);
            }
        }

        throw new \RuntimeException(sprintf(
            'cfg.local.php nelze atomicky nahradit na %s (zkontroluj práva souboru/adresáře); soubor zůstal nezměněn.',
            $path,
        ));
    }

    /** Práva cíle: zachovej existující, jinak 0600. */
    private static function targetMode(string $path): int
    {
        $perms = @fileperms($path);
        if ($perms === false) {
            return self::DEFAULT_MODE;
        }

        $mode = $perms & 0777;

        return $mode !== 0 ? $mode : self::DEFAULT_MODE;
    }

    /**
     * Porovnání dvou cest. Case-insensitive záměrně na všech platformách —
     * realpath() vrací na Windows nekonzistentní casing (viz AGENTS.md).
     */
    private static function pathsEqual(string $a, string $b): bool
    {
        $ra = realpath($a);
        $rb = realpath($b);
        $a  = $ra !== false ? $ra : $a;
        $b  = $rb !== false ? $rb : $b;

        $normalize = static fn (string $p): string => rtrim(str_replace('\\', '/', $p), '/');

        return strcasecmp($normalize($a), $normalize($b)) === 0;
    }

    /** Popis chyby BEZ její zprávy — ta u ParseError obsahuje kus obsahu souboru. */
    private static function describeThrowable(\Throwable $e): string
    {
        return $e instanceof \ParseError ? 'syntaktická chyba' : get_debug_type($e);
    }

    /**
     * Po přepsání (a před čtením) je potřeba zahodit zkompilovanou verzi z OPcache,
     * jinak by se ještě chvíli četl starý obsah.
     */
    private static function invalidateOpcache(string $path): void
    {
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($path, true);
        }
    }

    /**
     * @param array<mixed,mixed> $data
     * @return array<mixed,mixed>
     */
    private static function setByPath(array $data, string $path, mixed $value): array
    {
        $segments = explode('.', $path);
        $ref      = &$data;
        foreach ($segments as $i => $segment) {
            if ($i === count($segments) - 1) {
                $ref[$segment] = $value;
                break;
            }
            if (!isset($ref[$segment]) || !is_array($ref[$segment])) {
                $ref[$segment] = [];
            }
            $ref = &$ref[$segment];
        }
        return $data;
    }
}
