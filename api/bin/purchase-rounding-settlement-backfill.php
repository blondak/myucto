<?php

declare(strict_types=1);

/**
 * Dorovná haléřový zbytek na 321 u zaúčtovaných úhrad přijatých faktur, které se
 * nesrovnaly na nominál předpisu (doklad se zaokrouhlením, dobropis s vratkou).
 * Logika a hranice (jen otevřená období, přepis na místě):
 * MyInvoice\Service\Accounting\Bank\PurchaseRoundingSettlementBackfill.
 *
 * Idempotentní, bezpečné pouštět opakovaně. Spouští ho i auto-backfill v api/bin/migrate.php.
 *
 * Použití:
 *   php api/bin/purchase-rounding-settlement-backfill.php                  # dry-run (jen vypíše)
 *   php api/bin/purchase-rounding-settlement-backfill.php --apply          # skutečně zapíše
 *   php api/bin/purchase-rounding-settlement-backfill.php --supplier=1 --apply
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
$backfill = $app->getContainer()->get(\MyInvoice\Service\Accounting\Bank\PurchaseRoundingSettlementBackfill::class);

$mode = $apply ? '' : '[DRY-RUN] ';
$result = $backfill->run($supplierId, $apply);

$labels = [
    'fixed'            => 'dorovnáno',
    'would_fix'        => 'k dorovnání',
    'period_not_open'  => 'období není otevřené, beze změny',
    'not_full_payment' => 'nesplňuje podmínky srovnání (slabé párování, záloha bez předpisu), beze změny',
];
foreach ($result['rows'] as $row) {
    $label = $labels[$row['status']] ?? $row['status'];
    printf(
        "  %sfirma #%d PF #%d pohyb #%d zápis #%d (%s): k úhradě %.2f, zaokrouhlení %.2f, zaplaceno %.2f — %s%s\n",
        $mode,
        $row['supplier_id'],
        $row['purchase_invoice_id'],
        $row['tx_id'],
        $row['entry_id'],
        $row['entry_date'],
        $row['amount_to_pay'],
        $row['rounding'],
        $row['paid'],
        $label,
        isset($row['message']) ? ' (' . $row['message'] . ')' : '',
    );
}

echo "\n{$mode}Úhrad přijatých faktur s haléřovým zbytkem: kandidátů {$result['candidates']}, "
    . ($apply ? 'dorovnáno' : 'k dorovnání') . " {$result['fixed']}.\n";
if (!$apply && $result['fixed'] > 0) {
    echo "Spusť znovu s --apply pro skutečný zápis.\n";
}
