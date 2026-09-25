<?php

declare(strict_types=1);

/**
 * Přečíslování bankovních zápisů v deníku na dokladovou řadu účtu a měsíc výpisu
 * (BCR-08). Logika a hranice (jen otevřená období, storna, odpojené zápisy):
 * MyInvoice\Service\Accounting\Bank\BankDocumentNumberBackfill.
 *
 * Idempotentní, bezpečné pouštět opakovaně. Spouští ho i auto-backfill
 * v api/bin/migrate.php a uložení řady v nastavení bankovních účtů.
 *
 * Použití:
 *   php api/bin/bank-document-series-backfill.php                      # dry-run (jen vypíše)
 *   php api/bin/bank-document-series-backfill.php --apply              # skutečně zapíše
 *   php api/bin/bank-document-series-backfill.php --supplier=1 --from-date=2026-01-01 --apply
 *   --dry-run má přednost před --apply.
 */

require __DIR__ . '/../vendor/autoload.php';

$apply = in_array('--apply', $argv, true) && !in_array('--dry-run', $argv, true);
$supplierId = null;
$fromDate = null;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--supplier=')) {
        $supplierId = (int) substr($arg, 11);
        if ($supplierId <= 0) {
            fwrite(STDERR, "Neplatné --supplier.\n");
            exit(2);
        }
    } elseif (str_starts_with($arg, '--from-date=')) {
        $fromDate = substr($arg, 12);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromDate) !== 1) {
            fwrite(STDERR, "--from-date musí být YYYY-MM-DD.\n");
            exit(2);
        }
    }
}

$app = \MyInvoice\Bootstrap::buildApp();
$container = $app->getContainer();
$backfill = new \MyInvoice\Service\Accounting\Bank\BankDocumentNumberBackfill(
    $container->get(\MyInvoice\Infrastructure\Database\Connection::class),
    $container->get(\MyInvoice\Service\ActivityLogger::class),
);

$mode = $apply ? '' : '[DRY-RUN] ';
$result = $backfill->run($supplierId, $fromDate, $apply);

foreach ($result['changes'] as $change) {
    echo "  {$mode}firma #{$change['supplier_id']} zápis #{$change['entry_id']} ({$change['entry_date']}): "
        . ($change['from'] ?? '—') . " → {$change['to']}\n";
}

echo "\n{$mode}Zkontrolováno {$result['checked']} bankovních zápisů v otevřených obdobích, "
    . ($apply ? 'přečíslováno' : 'k přečíslování') . " {$result['changed']}, "
    . "bez dohledatelného účtu {$result['unresolved']}.\n";
if (!$apply && $result['changed'] > 0) {
    echo "Spusť znovu s --apply pro skutečný zápis.\n";
}
