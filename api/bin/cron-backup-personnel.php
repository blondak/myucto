<?php

declare(strict_types=1);

/**
 * Denní záloha personálních spisů — storage/payroll-personnel/
 * → ZIP do storage/backup/{dbname}-personnel-YYYY-MM-DD_H-i.zip.
 *
 * PROČ SAMOSTATNÝ CRON: personální spis (pracovní smlouvy, dodatky, sken
 * dokladů zaměstnance) jsou soukromá data a záměrně neleží v sekci Dokumenty
 * ani v mzdovém úložišti. Samostatná záloha se dá držet jinde a s jinými
 * právy než ostatní zálohy.
 *
 * Poznámky personálního spisu jsou v databázi (zašifrované) a jedou v jejím
 * dumpu; tady jsou jen soubory.
 *
 * Soubory se ukládají tak, jak leží — šifrované datovým klíčem osoby a pod
 * otiskem. Důvod je v {@see \MyInvoice\Service\Backup\PersonnelBackupArchiveLayout};
 * pro člověka je v ZIPu `MANIFEST.csv` a `CTI-MNE.txt`.
 *
 * Retention: 30 denních + měsíční (1. v měsíci) drženy 365 dní — stejně jako
 * u ostatních záloh.
 *
 * Vyžaduje PHP ext-zip.
 */

if (PHP_SAPI !== 'cli') exit("CLI only.\n");
require __DIR__ . '/../vendor/autoload.php';

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Config\RuntimePaths;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Backup\BackupLocation;
use MyInvoice\Service\Backup\PersonnelBackupArchiveLayout;
use MyInvoice\Service\Cron\BackupEncryption;
use MyInvoice\Service\Cron\CronRun;

$rootDir = Bootstrap::rootDir();
$config  = Config::load($rootDir);
$dbName  = (string) $config->get('db.name');

$db = new Connection($config);
$run = CronRun::start($db->pdo(), 'cron-backup-personnel');

// Resolve backup output dir — stejné pořadí jako ostatní zálohy (issue #34).
$backupDir = BackupLocation::resolve($config);
if (!is_dir($backupDir)) @mkdir($backupDir, 0755, true);

if (!class_exists(ZipArchive::class)) {
    $msg = 'PHP ext-zip není nainstalována.';
    fwrite(STDERR, "$msg\n");
    $run->finish('error', null, $msg, 1);
    exit(1);
}

// Volitelné šifrování zálohy (cfg cron.backup.password, AES-256). Nevynucuje
// se: samotné soubory už šifrované jsou a tvrdý požadavek by na instalacích
// bez hesla zálohu úplně zastavil.
$zipPassword = BackupEncryption::passwordFromConfig($config);
if (($msg = BackupEncryption::unsupportedReason($zipPassword)) !== null) {
    fwrite(STDERR, "$msg\n");
    $run->finish('error', null, $msg, 1);
    exit(1);
}

$layout = new PersonnelBackupArchiveLayout($db->pdo());
$files = $layout->all();

if (count($files) === 0) {
    echo "[" . date('Y-m-d H:i:s') . "] backup-personnel: personální spisy jsou prázdné, nic k záloze.\n";
    $run->finish('ok', ['files' => 0, 'note' => 'no personnel files']);
    exit(0);
}

$date = date('Y-m-d_H-i');
$file = "$backupDir/$dbName-personnel-$date.zip";

@unlink($file);
$zip = new ZipArchive();
if ($zip->open($file, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    fwrite(STDERR, "Cannot create ZIP: $file\n");
    $run->finish('error', null, 'cannot create zip', 1);
    exit(1);
}

/**
 * Zapíše položku a hned na ni pověsí normalizaci práv i šifrování. Kdyby
 * kterýkoli krok selhal, rozdělaný ZIP se maže — poloviční záloha je horší
 * než žádná, protože vypadá jako hotová.
 */
$pridej = static function (callable $zapis, string $entry) use ($zip, $file, $run, $zipPassword): void {
    if (!$zapis()) {
        fwrite(STDERR, "Cannot add to ZIP: $entry\n");
        $zip->close();
        @unlink($file);
        $run->finish('error', null, 'cannot add file', 1);
        exit(1);
    }
    if (!\MyInvoice\Service\Backup\BackupZipPermissions::neutralize($zip, $entry)) {
        fwrite(STDERR, "Cannot normalize ZIP entry permissions: $entry\n");
        $zip->close();
        @unlink($file);
        $run->finish('error', null, 'zip permission normalization failed', 1);
        exit(1);
    }
    if (!BackupEncryption::encryptEntry($zip, $entry, $zipPassword)) {
        fwrite(STDERR, "Cannot encrypt ZIP entry: $entry\n");
        $zip->close();
        @unlink($file);
        $run->finish('error', null, 'zip encryption failed', 1);
        exit(1);
    }
};

foreach ($files as $item) {
    $pridej(
        static fn (): bool => $zip->addFile($item['source'], $item['entry']),
        $item['entry'],
    );
}

$manifest = $layout->manifestCsv();
$pridej(
    static fn (): bool => $zip->addFromString('MANIFEST.csv', $manifest),
    'MANIFEST.csv',
);

$navod = implode("
", [
    'ZALOHA PERSONALNICH SPISU MYUCTO.CZ',
    '',
    'Obsahuje soubory personalnich spisu zamestnancu (pracovni smlouvy, dodatky',
    'a dalsi nahrane dokumenty). Poznamky personalniho spisu tu nejsou - jsou',
    'ulozene v databazi a jedou v jejim dumpu.',
    '',
    'OBNOVA',
    '  Rozbalte obsah archivu do korene instalace. Cesty uvnitr uz zacinaji',
    '  storage/ a odpovidaji cilovemu rozlozeni, nic se neprejmenovava.',
    '',
    'DULEZITE',
    '  Soubory jsou sifrovane klicem zamestnance a adresovane svym otiskem.',
    '  Samy o sobe se otevrit NEDAJI a bez odpovidajiciho dumpu databaze jsou',
    '  k nicemu - obnovujte je vzdy spolu s dumpem ze stejneho dne.',
    '',
    '  Co se pod kterym otiskem skryva, je v MANIFEST.csv.',
    '',
]);
$pridej(
    static fn (): bool => $zip->addFromString('CTI-MNE.txt', $navod),
    'CTI-MNE.txt',
);

if (!$zip->close()) {
    @unlink($file);
    fwrite(STDERR, "ZIP close failed.\n");
    $run->finish('error', null, 'zip close failed', 1);
    exit(1);
}

if (!is_file($file) || filesize($file) < 100) {
    fwrite(STDERR, "ZIP backup is empty.\n");
    @unlink($file);
    $run->finish('error', null, 'empty zip', 1);
    exit(1);
}

$size = round(filesize($file) / 1024, 1);
$count = count($files);
echo "[" . date('Y-m-d H:i:s') . "] backup-personnel: " . basename($file) . " ({$count} souborů, {$size} KB)\n";

$report = ['file' => basename($file), 'files' => $count, 'size_kb' => $size];
if ($zipPassword !== '') {
    $report['encrypted'] = 'AES-256';
}

// Retention: smaž zálohy starší 30 dní (1. v měsíci drž 365 dní).
// Filtrujeme jen vlastní prefix "{dbName}-personnel-", ať se nedotkne cizích záloh.
$prefix = $dbName . '-personnel-';
$existing = glob($backupDir . '/' . $prefix . '*.zip') ?: [];
$now = time();
foreach ($existing as $f) {
    if (!preg_match('/-(\d{4}-\d{2}-\d{2})(?:_\d{2}-\d{2})?\.zip$/', $f, $m)) continue;
    $age = $now - strtotime($m[1]);
    $isMonthly = str_ends_with($m[1], '-01');
    $maxAge = $isMonthly ? 365 * 86400 : 30 * 86400;
    if ($age > $maxAge) {
        @unlink($f);
        echo "  - retention: smazáno " . basename($f) . "\n";
        $report['retention_purged'] = ($report['retention_purged'] ?? 0) + 1;
    }
}

$run->finish('ok', $report);
