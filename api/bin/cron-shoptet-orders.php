<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Cron\CronRun;
use MyInvoice\Service\Shoptet\ShoptetOrderImportService;
use MyInvoice\Service\Shoptet\ShoptetSettingsService;

// Automatické stažení exportu objednávek ze Shoptetu (trvalý odkaz s hashem) pro
// firmy, které ho mají zapnuté. Interval určuje nastavení firmy; bez uloženého
// kurzoru se úplný export stahuje nejvýš jednou za 15 minut (pravidlo Shoptetu).

$container = Bootstrap::buildContainer();
$pdo = $container->get(Connection::class)->pdo();
$run = CronRun::start($pdo, 'cron-shoptet-orders');
$settings = $container->get(ShoptetSettingsService::class);
$importer = $container->get(ShoptetOrderImportService::class);

$stats = ['suppliers' => 0, 'created' => 0, 'updated' => 0, 'failed' => 0];
foreach ($settings->dueForFetch(new DateTimeImmutable()) as $supplierId) {
    $stats['suppliers']++;
    try {
        $batch = $importer->autoFetch($supplierId);
        $stats['created'] += (int) ($batch['summary']['created'] ?? 0);
        $stats['updated'] += (int) ($batch['summary']['updated'] ?? 0);
    } catch (Throwable $e) {
        $stats['failed']++;
        // Odkaz na export je tajemství, do logu jde jen firma a třída chyby.
        error_log(sprintf('cron-shoptet-orders: firma #%d: %s', $supplierId, $e::class));
    }
}

$run->finish($stats['failed'] > 0 ? 'error' : 'ok', $stats);
echo json_encode($stats, JSON_THROW_ON_ERROR) . PHP_EOL;
exit($stats['failed'] > 0 ? 1 : 0);
