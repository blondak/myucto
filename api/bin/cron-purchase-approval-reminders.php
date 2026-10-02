<?php

declare(strict_types=1);

/**
 * Cron: připomínky schvalovatelům přijatých dokladů (schvalování manažerem střediska, F6).
 *
 * Použití:
 *   php api/bin/cron-purchase-approval-reminders.php             # výchozí z cfg.purchase_approval.*
 *   php api/bin/cron-purchase-approval-reminders.php --days=5    # přepíše reminder_after_days
 *   php api/bin/cron-purchase-approval-reminders.php --dry-run
 *
 * Logika:
 *   - schválení čeká (status 'pending') a doklad je pořád koncept,
 *   - poslední kontakt (připomínka, jinak žádost) je starší než N dní
 *     (cfg.purchase_approval.reminder_after_days, výchozí 3),
 *   - připomínek bylo méně než cfg.purchase_approval.max_reminders (výchozí 3).
 *
 * Připomínka nese NOVÝ odkaz (token se v DB drží jen jako hash, starý tedy nejde
 * poslat znovu) s novou platností 14 dní. Audit: purchase_invoice.approval_reminder_sent.
 */

if (PHP_SAPI !== 'cli') exit("CLI only.\n");
require __DIR__ . '/../vendor/autoload.php';

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\PurchaseInvoiceApprovalRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Cron\CronRun;
use MyInvoice\Service\PurchaseInvoice\Approval\PurchaseInvoiceApprovalService;

$daysOverride = null;
$dryRun = false;
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--dry-run') { $dryRun = true; continue; }
    if (preg_match('/^--days=(\d+)$/', $arg, $m)) { $daysOverride = (int) $m[1]; continue; }
    fwrite(STDERR, "Unknown arg: $arg\n");
    exit(1);
}

$container = Bootstrap::buildContainer();

/** @var Config $config */
$config = $container->get(Config::class);
/** @var Connection $conn */
$conn = $container->get(Connection::class);
/** @var PurchaseInvoiceApprovalRepository $repo */
$repo = $container->get(PurchaseInvoiceApprovalRepository::class);
/** @var PurchaseInvoiceApprovalService $service */
$service = $container->get(PurchaseInvoiceApprovalService::class);
/** @var ActivityLogger $logger */
$logger = $container->get(ActivityLogger::class);

$run = CronRun::start($conn->pdo(), 'cron-purchase-approval-reminders');

$days = max(1, $daysOverride ?? (int) $config->get('purchase_approval.reminder_after_days', 3));
$maxReminders = max(0, (int) $config->get('purchase_approval.max_reminders', 3));

$startedAt = microtime(true);
echo '[' . date('Y-m-d H:i:s') . "] cron-purchase-approval-reminders --days={$days} --max={$maxReminders}"
    . ($dryRun ? ' --dry-run' : '') . "\n";

$candidates = $repo->reminderCandidates($days, $maxReminders);
echo '  found ' . count($candidates) . " candidates\n";

$report = ['days' => $days, 'max' => $maxReminders, 'dry_run' => $dryRun, 'candidates' => count($candidates), 'sent' => 0, 'errors' => 0];

foreach ($candidates as $c) {
    if ($dryRun) {
        printf("  [DRY] approval #%d (firma %d)\n", $c['id'], $c['supplier_id']);
        continue;
    }
    try {
        $service->remind($c['supplier_id'], $c['id'], null, false);
        $report['sent']++;
        printf("  ✓ approval #%d (firma %d)\n", $c['id'], $c['supplier_id']);
    } catch (\Throwable $e) {
        $report['errors']++;
        $logger->log('purchase_invoice.approval_reminder_failed', null, 'purchase_invoice_approval', $c['id'], [
            'supplier_id' => $c['supplier_id'],
            'error' => mb_substr($e->getMessage(), 0, 500),
        ]);
        fprintf(STDERR, "  ✗ approval #%d: %s\n", $c['id'], $e->getMessage());
    }
}

$ms = (int) ((microtime(true) - $startedAt) * 1000);
echo "  done ({$ms} ms): sent={$report['sent']}, errors={$report['errors']}\n";

$logger->log('cron.purchase_approval_reminders', null, null, null, $report);
$run->finish($report['errors'] > 0 ? 'error' : 'ok', $report);
