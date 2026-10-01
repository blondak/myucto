<?php

declare(strict_types=1);

/**
 * Denní úklid záloh, logů a dočasných souborů, ať úložiště neroste donekonečna.
 *
 * Ve spravovaném provozu (`app.managed`) běží povinně, na self-hostu si ho
 * provozovatel zapíná v cfg (`cron.retention.enabled`). Limity jsou
 * v `cron.retention.*`, výchozí hodnoty a jejich zdůvodnění drží
 * {@see \MyInvoice\Service\System\StorageRetentionPolicy}.
 *
 * Kromě limitů z politiky maže i archivy kompletního exportu po jejich platnosti
 * (`export.instance.ttl_days`).
 *
 * Zálohovací crony si retenci drží dál po svém (30 dnů + měsíční rok); tenhle
 * úklid ji jen zpřísňuje. Doklady, dokumenty, mzdové podklady, účetní archivy
 * ani uzávěrkové balíčky nemaže nikdy.
 *
 * Použití:
 *   php api/bin/cron-retention.php              (běžný běh)
 *   php api/bin/cron-retention.php --dry-run    (jen vypíše, co by smazal)
 *   php api/bin/cron-retention.php --force      (na self-hostu bez cron.retention.enabled)
 */

if (PHP_SAPI !== 'cli') exit("CLI only.\n");
require __DIR__ . '/../vendor/autoload.php';

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Config\RuntimePaths;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Backup\BackupLocation;
use MyInvoice\Service\Cron\CronRun;
use MyInvoice\Service\Export\Instance\InstanceExportJobStore;
use MyInvoice\Service\Export\Instance\InstanceExportService;
use MyInvoice\Service\System\ManagedModeGuard;
use MyInvoice\Service\System\StorageRetentionPolicy;
use MyInvoice\Service\System\StorageRetentionSweeper;

$args   = array_slice($argv, 1);
$dryRun = in_array('--dry-run', $args, true);
$force  = in_array('--force', $args, true);

$rootDir = Bootstrap::rootDir();
$config  = Config::load($rootDir);
$pdo     = (new Connection($config))->pdo();

$run = CronRun::start($pdo, 'cron-retention');

$managed = (new ManagedModeGuard($config))->isManaged();
$enabled = $managed || (bool) $config->get('cron.retention.enabled', false);
if (!$enabled && !$force && !$dryRun) {
    echo "[" . date('Y-m-d H:i:s') . "] cron-retention: vypnuto (cron.retention.enabled), nic se nemaže. Jednorázově lze spustit s --force.\n";
    $run->finish('ok', ['note' => 'disabled'], null, 0, false);
    exit(0);
}

$policy = StorageRetentionPolicy::fromConfig($config);
$logDirs = [
    dirname((string) $config->get('logging.path', RuntimePaths::log('app.log'))),
    RuntimePaths::log(),
    RuntimePaths::log('cron'),
];

$sweeper = new StorageRetentionSweeper(
    $policy,
    BackupLocation::resolve($config),
    (string) $config->get('db.name'),
    $logDirs,
    RuntimePaths::storage(),
    new DateTimeImmutable('now'),
    $dryRun,
);

echo "[" . date('Y-m-d H:i:s') . "] cron-retention" . ($dryRun ? ' (nanečisto)' : '') . ": " . $policy->describe() . "\n";
$report = $sweeper->run();
foreach ($sweeper->deletedPaths() as $path) {
    echo '  - ' . ($dryRun ? 'smazalo by se' : 'smazáno') . ': ' . $path . "\n";
}
foreach ($sweeper->failedPaths() as $path) {
    fwrite(STDERR, "  ✗ nepodařilo se smazat/zkrátit: $path\n");
}
// Archivy kompletního exportu (storage/instance-exports). Platnost má každý svou
// (`export.instance.ttl_days`, zákazník ji vidí v UI), takže je maže služba exportu
// podle záznamu běhu, ne my podle stáří souboru. Dřív se uklízely jen při dalším
// exportu: archiv firmy, která už nic neexportovala, ležel na disku navždy.
$messages = [];
try {
    $container = Bootstrap::buildApp()->getContainer();
    if ($dryRun) {
        $report['instance_exports'] = count($container->get(InstanceExportJobStore::class)->expired());
    } else {
        $report['instance_exports'] = $container->get(InstanceExportService::class)->cleanupExpired();
    }
} catch (Throwable $e) {
    $report['instance_exports'] = 0;
    $messages[] = 'Úklid exportů instance selhal: ' . $e->getMessage();
    fwrite(STDERR, "  ✗ úklid exportů instance selhal: {$e->getMessage()}\n");
}

echo "[" . date('Y-m-d H:i:s') . "] cron-retention: " . json_encode($report, JSON_UNESCAPED_UNICODE) . "\n";

if ($dryRun) {
    $report['dry_run'] = 'ano';
}
$didWork = !$dryRun && ($sweeper->deletedPaths() !== [] || $report['logs_trimmed'] > 0 || $report['instance_exports'] > 0);
if ($sweeper->failedPaths() !== []) {
    $messages[] = sprintf('%d souborů se nepodařilo smazat (zamčené nebo bez práv), viz log běhu.', count($sweeper->failedPaths()));
}
$message = $messages !== [] ? implode(' ', $messages) : null;

$run->finish('ok', $report, $message, 0, $didWork);
