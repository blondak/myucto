<?php

declare(strict_types=1);

/**
 * Obnova jediného kompletního exportu MyÚčto do čisté, předem migrované DB.
 *
 * php api/bin/archive-restore.php --file=<export.zip> --database=<prazdna_db> --dry-run
 * php api/bin/archive-restore.php --file=<export.zip> --database=<prazdna_db> --restore --storage=<data_dir>
 */

require __DIR__ . '/../vendor/autoload.php';

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Service\Cron\BackupEncryption;
use MyInvoice\Service\Export\Instance\CompleteInstanceRestoreService;
use MyInvoice\Service\Export\Instance\InstanceExportException;

const EXIT_OK = 0;
const EXIT_INVALID = 1;
const EXIT_USAGE = 2;

$file = null;
$database = null;
$storage = null;
$mode = null;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--file=')) {
        $file = substr($arg, 7);
    } elseif (str_starts_with($arg, '--database=')) {
        $database = substr($arg, 11);
    } elseif (str_starts_with($arg, '--storage=')) {
        $storage = substr($arg, 10);
    } elseif (in_array($arg, ['--dry-run', '--restore'], true)) {
        $mode = $arg;
    } else {
        fwrite(STDERR, "Neznámý argument: {$arg}\n");
        exit(EXIT_USAGE);
    }
}
if ($file === null || !is_file($file) || $database === null || preg_match('/^[A-Za-z0-9_]+$/', $database) !== 1 || $mode === null) {
    fwrite(STDERR, "Použití: php api/bin/archive-restore.php --file=<export.zip> --database=<prázdná_migrovaná_db> --dry-run|--restore [--storage=<data_dir>]\n");
    exit(EXIT_USAGE);
}
if ($mode === '--restore' && $storage === null) {
    fwrite(STDERR, "Pro --restore zadejte také prázdný --storage=<data_dir>; chrání to soubory původní instance.\n");
    exit(EXIT_USAGE);
}

try {
    $config = Config::load(Bootstrap::rootDir());
    $pdo = new \PDO(
        sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $config->get('db.host', '127.0.0.1'), (int) $config->get('db.port', 3306), $database, $config->get('db.charset', 'utf8mb4')),
        (string) $config->get('db.user'),
        (string) $config->get('db.pass', ''),
        [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC, \PDO::ATTR_EMULATE_PREPARES => false],
    );
    $service = new CompleteInstanceRestoreService(
        $pdo,
        $storage ?? sys_get_temp_dir(),
        BackupEncryption::passwordFromConfig($config),
    );
    $report = $mode === '--restore' ? $service->restore($file) : $service->validate($file);
} catch (InstanceExportException $e) {
    fwrite(STDERR, "Obnova/validace selhala ({$e->errorCode}): {$e->getMessage()}\n");
    exit(EXIT_INVALID);
} catch (\Throwable $e) {
    fwrite(STDERR, "Obnova/validace selhala: {$e->getMessage()}\n");
    exit(EXIT_INVALID);
}

$manifest = $report['manifest'];
echo ($mode === '--restore' ? '== Obnova dokončena ==' : '== Archiv je validní ==') . "\n";
echo 'Firma: #' . (string) ($manifest['supplier']['id'] ?? '?') . ' ' . (string) ($manifest['supplier']['name'] ?? '?') . "\n";
echo 'Tabulek: ' . count($report['counts']) . ', souborů: ' . $report['files'] . ', binárních výpisů: ' . $report['blobs'] . "\n";
if ($mode === '--restore') {
    echo "Uživatelé jsou obnoveni zablokovaní; správce jim musí poslat pozvánku nebo reset hesla.\n";
}
exit(EXIT_OK);
