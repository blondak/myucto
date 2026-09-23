<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') exit("CLI only.\n");
require __DIR__ . '/../vendor/autoload.php';

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\OtherItemScheduleService;
use MyInvoice\Service\Cron\CronRun;

$dryRun = in_array('--dry-run', array_slice($argv, 1), true);
if (count(array_diff(array_slice($argv, 1), ['--dry-run'])) > 0) {
    fwrite(STDERR, "Unknown argument.\n");
    exit(1);
}

$container = Bootstrap::buildContainer();
$pdo = $container->get(Connection::class)->pdo();
$service = $container->get(OtherItemScheduleService::class);
$through = (new DateTimeImmutable('today'))->modify('+90 days')->format('Y-m-d');
$run = $dryRun ? null : CronRun::start($pdo, 'cron-generate-other-items');
$report = ['schedules' => 0, 'generated' => 0, 'errors' => 0, 'through' => $through, 'dry_run' => $dryRun];
$stmt = $pdo->prepare("SELECT s.id, s.supplier_id FROM other_item_schedules s
    JOIN other_items oi ON oi.id = s.source_item_id AND oi.supplier_id = s.supplier_id
    WHERE s.status = 'active' AND oi.status IN ('draft', 'confirmed', 'posted')
      AND s.anchor_on <= ? ORDER BY s.supplier_id, s.id");
$stmt->execute([$through]);
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $schedule) {
    $report['schedules']++;
    if ($dryRun) continue;
    try {
        $result = $service->generate((int) $schedule['supplier_id'], (int) $schedule['id'], $through, null);
        $report['generated'] += count($result['created_ids']);
    } catch (\Throwable $e) {
        $report['errors']++;
        fwrite(STDERR, "Schedule {$schedule['id']}: {$e->getMessage()}\n");
    }
}
$status = $report['errors'] > 0 ? 'error' : 'ok';
$run?->finish($status, $report, null, $report['errors'] > 0 ? 2 : 0,
    $report['generated'] > 0 || $report['errors'] > 0);
echo json_encode($report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($report['errors'] > 0 ? 2 : 0);
