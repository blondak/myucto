<?php

declare(strict_types=1);

/**
 * Doúčtování GoPay úhrad ke dni platby (MD GoPay účet / D 311) pro úhrady, které
 * vznikly před zavedením okamžitého účtování nebo jejichž zaúčtování selhalo.
 * Logika: MyInvoice\Service\Accounting\GoPay\GoPayPendingService::postPending().
 *
 * Idempotentní, bezpečné pouštět opakovaně. Totéž dělá tlačítko
 * „Zaúčtovat úhrady čekající na vyúčtování" na stránce Účetnictví → GoPay.
 *
 * Použití:
 *   php api/bin/gopay-post-pending.php                    # dry-run, všechny firmy s GoPay
 *   php api/bin/gopay-post-pending.php --supplier=1       # dry-run jedné firmy
 *   php api/bin/gopay-post-pending.php --apply            # skutečně zaúčtuje
 */

require __DIR__ . '/../vendor/autoload.php';

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\GoPay\GoPayException;
use MyInvoice\Service\Accounting\GoPay\GoPayPendingService;

$apply = in_array('--apply', $argv, true);
$supplierFilter = null;
foreach ($argv as $arg) {
    if (preg_match('/^--supplier=(\d+)$/', $arg, $m) === 1) {
        $supplierFilter = (int) $m[1];
    }
}

$container = \MyInvoice\Bootstrap::buildApp()->getContainer();
$pdo = $container->get(Connection::class)->pdo();
$service = $container->get(GoPayPendingService::class);

$suppliers = $pdo->query(
    "SELECT DISTINCT s.id FROM supplier s JOIN gopay_settings gs ON gs.supplier_id=s.id
      WHERE s.accounting_mode='double_entry' ORDER BY s.id"
)->fetchAll(PDO::FETCH_COLUMN);
if ($supplierFilter !== null) {
    $suppliers = array_values(array_filter($suppliers, static fn ($id): bool => (int) $id === $supplierFilter));
}
if ($suppliers === []) {
    echo "Žádná firma s podvojným účetnictvím a nastaveným GoPay, nic k doúčtování.\n";
    exit(0);
}

$exit = 0;
foreach ($suppliers as $supplierId) {
    $supplierId = (int) $supplierId;
    $before = $service->overview($supplierId);
    echo "Firma #{$supplierId}: úhrad bez pohybu {$before['unrecorded_count']}, "
        . "čekajících pohybů " . count($before['items']) . " (nezaúčtovaných {$before['unposted_count']})\n";
    if (!$apply) {
        continue;
    }
    try {
        $result = $service->postPending($supplierId, null);
    } catch (GoPayException $e) {
        echo "  CHYBA: {$e->getMessage()}\n";
        $exit = 1;
        continue;
    }
    echo "  Založeno {$result['created']}, zaúčtováno {$result['posted']}, s chybou " . count($result['issues']) . "\n";
    foreach ($result['issues'] as $issue) {
        echo "    pohyb #{$issue['id']} ({$issue['performed_on']}): {$issue['issue_code']}: {$issue['issue_message']}\n";
    }
    if ($result['issues'] !== []) {
        $exit = 1;
    }
}

if (!$apply) {
    echo "\n[DRY-RUN] Spusť znovu s --apply pro skutečné zaúčtování.\n";
}
exit($exit);
