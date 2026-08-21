<?php

declare(strict_types=1);

namespace MyInvoice\Service\System;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Config\RuntimePaths;

/**
 * Zámek údržby spravované (SaaS) instalace — H-03.
 *
 * ⚠️ Kontrakt je DOHODNUTÝ S PROVOZOVATELEM hostingu, nikoli náš návrh. Nic
 * z následujícího se nesmí „vylepšit" jednostranně:
 *
 *  - Soubor je `${MYINVOICE_DATA_DIR}/storage/maintenance.lock`, tedy vedle
 *    `cfg.local.php` a mimo veřejný datový prostor. Cesta je konfigurovatelná
 *    (`maintenance.lock_file`), protože provozovatel nebude sahat do vhostu.
 *  - Zakládá ho PROVOZOVATEL, ne aplikace. Vlastník je systémový účet instance,
 *    práva 0644, obsah je VOLITELNÝ JSON `{"reason","since","by"}`.
 *  - Prázdný soubor tedy stačí. Kód proto nesmí na obsahu nic stavět a nesmí
 *    spadnout na nevalidním JSONu — {@see details()} v takovém případě mlčky
 *    vrátí samé null.
 *  - Odstranění souboru = konec údržby, okamžitě a bez restartu čehokoli. Proto
 *    se stav NIKDY nekešuje mezi requesty ani v rámci procesu a před každým
 *    dotazem se zahazuje stat cache i realpath cache (ta si pamatuje i NEúspěch,
 *    takže bez `clearstatcache()` by čerstvě založený zámek chvíli „neexistoval").
 *
 * Tahle třída je jen ČTENÍ zámku. Vynucení má dvě vrstvy:
 *   - {@see \MyInvoice\Middleware\MaintenanceModeMiddleware} pro Slim pipeline,
 *   - inline brána v `api/public/index.php` pro SPA fallback, který se do Slim
 *     pipeline vůbec nedostane.
 * `.htaccess` / `web.config` jsou třetí, čistě zrychlovací vrstva — nikdy ne
 * bezpečnostní hranice.
 */
final class MaintenanceLock
{
    /** Jméno značky ve `storage/`, když `maintenance.lock_file` není vyplněné. */
    public const DEFAULT_FILENAME = 'maintenance.lock';

    /** Fallback pro `maintenance.retry_after`, shodný s defaultem v Config. */
    public const DEFAULT_RETRY_AFTER = 300;

    /** Retry-After mimo tenhle rozsah je překlep, ne konfigurace. */
    private const MIN_RETRY_AFTER = 1;
    private const MAX_RETRY_AFTER = 86400;

    /** Obsah je volitelná diagnostika — číst víc než tohle nemá smysl. */
    private const MAX_READ_BYTES = 8192;

    /** Kolik znaků z `reason` se smí objevit ve veřejné 503 hlášce. */
    private const MAX_REASON_CHARS = 200;

    public const MESSAGE = 'Probíhá plánovaná údržba instalace. Zkuste to prosím za chvíli.';

    public function __construct(private readonly Config $config) {}

    /**
     * Absolutní cesta k zámku.
     *
     * Relativní hodnota v konfiguraci se vztahuje k datovému kořeni, NE k CWD:
     * web běží z docrootu, cron z čehokoli, a údržba nesmí platit jen pro jeden
     * z nich.
     */
    public function path(): string
    {
        $configured = $this->config->get('maintenance.lock_file', '');
        $configured = is_string($configured) ? trim($configured) : '';

        if ($configured === '') {
            return RuntimePaths::storage(self::DEFAULT_FILENAME);
        }

        return self::isAbsolute($configured)
            ? $configured
            : RuntimePaths::base() . '/' . ltrim($configured, '/\\');
    }

    /** Je instalace právě v údržbě? */
    public function isActive(): bool
    {
        $path = $this->path();
        // Druhý argument čistí i realpath cache pro tenhle jeden soubor —
        // bez toho by odstranění zámku „platilo" až po vypršení TTL cache.
        clearstatcache(true, $path);

        return @is_file($path);
    }

    /** Hodnota hlavičky `Retry-After` pro 503 odpovědi. */
    public function retryAfter(): int
    {
        $value = filter_var(
            $this->config->get('maintenance.retry_after', self::DEFAULT_RETRY_AFTER),
            FILTER_VALIDATE_INT,
        );
        if ($value === false) {
            return self::DEFAULT_RETRY_AFTER;
        }

        return max(self::MIN_RETRY_AFTER, min(self::MAX_RETRY_AFTER, $value));
    }

    /**
     * Volitelný obsah zámku. Prázdný soubor, nečitelný soubor i nevalidní JSON
     * jsou legitimní stavy — vrací se samé null a NIKDY se nevyhazuje výjimka.
     *
     * @return array{reason:?string,since:?string,by:?string}
     */
    public function details(): array
    {
        $empty = ['reason' => null, 'since' => null, 'by' => null];

        $raw = @file_get_contents($this->path(), false, null, 0, self::MAX_READ_BYTES);
        if (!is_string($raw) || trim($raw) === '') {
            return $empty;
        }

        try {
            $data = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $empty;
        }
        if (!is_array($data)) {
            return $empty;
        }

        return [
            'reason' => self::scalarString($data['reason'] ?? null),
            'since'  => self::scalarString($data['since'] ?? null),
            'by'     => self::scalarString($data['by'] ?? null),
        ];
    }

    /**
     * Text pro veřejnou 503 odpověď. `reason` je text provozovatele určený
     * koncovým uživatelům — proto se propouští ven, ale osekaný a zbavený
     * řídicích znaků. Escapování pro cílový formát dělá až renderer.
     */
    public function message(): string
    {
        $reason = $this->details()['reason'];
        if ($reason === null) {
            return self::MESSAGE;
        }

        return self::MESSAGE . ' ' . $reason;
    }

    private static function scalarString(mixed $value): ?string
    {
        if (is_int($value) || is_float($value)) {
            $value = (string) $value;
        }
        if (!is_string($value)) {
            return null;
        }

        // Řídicí znaky (včetně CR/LF) by se propsaly do hlaviček i do HTML.
        $clean = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value));
        if ($clean === '') {
            return null;
        }

        return mb_substr($clean, 0, self::MAX_REASON_CHARS);
    }

    private static function isAbsolute(string $path): bool
    {
        return preg_match('~^(?:[A-Za-z]:[\\\\/]|[\\\\/])~', $path) === 1;
    }
}
