<?php

declare(strict_types=1);

namespace MyInvoice\Service\Cron;

use MyInvoice\Bootstrap;
use MyInvoice\Service\BackgroundProcess;

/**
 * Produkční launcher — spustí `api/bin/<script>.php` jako odpojený proces
 * a hned se vrátí.
 *
 * Fire-and-forget je tady záměr, ne pohodlnost: dispatcher musí být hotový
 * v řádu desítek milisekund, aby stihl další minutu, a zaseknutá úloha
 * (čekání na SMTP, EPO, banku) nesmí blokovat ostatní. Stav běhu se stejně
 * nesleduje odsud — zapisuje si ho sama úloha přes {@see CronRun}.
 *
 * Log jde do stejného adresáře jako u wrapperů v cmd/ (`<data>/log/cron`),
 * takže diagnostika je v obou režimech plánování na jednom místě.
 */
final class BackgroundCronProcessLauncher implements CronProcessLauncher
{
    public function __construct(private readonly ?string $dataDir = null) {}

    public function launch(string $script, ?string &$error = null): bool
    {
        $root = Bootstrap::rootDir();
        $scriptPath = $root . '/api/bin/' . $script . '.php';
        if (!is_file($scriptPath)) {
            $error = 'script not found: ' . $scriptPath;
            return false;
        }

        $logDir = rtrim($this->dataDir ?? $root, "\\/") . DIRECTORY_SEPARATOR . 'log' . DIRECTORY_SEPARATOR . 'cron';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0o775, true);
        }
        $logPath = $logDir . DIRECTORY_SEPARATOR . $script . '.log';

        return BackgroundProcess::spawnPhp($scriptPath, [], $logPath, $root, $error);
    }
}
