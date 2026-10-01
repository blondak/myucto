<?php

declare(strict_types=1);

/**
 * Přepáruje platbu zálohy, která skončila na KONEČNÉ přijaté faktuře navázané na zálohovou
 * fakturu, zpět na zálohu: banka 321/221 → 314/221, konečná faktura dostane zúčtování
 * 321/314 a záloha zůstane uhrazená platbou místo ruční („evidenční") úhrady.
 * Logika a hranice (jen otevřené a nezamčené období, jen platba = částka zálohy, jen
 * pohyb hradící jediný doklad): MyInvoice\Service\Accounting\AdvancePaymentRelink.
 *
 * Druhá fáze srovná stav dvojic: záloha uhrazená platbami dostane datum úhrady podle
 * poslední platby (místo ručního data), konečná faktura krytá uhrazenou zálohou je
 * uhrazená k témuž datu (MyInvoice\Service\PurchaseInvoice\AdvanceCoveredPaidStatus).
 *
 * Idempotentní, bezpečné pouštět opakovaně — opravená dvojice už kritéria nesplní.
 *
 * Použití:
 *   php api/bin/fix-advance-payment-on-final.php                     # dry-run (jen vypíše)
 *   php api/bin/fix-advance-payment-on-final.php --apply             # skutečně zapíše
 *   php api/bin/fix-advance-payment-on-final.php --supplier=1 --apply
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
$relink = $app->getContainer()->get(\MyInvoice\Service\Accounting\AdvancePaymentRelink::class);

$mode = $apply ? '' : '[DRY-RUN] ';
$result = $relink->run($supplierId, $apply);

$labels = [
    'fixed'           => 'přepárováno na zálohu, banka 314/221, faktura zúčtovala 321/314',
    'would_fix'       => 'k opravě: platba → záloha, banka 321/221 → 314/221, faktura + 321/314, záloha uhrazená platbou',
    'failed'          => 'CHYBA, beze změny',
    'split_payment'   => 'beze změny',
    'amount_mismatch' => 'beze změny',
    'bank_not_posted' => 'beze změny',
    'taken_over'      => 'beze změny',
    'period_not_open' => 'jen report',
    'date_locked'     => 'jen report',
    'not_fully_covered'     => 'beze změny',
    'foreign_currency'      => 'beze změny',
    'card_clearing'         => 'beze změny',
    'payment_in_later_period' => 'jen report',
];
foreach ($result['rows'] as $row) {
    printf(
        "  %sfirma #%d záloha #%d (%s, stav %s) ← faktura #%d (%s), pohyb #%d z %s, %.2f Kč — %s%s\n",
        $mode,
        $row['supplier_id'],
        $row['advance_id'],
        $row['advance_no'] !== '' ? $row['advance_no'] : '—',
        $row['advance_status'],
        $row['final_id'],
        $row['final_no'] !== '' ? $row['final_no'] : '—',
        $row['tx_id'],
        $row['tx_date'],
        $row['amount'],
        $labels[$row['status']] ?? $row['status'],
        isset($row['message']) ? ' (' . $row['message'] . ')' : '',
    );
}

echo "\n{$mode}Plateb zálohy na konečné faktuře: kandidátů {$result['candidates']}, "
    . ($apply ? 'opraveno' : 'k opravě') . " {$result['fixed']}.\n";

// Druhá fáze: stav dvojic záloha → konečná faktura (MyInvoice\Service\PurchaseInvoice\AdvanceCoveredPaidStatus).
// Záloha uhrazená platbami má datum úhrady podle poslední platby, konečná faktura krytá
// uhrazenou zálohou je uhrazená k témuž datu. V dry-runu bere stav po případné první fázi
// tak, jak je v DB teď.
$pdo = $app->getContainer()->get(\MyInvoice\Infrastructure\Database\Connection::class)->pdo();
$status = $app->getContainer()->get(\MyInvoice\Service\PurchaseInvoice\AdvanceCoveredPaidStatus::class);
$pdo->beginTransaction();
try {
    $statusRows = $status->backfill($supplierId, $apply);
    $pdo->commit();
} catch (\Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, 'Srovnání stavů selhalo, beze změny: ' . $e->getMessage() . "\n");
    exit(1);
}
$statusChanged = 0;
foreach ($statusRows as $row) {
    if ($row['kind'] === 'advance_paid_at') {
        $statusChanged++;
        printf("  %sfirma #%d záloha #%d: datum úhrady %s → %s (poslední platba)\n",
            $mode, $row['supplier_id'], $row['id'], $row['from'] ?? '—', $row['to']);
    } elseif ($row['kind'] === 'final_paid') {
        $statusChanged++;
        printf("  %sfirma #%d konečná faktura #%d (záloha #%d): %s %s → %s %s\n",
            $mode, $row['supplier_id'], $row['id'], $row['advance_id'],
            $row['from_status'], $row['from_paid_at'] ?? '—', $row['to_status'], $row['to_paid_at'] ?? '—');
    } else {
        printf("  firma #%d konečná faktura #%d je uhrazená, ale záloha #%d ne — jen report, zkontroluj ručně\n",
            $row['supplier_id'], $row['id'], $row['advance_id']);
    }
}
echo "\n{$mode}Stav záloh a konečných faktur: " . ($apply ? 'srovnáno' : 'ke srovnání') . " {$statusChanged}.\n";

if (!$apply && ($result['fixed'] > 0 || $statusChanged > 0)) {
    echo "Spusť znovu s --apply pro skutečný zápis.\n";
}
exit(array_filter($result['rows'], static fn (array $r): bool => $r['status'] === 'failed') === [] ? 0 : 1);
