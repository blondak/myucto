<?php

declare(strict_types=1);

/**
 * Doplní číslo dokladu (journal_entries.document_no) u zápisů vydaných a přijatých
 * faktur, které ho nemají. Logika a hranice (jen otevřená období):
 * MyInvoice\Service\Accounting\DocumentEntryNumberBackfill.
 *
 * Idempotentní, bezpečné pouštět opakovaně. Spouští ho i auto-backfill v api/bin/migrate.php.
 *
 * Použití:
 *   php api/bin/document-entry-number-backfill.php                  # dry-run (jen vypíše)
 *   php api/bin/document-entry-number-backfill.php --apply          # skutečně zapíše
 *   php api/bin/document-entry-number-backfill.php --supplier=1 --apply
 *   --dry-run má přednost před --apply.
 */

require __DIR__ . '/../vendor/autoload.php';

$apply = in_array('--apply', $argv, true) && !in_array('--dry-run', $argv, true);
$supplierId = null;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--supplier=')) {
        $supplierId = (int) substr($arg, 11);
        if ($supplierId <= 0) {
            fwrite(STDERR, "Neplatné --supplier.\n");
            exit(2);
        }
    }
}

$app = \MyInvoice\Bootstrap::buildApp();
$container = $app->getContainer();
$backfill = new \MyInvoice\Service\Accounting\DocumentEntryNumberBackfill(
    $container->get(\MyInvoice\Infrastructure\Database\Connection::class),
    $container->get(\MyInvoice\Service\ActivityLogger::class),
);

$mode = $apply ? '' : '[DRY-RUN] ';
$result = $backfill->run($supplierId, $apply);

foreach ($result['changes'] as $change) {
    echo "  {$mode}firma #{$change['supplier_id']} zápis #{$change['entry_id']} ({$change['entry_date']}, "
        . "{$change['source_type']} #{$change['source_id']}): — → {$change['to']}\n";
}

echo "\n{$mode}Zápisů faktur bez čísla dokladu v otevřených obdobích: "
    . ($apply ? 'doplněno' : 'k doplnění') . " {$result['changed']}.\n";
if (!$apply && $result['changed'] > 0) {
    echo "Spusť znovu s --apply pro skutečný zápis.\n";
}
