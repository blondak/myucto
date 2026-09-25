<?php

declare(strict_types=1);

/**
 * Převod plateb kartou zaúčtovaných přes mezičlen (378.x) na přímé účtování banky
 * (MD 321 / D 221): bankovní zápis a jeho vypořádání s dokladem se sloučí na místě,
 * vypořádání se smaže. Logika a hranice (jen otevřená období mimo zamčené datum):
 * MyInvoice\Service\Accounting\Bank\CardClearingConversion.
 *
 * Idempotentní, bezpečné pouštět opakovaně. Spouští ho i auto-backfill v api/bin/migrate.php.
 *
 * Použití:
 *   php api/bin/card-clearing-to-direct.php                   # dry-run (jen vypíše)
 *   php api/bin/card-clearing-to-direct.php --apply           # skutečně zapíše
 *   php api/bin/card-clearing-to-direct.php --supplier=1 --apply
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
$conversion = new \MyInvoice\Service\Accounting\Bank\CardClearingConversion(
    $container->get(\MyInvoice\Infrastructure\Database\Connection::class),
    $container->get(\MyInvoice\Service\ActivityLogger::class),
    $container->get(\MyInvoice\Service\Accounting\Bank\BankPostingService::class),
);

$mode = $apply ? '' : '[DRY-RUN] ';
$result = $conversion->run($supplierId, $apply);

foreach ($result['suppliers'] as $sid => $report) {
    if ($report['merge'] === [] && $report['release'] === [] && $report['pairs'] === []
        && $report['empty_accounts'] === [] && $report['blocked'] === [] && $report['remaining'] === []) {
        continue;
    }
    echo "Firma #{$sid} (analytiky mezičlenu: " . ($report['codes'] === [] ? '—' : implode(', ', $report['codes'])) . ")\n";
    foreach ($report['merge'] as $m) {
        echo "  {$mode}sloučeno: bankovní zápis #{$m['bank_entry_id']} (" . ($m['document_no'] ?? '—') . ", {$m['entry_date']}) + smazané vypořádání #"
            . implode(', #', $m['settlement_entry_ids']) . "\n";
    }
    foreach ($report['release'] as $r) {
        echo "  {$mode}vráceno do fronty: pohyb #{$r['tx_id']}, smazaný zápis #{$r['bank_entry_id']} (" . ($r['document_no'] ?? '—') . ")\n";
    }
    foreach ($report['pairs'] as $p) {
        echo "  {$mode}smazaná storno dvojice: #{$p['entry_id']} / #{$p['reversal_id']} ({$p['source_type']})\n";
    }
    foreach ($report['empty_accounts'] as $code) {
        echo "  {$mode}prázdná analytika k odstranění z osnovy: {$code}\n";
    }
    foreach ($report['blocked'] as $b) {
        echo "  ZŮSTÁVÁ ({$b['reason']}): {$b['detail']}\n";
    }
    foreach ($report['remaining'] as $code => $t) {
        echo "  obrat {$code}: řádků {$t['lines']}, MD " . number_format($t['debit'], 2, ',', ' ') . ', D ' . number_format($t['credit'], 2, ',', ' ') . "\n";
    }
}

echo "\n{$mode}Sloučeno {$result['merged']}, vráceno do fronty {$result['released']}, smazaných storno dvojic {$result['pairs_deleted']}, "
    . 'odstraněných analytik ' . ($apply ? $result['accounts_deleted'] : $result['accounts_deleted'] . ' (k odstranění)')
    . ", zůstává mimo převod {$result['blocked']}.\n";
if (!$apply && ($result['merged'] + $result['released'] + $result['pairs_deleted'] + $result['accounts_deleted']) > 0) {
    echo "Spusť znovu s --apply pro skutečný zápis.\n";
}
