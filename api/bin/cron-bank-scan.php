<?php

declare(strict_types=1);

/**
 * Auto-import GPC výpisů z konfigurovaného adresáře.
 * Konfigurace v cfg.php:
 *   'bank_import' => ['scan_root' => 'C:/Users/.../FIO/exports']
 *
 * Skenuje rekurzivně root + podadresáře YYYY-MM/ , hledá *.gpc / *.txt.
 * SHA256 dedupe — soubor co už byl naimportovaný se přeskočí.
 */

if (PHP_SAPI !== 'cli') exit("CLI only.\n");
require __DIR__ . '/../vendor/autoload.php';

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Bank\StatementScanner;
use MyInvoice\Service\Cron\CronRun;

$rootDir = Bootstrap::rootDir();
$config  = Config::load($rootDir);
$conn    = new Connection($config);

$run = CronRun::start($conn->pdo(), 'cron-bank-scan');

$scanRoot = (string) $config->get('bank_import.scan_root', '');
if ($scanRoot === '' || !is_dir($scanRoot)) {
    fwrite(STDERR, "[bank-scan] cfg.bank_import.scan_root neexistuje: " . ($scanRoot ?: '(prázdné)') . "\n");
    $run->finish('ok', ['skipped' => 'scan_root not configured', 'scan_root' => $scanRoot]);
    exit(0);
}

// Přes DI container — StatementMatcher má injektovaný PaymentThanksMailer (#127),
// takže auto-import GPC pošle děkovný e-mail za úhradu stejně jako ostatní cesty.
// (Dřív se zde StatementMatcher konstruoval ručně a navíc bez FinalFromProformaCreator.)
$container = Bootstrap::buildContainer();
$scanner   = $container->get(StatementScanner::class);

$started = microtime(true);
$summary = $scanner->scan($scanRoot);
$ms = (int) ((microtime(true) - $started) * 1000);

echo "[" . date('Y-m-d H:i:s') . "] bank-scan ({$ms} ms): " . json_encode($summary, JSON_UNESCAPED_UNICODE) . "\n";

$conn->pdo()->prepare(
    "INSERT INTO activity_log (action, payload) VALUES ('cron.bank_scan', ?)"
)->execute([json_encode($summary, JSON_UNESCAPED_UNICODE)]);

$run->finish('ok', is_array($summary) ? $summary : ['summary' => $summary]);
