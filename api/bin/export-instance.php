<?php

declare(strict_types=1);

/**
 * H-14 — kompletní export dat instance (per firma).
 *
 * Jeden skript ve třech rolích:
 *
 *   1) RUČNÍ EXPORT z příkazové řádky (odchod zákazníka, archivace na roky):
 *        php api/bin/export-instance.php --supplier=3
 *        php api/bin/export-instance.php --supplier=3 --parts=data,documents --from=2024-01-01 --to=2024-12-31
 *        php api/bin/export-instance.php --supplier=3 --out=/mnt/archiv
 *
 *   2) WORKER na pozadí — takhle ho spouští API akce, aby HTTP požadavek nedržel
 *      běh, který trvá minuty až hodiny:
 *        php api/bin/export-instance.php --job-id=12
 *
 *   3) ÚKLID expirovaných archivů (do cronu vedle ostatních cleanup úloh):
 *        php api/bin/export-instance.php --cleanup
 *
 *   Přehled běhů firmy:
 *        php api/bin/export-instance.php --list --supplier=3
 *
 * Souběh: jeden běžící export na firmu. Hlídá to UNIQUE index v `instance_exports`
 * i souborový zámek (InstanceExportLock) — druhý pokus skončí exit kódem 4, ne frontou.
 *
 * Exit kódy: 0 ok · 1 chyba běhu · 2 chybné argumenty · 3 firma neexistuje
 *            4 už běží · 5 zrušeno uživatelem
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}

require __DIR__ . '/../vendor/autoload.php';

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Export\Instance\InstanceExportCancelled;
use MyInvoice\Service\Export\Instance\InstanceExportException;
use MyInvoice\Service\Export\Instance\InstanceExportJobStore;
use MyInvoice\Service\Export\Instance\InstanceExportService;

$argvList = $argv ?? $_SERVER['argv'] ?? [];

/** @return array<string,string|bool> */
$parseArgs = static function (array $args): array {
    $out = [];
    foreach (array_slice($args, 1) as $arg) {
        if (!str_starts_with($arg, '--')) {
            continue;
        }
        $body = substr($arg, 2);
        $eq = strpos($body, '=');
        if ($eq === false) {
            $out[$body] = true;
        } else {
            $out[substr($body, 0, $eq)] = substr($body, $eq + 1);
        }
    }
    return $out;
};
$opts = $parseArgs($argvList);

$usage = <<<TXT
Kompletní export dat firmy (H-14).

  --supplier=N            ID firmy (povinné mimo --job-id / --cleanup)
  --parts=a,b,c           části: restore,data,documents,files,vat_exports,closing_packages (výchozí: všechny)
                          restore automaticky přidá data, documents a files
  --from=YYYY-MM-DD       omezení dokladů (obojí, nebo ani jedno)
  --to=YYYY-MM-DD
  --out=DIR               cíl místo storage/instance-exports/sup-N
  --job-id=N              režim workeru (běh navázaný na řádek instance_exports)
  --cleanup               smaže archivy po expiraci
  --list                  vypíše běhy firmy
  --help

TXT;

if (isset($opts['help'])) {
    fwrite(STDOUT, $usage);
    exit(0);
}

$app = Bootstrap::buildApp();
$container = $app->getContainer();
/** @var InstanceExportService $service */
$service = $container->get(InstanceExportService::class);
/** @var InstanceExportJobStore $jobs */
$jobs = $container->get(InstanceExportJobStore::class);

set_time_limit(0);
ignore_user_abort(true);

$stamp = static fn (): string => '[' . date('Y-m-d H:i:s') . '] ';

// ── 3) úklid expirovaných ────────────────────────────────────────────────────
if (isset($opts['cleanup'])) {
    // Zaseknuté běhy (mrtvý proces) se uklidí spolu s tím — jinak by blokovaly
    // UNIQUE index a firma by už nikdy nespustila další export.
    $reaped = $jobs->reapStale();
    $removed = $service->cleanupExpired();
    fwrite(STDOUT, $stamp() . "export-instance cleanup: smazáno {$removed} archivů, uklizeno {$reaped} zaseknutých běhů.\n");
    exit(0);
}

// ── 2) worker ────────────────────────────────────────────────────────────────
if (isset($opts['job-id'])) {
    $jobId = (int) $opts['job-id'];
    if ($jobId <= 0) {
        fwrite(STDERR, "Neplatné --job-id.\n");
        exit(2);
    }
    $service->run($jobId);
    $job = $jobs->findById($jobId);
    $status = (string) ($job['status'] ?? 'unknown');
    fwrite(STDOUT, $stamp() . "export-instance job #{$jobId}: {$status}\n");
    if ($status === 'completed') {
        exit(0);
    }
    exit($status === 'cancelled' ? 5 : 1);
}

$supplierId = (int) ($opts['supplier'] ?? 0);
if ($supplierId <= 0) {
    fwrite(STDERR, "Chybí --supplier=N.\n\n" . $usage);
    exit(2);
}

// ── výpis běhů ───────────────────────────────────────────────────────────────
if (isset($opts['list'])) {
    $rows = $jobs->listForSupplier($supplierId, 20);
    if ($rows === []) {
        fwrite(STDOUT, "Firma #{$supplierId} zatím nemá žádný export.\n");
        exit(0);
    }
    foreach ($rows as $row) {
        fwrite(STDOUT, sprintf(
            "#%-5d %-10s %-20s %12s  %s  expiruje %s\n",
            $row['id'],
            $row['status'],
            (string) $row['created_at'],
            $row['size_bytes'] === null ? '-' : number_format((float) $row['size_bytes'] / 1048576, 1, ',', ' ') . ' MB',
            substr((string) ($row['sha256'] ?? ''), 0, 12) ?: '-',
            (string) ($row['expires_at'] ?? '-'),
        ));
    }
    exit(0);
}

// ── 1) ruční export ──────────────────────────────────────────────────────────
$pdo = $container->get(Connection::class)->pdo();
$stmt = $pdo->prepare('SELECT company_name FROM supplier WHERE id = ?');
$stmt->execute([$supplierId]);
$company = $stmt->fetchColumn();
if ($company === false) {
    fwrite(STDERR, "Firma #{$supplierId} neexistuje.\n");
    exit(3);
}

$parts = InstanceExportService::normalizeParts(
    is_string($opts['parts'] ?? null) ? array_map('trim', explode(',', $opts['parts'])) : [],
);

$dateFrom = is_string($opts['from'] ?? null) ? $opts['from'] : null;
$dateTo = is_string($opts['to'] ?? null) ? $opts['to'] : null;
if (($dateFrom === null) !== ($dateTo === null)) {
    fwrite(STDERR, "--from a --to se zadávají obojí, nebo ani jedno.\n");
    exit(2);
}
foreach (['from' => $dateFrom, 'to' => $dateTo] as $name => $value) {
    if ($value !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
        fwrite(STDERR, "Neplatné --{$name} (očekává se YYYY-MM-DD).\n");
        exit(2);
    }
}

$targetDir = is_string($opts['out'] ?? null) && $opts['out'] !== '' ? $opts['out'] : null;

fwrite(STDOUT, $stamp() . "Export firmy #{$supplierId} ({$company}); části: " . implode(', ', $parts)
    . ($dateFrom !== null ? "; rozsah {$dateFrom} … {$dateTo}" : '') . "\n");

try {
    $result = $service->runForSupplier(
        $supplierId,
        $parts,
        $dateFrom,
        $dateTo,
        $targetDir,
        static function (string $line) use ($stamp): void {
            fwrite(STDOUT, $stamp() . '  ' . $line . "\n");
        },
    );
} catch (InstanceExportCancelled) {
    fwrite(STDERR, "Export zrušen.\n");
    exit(5);
} catch (InstanceExportException $e) {
    fwrite(STDERR, 'Export selhal (' . $e->errorCode . '): ' . $e->getMessage() . "\n");
    exit($e->errorCode === 'already_running' ? 4 : 1);
} catch (\Throwable $e) {
    fwrite(STDERR, 'Export selhal: ' . $e->getMessage() . "\n");
    exit(1);
}

fwrite(STDOUT, $stamp() . "Hotovo.\n");
fwrite(STDOUT, '  soubor:   ' . $result['abs_path'] . "\n");
fwrite(STDOUT, '  velikost: ' . number_format((float) $result['size_bytes'] / 1048576, 1, ',', ' ') . " MB\n");
fwrite(STDOUT, '  položek:  ' . (string) ($result['manifest']['totals']['entries'] ?? 0) . "\n");
fwrite(STDOUT, '  SHA-256:  ' . $result['sha256'] . "\n");
fwrite(STDOUT, '  šifrováno: ' . ($result['encrypted'] ? 'AES-256' : 'NE (cfg cron.backup.password je prázdné)') . "\n");
exit(0);
