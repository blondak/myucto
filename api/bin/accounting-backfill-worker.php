<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use MyInvoice\Bootstrap;
use MyInvoice\Repository\AccountingBackfillJobRepository;
use MyInvoice\Service\Accounting\Activation\BackfillService;

$jobId = null;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--job-id=')) $jobId = (int) substr($arg, 9);
}
if ($jobId === null || $jobId <= 0) {
    fwrite(STDERR, "Usage: php accounting-backfill-worker.php --job-id=N\n");
    exit(1);
}

$container = Bootstrap::buildApp()->getContainer();
$jobs = $container->get(AccountingBackfillJobRepository::class);
$job = $jobs->findById($jobId);
if ($job === null) {
    fwrite(STDERR, "Job #{$jobId} nenalezen.\n");
    exit(2);
}
if ($job['status'] !== 'queued') {
    fwrite(STDERR, "Job #{$jobId} není ve stavu queued.\n");
    exit(3);
}

set_time_limit(0);
ignore_user_abort(true);
try {
    $container->get(BackfillService::class)->run($jobId);
} catch (\Throwable $e) {
    $failed = $jobs->findById($jobId);
    if (($failed['status'] ?? null) !== 'failed') {
        $jobs->markFailed($jobId, 'Unexpected: ' . $e->getMessage());
    }
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(5);
}
exit(0);
