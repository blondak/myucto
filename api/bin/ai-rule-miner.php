<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\Learning\RuleMiner;
use MyInvoice\Service\Cron\CronRun;

function minerArg(array $argv, string $key): ?string
{
    foreach ($argv as $arg) {
        if (str_starts_with($arg, "--{$key}=")) return substr($arg, strlen($key) + 3);
    }
    return null;
}

$supplier = minerArg($argv, 'supplier');
$supplierId = $supplier === null ? null : (int) $supplier;
$days = (int) (minerArg($argv, 'days') ?? 180);
$apply = in_array('--apply', $argv, true);
if (($supplier !== null && $supplierId <= 0) || $days <= 0) {
    fwrite(STDERR, "Použití: php api/bin/ai-rule-miner.php [--supplier=<id>] [--days=<n>] [--apply|--dry-run]\n");
    exit(2);
}

$container = Bootstrap::buildApp()->getContainer();
$run = defined('MYINVOICE_CRON_SCRIPT')
    ? CronRun::start($container->get(Connection::class)->pdo(), (string) MYINVOICE_CRON_SCRIPT)
    : null;
try {
    /** @var RuleMiner $miner */
    $miner = $container->get(RuleMiner::class);
    $report = $miner->run($supplierId, $days, $apply);
    $prefix = $apply ? '' : '[DRY-RUN] ';
    echo "{$prefix}Učení pravidel z korekcí ({$days} dnů)\n";
    printf("  clustery: %d\n  navrženo: %d\n  vytvořeno: %d\n  přeskočeno: %d\n",
        $report['clusters'], $report['proposed'], $report['created'], $report['skipped']);
    foreach ($report['skip_reasons'] as $reason => $count) printf("  %-22s %d×\n", $reason, $count);
    if (!$apply) echo "\nDry-run nic nezapsal; pro vytvoření návrhových pravidel přidejte --apply.\n";
    $run?->finish('ok', [
        'clusters' => $report['clusters'], 'proposed' => $report['proposed'],
        'created' => $report['created'], 'skipped' => $report['skipped'],
        'skip_reasons' => $report['skip_reasons'], 'dry_run' => !$apply,
    ]);
} catch (Throwable $e) {
    $run?->finish('error', ['error' => $e->getMessage(), 'dry_run' => !$apply], $e->getMessage(), 1);
    throw $e;
}
