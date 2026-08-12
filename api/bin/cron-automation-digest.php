<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') exit("CLI only.\n");
require __DIR__ . '/../vendor/autoload.php';

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Automation\AutomationDigestService;
use MyInvoice\Service\Cron\CronRun;

$dryRun = false;
$hour = null;
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--dry-run') { $dryRun = true; continue; }
    if (preg_match('/^--hour=(\d{1,2})$/', $arg, $m) && (int) $m[1] <= 23) { $hour = (int) $m[1]; continue; }
    fwrite(STDERR, "Unknown arg: {$arg}\n"); exit(1);
}

$container = Bootstrap::buildContainer();
if ($container === null) { fwrite(STDERR, "Container not available.\n"); exit(1); }
$pdo = $container->get(Connection::class)->pdo();
$run = CronRun::start($pdo, 'cron-automation-digest');
try {
    $report = $container->get(AutomationDigestService::class)->run(new DateTimeImmutable(), $dryRun, $hour);
    echo json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
    // Přepočet zastaralých návrhů (běží před sečtením fronty) patří do protokolu cronu:
    // tiché doúčtování je v účetnictví to poslední, co má zůstat jen v e-mailu.
    $resweep = is_array($report['resweep'] ?? null) ? $report['resweep'] : [];
    $run->finish('ok', [
        'sent' => $report['sent'],
        'skipped' => $report['skipped'],
        'dry_run' => $dryRun,
        'resweep' => [
            'candidates' => $resweep['candidates'] ?? 0,
            'reevaluated' => $resweep['reevaluated'] ?? 0,
            'posted' => $resweep['posted'] ?? 0,
            'refreshed' => $resweep['refreshed'] ?? 0,
            'queued' => $resweep['queued'] ?? 0,
            'skipped' => $resweep['skipped'] ?? 0,
            'error' => $resweep['error'] ?? null,
        ],
    ]);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    $run->finish('error', ['error' => $e->getMessage(), 'dry_run' => $dryRun]);
    exit(1);
}
