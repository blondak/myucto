<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\Activation\DocumentBackfill;

function argValue(array $argv, string $key): ?string
{
    foreach ($argv as $arg) {
        if (str_starts_with($arg, "--{$key}=")) return substr($arg, strlen($key) + 3);
    }
    return null;
}

$supplierId = (int) (argValue($argv, 'supplier') ?? 0);
$yearValue = argValue($argv, 'year');
$year = $yearValue === null ? null : (int) $yearValue;
$dryRun = in_array('--dry-run', $argv, true);
$asDrafts = in_array('--drafts', $argv, true);
if ($supplierId <= 0) {
    fwrite(STDERR, "Chybí --supplier=<id>.\nPoužití: php api/bin/backfill-accounting.php --supplier=<id> [--year=YYYY] [--dry-run] [--drafts]\n");
    exit(2);
}

$container = Bootstrap::buildApp()->getContainer();
$pdo = $container->get(Connection::class)->pdo();
$stmt = $pdo->prepare('SELECT accounting_mode FROM supplier WHERE id = ?');
$stmt->execute([$supplierId]);
$mode = $stmt->fetchColumn();
if ($mode === false) {
    fwrite(STDERR, "Firma #{$supplierId} neexistuje.\n");
    exit(2);
}

$prefix = $dryRun ? '[DRY-RUN] ' : '';
$scope = $year !== null ? "rok {$year}" : 'všechny roky';
$post = $asDrafts ? 'koncepty (posted_at NULL)' : 'zaúčtováno';
echo "{$prefix}Backfill deníku — firma #{$supplierId}, {$scope}, jako {$post}.\n";
echo "Účetní režim firmy: {$mode}\n";

$report = $container->get(DocumentBackfill::class)->run(
    $supplierId,
    null,
    $year,
    $dryRun,
    $asDrafts,
    static fn (string $line) => print($line),
);
$failed = $report['invoice']['failed'] + $report['purchase_invoice']['failed'];
exit(($report['balance']['balanced'] && $failed === 0) ? 0 : 1);
