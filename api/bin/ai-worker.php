<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Config\RuntimePaths;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Ai\AiWorker;
use MyInvoice\Service\Cron\CronPreflight;
use MyInvoice\Service\Cron\CronRun;

function aiWorkerArg(array $argv, string $name): ?string
{
    foreach ($argv as $arg) {
        if (str_starts_with($arg, "--{$name}=")) {
            return substr($arg, strlen($name) + 3);
        }
    }
    return null;
}

$supplier = aiWorkerArg($argv, 'supplier');
$supplierId = $supplier === null ? null : (int) $supplier;
$limit = max(1, min(200, (int) (aiWorkerArg($argv, 'limit') ?? 50)));
$dryRun = in_array('--dry-run', $argv, true);
if ($supplier !== null && $supplierId <= 0) {
    fwrite(STDERR, "Použití: php api/bin/ai-worker.php [--supplier=N] [--limit=50] [--dry-run]\n");
    exit(2);
}

$lockDir = RuntimePaths::storage('locks');
if (!is_dir($lockDir) && !mkdir($lockDir, 0750, true) && !is_dir($lockDir)) {
    fwrite(STDERR, "Nelze vytvořit adresář zámku.\n");
    exit(3);
}
$lock = fopen($lockDir . '/ai-worker.lock', 'c+');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "AI worker už běží.\n");
    exit(0);
}

try {
    // Preflight PŘED stavbou kontejneru — prázdná fronta je u běžného tenanta
    // naprostá většina z ticků po deseti minutách. `--dry-run` bránu obchází,
    // protože jeho smysl je právě nahlédnout do fronty (i prázdné).
    if (!$dryRun) {
        $lightPdo = (new Connection(Config::load(Bootstrap::rootDir())))->pdo();
        if (!CronPreflight::hasAiWork($lightPdo)) {
            $stats = ['processed' => 0, 'done' => 0, 'skipped' => 0, 'failed' => 0, 'gate' => 'empty_queue'];
            if (defined('MYINVOICE_CRON_SCRIPT')) {
                CronRun::start($lightPdo, (string) MYINVOICE_CRON_SCRIPT)->finish('ok', $stats);
            }
            echo json_encode($stats, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
            return;
        }
    }

    $container = Bootstrap::buildContainer();
    $run = defined('MYINVOICE_CRON_SCRIPT')
        ? CronRun::start($container->get(Connection::class)->pdo(), (string) MYINVOICE_CRON_SCRIPT)
        : null;
    /** @var AiWorker $worker */
    $worker = $container->get(AiWorker::class);
    try {
        $stats = $worker->run($supplierId, $limit, $dryRun);
        echo json_encode($stats, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        $run?->finish('ok', $stats + ['dry_run' => $dryRun]);
    } catch (Throwable $e) {
        $run?->finish('error', ['error' => $e->getMessage(), 'dry_run' => $dryRun], $e->getMessage(), 1);
        throw $e;
    }
} finally {
    flock($lock, LOCK_UN);
    fclose($lock);
}
