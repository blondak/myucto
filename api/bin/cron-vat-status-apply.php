<?php

declare(strict_types=1);

/**
 * Cron — denní propsání historie plátcovství DPH do živé cache (EPIC VH-01).
 *
 * Použití:
 *   php api/bin/cron-vat-status-apply.php
 *   php api/bin/cron-vat-status-apply.php --dry-run   (jen vypíše počet firem k aktualizaci)
 *
 * Změny plátcovství lze v Nastavení plánovat i s budoucí účinností — řádek
 * supplier_vat_status_history s effective_from > dnes se do živé cache
 * (supplier.is_vat_payer, supplier.is_identified) nepropíše hned, ale až
 * v den účinnosti tímto cronem. Jediný set-based UPDATE přes všechny firmy
 * ({@see \MyInvoice\Service\Vat\VatStatusService::applyDueStatuses}), žádná
 * smyčka; idempotentní — mění jen firmy, kde se cache liší od historie,
 * takže opakovaný běh v tomtéž dni nic nepřepíše.
 */

if (PHP_SAPI !== 'cli') exit("CLI only.\n");
require __DIR__ . '/../vendor/autoload.php';

use MyInvoice\Bootstrap;
use MyInvoice\Service\Cron\CronRun;
use MyInvoice\Service\Vat\VatStatusService;

$dryRun = false;
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--dry-run') { $dryRun = true; continue; }
    fwrite(STDERR, "Unknown arg: $arg\n");
    exit(1);
}

$container = Bootstrap::buildContainer();

/** @var \MyInvoice\Infrastructure\Database\Connection $conn */
$conn = $container->get(\MyInvoice\Infrastructure\Database\Connection::class);
$pdo = $conn->pdo();

$run = CronRun::start($pdo, 'cron-vat-status-apply');
$startedAt = microtime(true);

printf("[%s] cron-vat-status-apply%s\n", date('Y-m-d H:i:s'), $dryRun ? ' --dry-run' : '');

if ($dryRun) {
    // Stejný predikát jako ostrý UPDATE, jen jako SELECT COUNT.
    $stmt = $pdo->query(
        'SELECT COUNT(*)
           FROM supplier s
           JOIN (
               SELECT h.supplier_id, h.is_vat_payer, h.is_identified
                 FROM supplier_vat_status_history h
                 JOIN (
                     SELECT supplier_id, MAX(effective_from) AS max_from
                       FROM supplier_vat_status_history
                      WHERE effective_from <= CURRENT_DATE
                      GROUP BY supplier_id
                 ) m ON m.supplier_id = h.supplier_id AND m.max_from = h.effective_from
           ) cur ON cur.supplier_id = s.id
          WHERE s.is_vat_payer <> cur.is_vat_payer OR s.is_identified <> cur.is_identified'
    );
    $updated = (int) $stmt->fetchColumn();
} else {
    $updated = VatStatusService::applyDueStatuses($pdo);
}

$ms = (int) ((microtime(true) - $startedAt) * 1000);
$report = ['dry_run' => $dryRun, 'updated' => $updated];

printf(
    "  done (%d ms): %d firem %s\n",
    $ms,
    $updated,
    $dryRun ? 'by se aktualizovalo (DRY RUN, nic se nezměnilo)' : 'aktualizováno',
);

$pdo->prepare("INSERT INTO activity_log (action, payload) VALUES ('cron.vat_status_apply', ?)")
    ->execute([json_encode($report, JSON_UNESCAPED_UNICODE)]);

$run->finish('ok', $report);
