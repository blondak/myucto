<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}

require __DIR__ . '/../vendor/autoload.php';

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Cron\CronRun;
use MyInvoice\Service\Epo\EpoDirectSubmissionService;
use MyInvoice\Service\License\CommercialFeatureAccess;

$limit = 50;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--limit=')) {
        $limit = max(1, min(200, (int) substr($arg, 8)));
        continue;
    }
    fwrite(STDERR, "Unknown arg: {$arg}\n");
    exit(1);
}

$app = Bootstrap::buildApp();
$container = $app->getContainer();
if ($container === null) {
    fwrite(STDERR, "Container not available.\n");
    exit(1);
}

/** @var Connection $connection */
$connection = $container->get(Connection::class);
/** @var EpoDirectSubmissionService $service */
$service = $container->get(EpoDirectSubmissionService::class);
/** @var CommercialFeatureAccess $commercialFeatures */
$commercialFeatures = $container->get(CommercialFeatureAccess::class);

$run = CronRun::start($connection->pdo(), 'cron-epo-status');
try {
    if (!$commercialFeatures->isAvailable()) {
        $result = ['polled' => 0, 'updated' => 0, 'errors' => 0, 'skipped' => 'commercial_license_required'];
        $run->finish('ok', $result);
        echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        exit(0);
    }

    $result = $service->pollDue($limit);
    $status = $result['errors'] > 0 ? 'error' : 'ok';
    $run->finish($status, $result);
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit($result['errors'] > 0 ? 2 : 0);
} catch (\Throwable $e) {
    $run->finish('error', ['error' => $e->getMessage()]);
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}
