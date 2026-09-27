<?php

declare(strict_types=1);

/**
 * Backfill chybějících exchange_rate na non-CZK fakturách (přijaté + vystavené).
 *
 * Use case: faktury importované přes ISDOC / iDoklad / Fakturoid / sample.php /
 * ručně bez explicitního exchange_rate v EUR/USD/jiné měně mají
 * `exchange_rate IS NULL`. V CRM costs sumaci, Top klienti rankingu a podobných
 * agregacích se EUR řádky počítají jako multiplier=1 (jako CZK), což je špatně.
 *
 * Skript dotahuje ČNB kurz k DUZP (tax_date, fallback issue_date) a UPDATE
 *   - purchase_invoices.exchange_rate + exchange_rate_date + exchange_rate_source='cnb'
 *   - invoices.exchange_rate + exchange_rate_date
 *
 * Doklad převzatý z jiného účetního programu (TakenOverRecord) se vynechá: kurz a jeho
 * přepočet určil zdroj a kurz ČNB by se od převzatého zaúčtování rozešel.
 *
 * Použití:
 *   php api/bin/backfill-exchange-rates.php           # dry-run
 *   php api/bin/backfill-exchange-rates.php --apply   # zápis
 */

require __DIR__ . '/../vendor/autoload.php';

$dryRun = !in_array('--apply', $argv, true);

$app = \MyInvoice\Bootstrap::buildApp();
$container = $app->getContainer();
$pdo = $container->get(\MyInvoice\Infrastructure\Database\Connection::class)->pdo();
$cnb = $container->get(\MyInvoice\Service\Currency\CnbExchangeRateClient::class);
$piRepo  = $container->get(\MyInvoice\Repository\PurchaseInvoiceRepository::class);
$invRepo = $container->get(\MyInvoice\Repository\InvoiceRepository::class);

$totalOk = 0;
$totalFailed = 0;

// ── Přijaté faktury ──────────────────────────────────────────────────────
$piRows = $pdo->query(
    "SELECT pi.id, pi.supplier_id, pi.vendor_invoice_number AS label, pi.status,
            pi.issue_date, pi.tax_date, cur.code AS currency
       FROM purchase_invoices pi
       JOIN currencies cur ON cur.id = pi.currency_id
      WHERE pi.exchange_rate IS NULL
        AND cur.code != 'CZK'
        AND pi.status != 'cancelled'
        AND NOT " . \MyInvoice\Service\Accounting\TakenOverRecord::documentSql('purchase_invoice', 'pi') . "
      ORDER BY pi.supplier_id, pi.issue_date, pi.id"
)->fetchAll(\PDO::FETCH_ASSOC);

// ── Vystavené faktury ────────────────────────────────────────────────────
$invRows = $pdo->query(
    "SELECT i.id, i.supplier_id, i.varsymbol AS label, i.status,
            i.issue_date, i.tax_date, cur.code AS currency
       FROM invoices i
       JOIN currencies cur ON cur.id = i.currency_id
      WHERE i.exchange_rate IS NULL
        AND cur.code != 'CZK'
        AND i.status NOT IN ('cancelled', 'draft')
        AND NOT " . \MyInvoice\Service\Accounting\TakenOverRecord::documentSql('invoice', 'i') . "
      ORDER BY i.supplier_id, i.issue_date, i.id"
)->fetchAll(\PDO::FETCH_ASSOC);

if (empty($piRows) && empty($invRows)) {
    echo "Žádné non-CZK faktury bez kurzu — nic k doplnění.\n";
    exit(0);
}

$mode = $dryRun ? '[DRY-RUN] ' : '';

/** Zpracuj jeden set řádků, callback aplikuje update. */
$process = function (string $kind, array $rows, callable $apply) use (&$totalOk, &$totalFailed, $cnb, $dryRun) {
    if (empty($rows)) return;
    echo "\n# $kind — " . count($rows) . " faktur\n";
    foreach ($rows as $r) {
        $dateStr = $r['tax_date'] ?: $r['issue_date'];
        try {
            $date = new \DateTimeImmutable($dateStr);
            $result = $cnb->getRate($r['currency'], $date);
        } catch (\Throwable $e) {
            echo sprintf("  #%-6d tenant=%-2d  %s  %s  ✗ ČNB: %s\n",
                $r['id'], $r['supplier_id'], $dateStr, $r['currency'], $e->getMessage());
            $totalFailed++;
            continue;
        }
        if ($result === null || !isset($result['rate'])) {
            echo sprintf("  #%-6d tenant=%-2d  %s  %s  ✗ ČNB vrátil null\n",
                $r['id'], $r['supplier_id'], $dateStr, $r['currency']);
            $totalFailed++;
            continue;
        }
        $rate = (float) $result['rate'];
        $rateDate = (string) ($result['rate_date'] ?? $dateStr);
        $line = sprintf("  #%-6d tenant=%-2d  %s  %s  →  %.4f (k %s)  [%s]",
            $r['id'], $r['supplier_id'], $dateStr, $r['currency'], $rate, $rateDate, $r['label'] ?? '');

        if (!$dryRun) {
            try {
                $apply((int) $r['id'], $rate, $rateDate, (int) $r['supplier_id']);
                $totalOk++;
            } catch (\Throwable $e) {
                echo $line . "  ✗ DB: " . $e->getMessage() . "\n";
                $totalFailed++;
                continue;
            }
        } else {
            $totalOk++;
        }
        echo $line . "\n";
    }
};

echo $mode . "Backfill exchange_rate spuštěn.\n";

$process('purchase_invoices', $piRows, function (int $id, float $rate, string $rateDate, int $supplierId) use ($piRepo) {
    $piRepo->setExchangeRate($id, $rate, $rateDate, 'cnb', $supplierId);
});

$process('invoices', $invRows, function (int $id, float $rate, string $rateDate, int $supplierId) use ($invRepo) {
    // InvoiceRepository::setExchangeRate nemá supplier scope param (1:1 přes ID); supplier
    // jsme už filtrovali ve SELECT — bezpečné.
    $invRepo->setExchangeRate($id, $rate, $rateDate);
});

if ($dryRun) {
    echo "\nOK k zápisu: {$totalOk}, selhání ČNB: {$totalFailed}\n";
    echo "Spusť znovu s --apply pro skutečný zápis.\n";
} else {
    echo "\nHotovo. Doplněno: {$totalOk}, selhalo: {$totalFailed}\n";
    if ($totalFailed > 0) exit(1);
}
