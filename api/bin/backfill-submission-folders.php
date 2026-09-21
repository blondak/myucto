<?php

declare(strict_types=1);

/**
 * Přesun originálů příchozích dokladů (Nákup → Příchozí doklady) z kořene Dokumentů
 * do složky „Příchozí doklady / rok / měsíc". Logika:
 * MyInvoice\Service\PurchaseInvoice\SubmissionFolderBackfill.
 *
 * Idempotentní, bezpečné pouštět opakovaně. Spouští ho i auto-backfill
 * v api/bin/migrate.php, když najde doklady v kořeni.
 *
 * Použití:
 *   php api/bin/backfill-submission-folders.php           # dry-run (jen vypíše)
 *   php api/bin/backfill-submission-folders.php --apply   # skutečně přesune
 */

require __DIR__ . '/../vendor/autoload.php';

$apply = in_array('--apply', $argv, true);

$container = \MyInvoice\Bootstrap::buildApp()->getContainer();
$pdo = $container->get(\MyInvoice\Infrastructure\Database\Connection::class)->pdo();
$ingest = $container->get(\MyInvoice\Service\Document\DocumentIngestService::class);

$mode = $apply ? '' : '[DRY-RUN] ';
$result = (new \MyInvoice\Service\PurchaseInvoice\SubmissionFolderBackfill($pdo, $ingest))->run(
    $apply,
    static function (int $documentId, int $supplierId, string $path) use ($mode): void {
        echo "  {$mode}firma #{$supplierId}, dokument #{$documentId} → {$path}\n";
    },
);

if ($result['found'] === 0) {
    echo "Žádné příchozí doklady v kořeni Dokumentů — nic k přesunu.\n";
    exit(0);
}
if ($apply) {
    echo "\nHotovo. Nalezeno: {$result['found']}, přesunuto: {$result['moved']}\n";
} else {
    echo "\n{$mode}Nalezeno {$result['found']} dokladů. Spusť znovu s --apply pro skutečný přesun.\n";
}
