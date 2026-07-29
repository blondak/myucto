<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\Activation\CashBackfill;

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
if ($supplierId <= 0) {
    fwrite(STDERR, "Chybí --supplier=<id>.\nPoužití: php api/bin/backfill-cash-accounting.php --supplier=<id> [--year=YYYY] [--dry-run]\n");
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
echo "{$prefix}Backfill deníku pokladny — firma #{$supplierId}, {$scope}.\n";
echo "Účetní režim firmy: {$mode}\n";

$report = $container->get(CashBackfill::class)->run(
    $supplierId,
    null,
    $year,
    $dryRun,
    static fn (string $line) => print($line),
);
exit($report['failed'] === 0 ? 0 : 1);
