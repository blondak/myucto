<?php

declare(strict_types=1);

/**
 * MyÚčto.cz — cron-license-renew (E4)
 *
 * Denní obnova licenčního tokenu proti licenčnímu serveru. Doplněk k obnově,
 * kterou spouští i první přihlášený request dne (LicenseMiddleware) — cron
 * pokrývá instalace, které přes den nikdo neotevře. Mutex uvnitř LicenseService
 * (atomický UPDATE dle CURDATE) zajistí, že obnova proběhne max. 1× denně.
 *
 * Plánování:
 *   - Linux/cron:        0 5 * * *  cd /path && php api/bin/cron-license-renew.php
 *   - Docker:            stejně, přes `docker compose exec app …`
 *   - Windows/Scheduler: denně, akce: php.exe api\bin\cron-license-renew.php
 *
 * Idempotentní — opakované spuštění téhož dne je no-op (síťovou chybu jen loguje).
 */

require __DIR__ . '/../vendor/autoload.php';

if (!defined('STDERR')) {
    define('STDERR', fopen('php://stderr', 'wb'));
}

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Cron\CronRun;
use MyInvoice\Service\License\LicenseClient;
use MyInvoice\Service\License\LicenseService;
use MyInvoice\Service\License\LicenseTokenVerifier;

$rootDir = Bootstrap::rootDir();
$config  = Config::load($rootDir);
$conn    = new Connection($config);

$run = CronRun::start($conn->pdo(), 'cron-license-renew');

$service = new LicenseService($conn, $config, new LicenseTokenVerifier(), new LicenseClient($config));

try {
    $service->renewIfDue();
    $state = $service->current();
    echo "[cron-license-renew] OK state={$state->state} last_check_ok=" . ($state->lastCheckOk ? '1' : '0') . "\n";
    $run->finish('ok', [
        'state'         => $state->state,
        'valid_until'   => $state->validUntil,
        'last_check_ok' => $state->lastCheckOk,
    ]);
} catch (\Throwable $e) {
    fwrite(STDERR, "[cron-license-renew] FAILED: {$e->getMessage()}\n");
    $run->finish('error', ['error' => $e->getMessage()], $e->getMessage(), 1);
    exit(1);
}
