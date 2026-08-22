<?php

declare(strict_types=1);

/**
 * MyÚčto.cz — cron-storage-usage (H-10)
 *
 * Změří spotřebu místa instance (velikost databáze + datový prostor BEZ
 * adresáře záloh) a uloží výsledek do `instance_storage_usage`. `/api/health`,
 * telemetrie i middleware režimu jen pro čtení pak čtou HOTOVÉ číslo a
 * nepočítají ho znovu — strom souborů se prochází výhradně tady.
 *
 * ⚠️ Zálohy se do kvóty NEZAPOČÍTÁVAJÍ — hosting je z ní taky vyjímá. Instalace,
 * která se zamkne vlastními zálohami, je nejtrapnější možná varianta selhání.
 *
 * ⚠️ Dokud měření neproběhne, je spotřeba `null` = NEZMĚŘENO. To není nula
 * a nespouští ani upozornění, ani režim jen pro čtení.
 *
 * Plánování:
 *   - Linux/cron:        15 * * * *  cd /path && php api/bin/cron-storage-usage.php
 *   - Windows/Scheduler: každou hodinu, akce: php.exe api\bin\cron-storage-usage.php
 *
 * Přepínače:
 *   --force   změř i tehdy, když je poslední měření čerstvé
 *   --json    vypiš výsledek jako JSON (pro odečet provozovatelem)
 *
 * Idempotentní — opakované spuštění jen přepíše singleton řádek.
 */

require __DIR__ . '/../vendor/autoload.php';

// STDOUT/STDERR existují jen v CLI SAPI. Úloha jde spustit i z admin UI
// („Plánované úlohy → spustit teď"), kde spawn může skončit pod php-cgi.
if (!defined('STDERR')) {
    define('STDERR', fopen('php://stderr', 'wb'));
}

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Cron\CronRun;
use MyInvoice\Service\System\StorageQuotaPolicy;
use MyInvoice\Service\System\StorageUsageMeter;

$argvList = $argv ?? [];
$force    = in_array('--force', $argvList, true);
$asJson   = in_array('--json', $argvList, true);

$container = Bootstrap::buildContainer();
$conn      = $container->get(Connection::class);

$run = CronRun::start($conn->pdo(), 'cron-storage-usage');

try {
    $meter  = $container->get(StorageUsageMeter::class);
    $policy = $container->get(StorageQuotaPolicy::class);

    [$snapshot, $measured] = $meter->measureIfStale($force);
    $status = $policy->evaluateSnapshot($snapshot);
} catch (Throwable $e) {
    $message = $e->getMessage();
    fwrite(STDERR, "[cron-storage-usage] FAILED: {$message}\n");
    $run->finish('error', ['error' => $message], $message, 1);
    exit(1);
}

$report = [
    'measured'       => $measured,
    'usage_bytes'    => $snapshot->usageBytes,
    'database_bytes' => $snapshot->databaseBytes,
    'files_bytes'    => $snapshot->filesBytes,
    // Diagnostika: kolik zabírají zálohy. Do `usage_bytes` se to NEPOČÍTÁ.
    'backup_bytes'   => $snapshot->backupBytes,
    'file_count'     => $snapshot->fileCount,
    'duration_ms'    => $snapshot->durationMs,
    'truncated'      => $snapshot->truncated,
    'state'          => $status->state->value,
    'percent'        => $status->percent,
];

if ($asJson) {
    echo json_encode($status->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
} else {
    // „?" místo nuly: nezměřená hodnota se nesmí vypsat jako 0 ani v logu —
    // právě z logu se pak odvozuje, jestli je instance prázdná, nebo neměřená.
    $fmt = static fn (?int $bytes): string => $bytes === null
        ? '?'
        : number_format($bytes / 1048576, 1, '.', '') . ' MB';

    echo sprintf(
        "[cron-storage-usage] %s state=%s usage=%s (db=%s, files=%s) backup=%s%s%s\n",
        $measured ? 'OK' : 'SKIP (čerstvé měření)',
        $status->state->value,
        $fmt($snapshot->usageBytes),
        $fmt($snapshot->databaseBytes),
        $fmt($snapshot->filesBytes),
        $fmt($snapshot->backupBytes),
        $status->percent === null ? '' : sprintf(' %.1f %%', $status->percent),
        $snapshot->truncated ? ' [TRUNCATED — dolní odhad]' : '',
    );
}

if ($status->state->warns()) {
    fwrite(STDERR, "[cron-storage-usage] " . $policy->warningMessage($status->percent) . "\n");
}

$run->finish('ok', $report, null, 0, $measured);
