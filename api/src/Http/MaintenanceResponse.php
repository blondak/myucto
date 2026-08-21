<?php

declare(strict_types=1);

namespace MyInvoice\Http;

/**
 * Tělo 503 odpovědi při zapnutém zámku údržby ({@see \MyInvoice\Service\System\MaintenanceLock}).
 *
 * Vytažené sem, protože stejnou odpověď musí umět DVĚ místa:
 *   - {@see \MyInvoice\Middleware\MaintenanceModeMiddleware} pro Slim pipeline,
 *   - inline brána v `api/public/index.php` pro SPA fallback, který se do Slim
 *     pipeline vůbec nedostane (front controller vydává `web/dist/index.html`
 *     ještě před `Bootstrap::buildApp()`).
 *
 * Bez sdílení by se ty dvě větve rozešly a přesně to je past, kterou tenhle
 * modul má zavřít: v údržbě nesmí ze SPA fallbacku vzniknout bílá stránka ani
 * 500, ale čitelná stránka pro člověka a JSON pro API klienta.
 */
final class MaintenanceResponse
{
    public const CODE = 'maintenance';

    private function __construct() {}

    /**
     * Chce volající JSON? Rozhoduje cesta (API prefix) a `Accept`.
     *
     * `Accept: * / *` (curl, wget, healthcheck bez hlavičky) na neAPI cestě
     * záměrně dostane HTML — je to požadavek na stránku, ne na API.
     */
    public static function wantsJson(string $path, string $accept): bool
    {
        return str_starts_with($path, '/api/')
            || $path === '/api'
            || stripos($accept, 'application/json') !== false;
    }

    public static function json(string $message): string
    {
        return (string) json_encode(
            ['error' => ['code' => self::CODE, 'message' => $message]],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );
    }

    /**
     * Statická stránka bez jediného odkazu na build frontendu — v údržbě je
     * `web/dist/` obecně nedostupný nebo právě přepisovaný.
     */
    public static function html(string $message, int $retryAfter): string
    {
        $refresh = max(5, min(300, $retryAfter));

        return '<!doctype html><html lang="cs"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<meta http-equiv="refresh" content="' . $refresh . '">'
            . '<title>Probíhá údržba</title>'
            . '<style>body{font:14px/1.6 system-ui,sans-serif;max-width:560px;margin:80px auto;'
            . 'padding:0 20px;color:#15131D;background:#fff}'
            . 'h1{color:#3B2D83;font-size:22px}'
            . '@media (prefers-color-scheme:dark){body{background:#15131D;color:#EDEBF2}'
            . 'h1{color:#B9AEE8}}</style></head><body>'
            . '<h1>Probíhá údržba</h1><p>' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p>'
            . '<p>Stránka se sama obnoví.</p></body></html>';
    }
}
