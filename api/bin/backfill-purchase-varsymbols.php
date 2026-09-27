<?php

declare(strict_types=1);

/**
 * Backfill chybějících varsymbolů na přijatých fakturách.
 *
 * Use case: faktury, které prošly AI auto-paid (markAlreadyPaid) před opravou
 * v AiPdfExtractor zůstaly bez varsymbolu, protože přímý UPDATE statusu
 * obcházel TransitionPurchaseInvoiceStatusAction (kde se varsymbol generuje
 * při draft→received).
 *
 * Skript je idempotentní — ensureVarsymbol() v repo vrátí stávající hodnotu
 * pokud varsymbol už nastavený je. Bezpečné pouštět opakovaně.
 *
 * Doklad převzatý z jiného účetního programu (TakenOverRecord) se vynechá: nese údaje
 * zdroje a převod je nedoplňuje.
 *
 * Použití:
 *   php api/bin/backfill-purchase-varsymbols.php           # dry-run (jen vypíše)
 *   php api/bin/backfill-purchase-varsymbols.php --apply   # skutečně zapíše
 */

require __DIR__ . '/../vendor/autoload.php';

$dryRun = !in_array('--apply', $argv, true);

$app = \MyInvoice\Bootstrap::buildApp();
$container = $app->getContainer();
$pdo  = $container->get(\MyInvoice\Infrastructure\Database\Connection::class)->pdo();
$repo = $container->get(\MyInvoice\Repository\PurchaseInvoiceRepository::class);

$stmt = $pdo->query(
    "SELECT pi.id, pi.supplier_id, pi.vendor_invoice_number, pi.total_with_vat, pi.status, pi.issue_date, pi.paid_at
       FROM purchase_invoices pi
      WHERE pi.varsymbol IS NULL
        AND pi.status != 'cancelled'
        AND NOT " . \MyInvoice\Service\Accounting\TakenOverRecord::documentSql('purchase_invoice', 'pi') . "
      ORDER BY pi.supplier_id, pi.issue_date, pi.id"
);
$rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

if (empty($rows)) {
    echo "Žádné přijaté faktury bez varsymbolu — nic k doplnění.\n";
    exit(0);
}

$mode = $dryRun ? '[DRY-RUN] ' : '';
echo "{$mode}Nalezeno " . count($rows) . " přijatých faktur bez varsymbolu:\n\n";

$ok = 0;
$failed = 0;
foreach ($rows as $r) {
    $line = sprintf(
        "  #%-6d tenant=%-2d  %s  status=%-9s  vendor#=%s  %s",
        $r['id'],
        $r['supplier_id'],
        $r['issue_date'],
        $r['status'],
        $r['vendor_invoice_number'] ?: '(none)',
        $r['paid_at'] ? "paid " . $r['paid_at'] : ''
    );
    if ($dryRun) {
        echo $line . "\n";
        continue;
    }
    try {
        $vs = $repo->ensureVarsymbol((int) $r['id'], (int) $r['supplier_id']);
        echo $line . "  → " . $vs . "\n";
        $ok++;
    } catch (\Throwable $e) {
        echo $line . "  ✗ " . $e->getMessage() . "\n";
        $failed++;
    }
}

if ($dryRun) {
    echo "\nSpusť znovu s --apply pro skutečný zápis.\n";
} else {
    echo "\nHotovo. Doplněno: {$ok}, selhalo: {$failed}\n";
    if ($failed > 0) exit(1);
}
