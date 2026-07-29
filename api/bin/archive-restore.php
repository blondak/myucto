<?php

declare(strict_types=1);

/**
 * archive-restore.php — validace a OBNOVA per-firma archivu (Fáze F, audit 2026-07).
 *
 * Režimy:
 *   --dry-run   jen validace (sha256 tabulek i binárek, počty řádků, schema_version,
 *               JSON parsovatelnost, PK sloupce, FK sanity). Nezapisuje do DB.
 *   --restore   SKUTEČNÁ obnova archivu jako NOVÁ firma (nikdy nepřepisuje existující).
 *               Import běží v jedné transakci s remapem všech id; při chybě rollback.
 *
 * Použití:
 *   php api/bin/archive-restore.php --file=<cesta.zip> --dry-run
 *   php api/bin/archive-restore.php --file=<cesta.zip> --restore
 *
 * Bez režimu skript vypíše nápovědu a skončí exit 2 (bezpečnost — obnova jen explicitně).
 *
 * ── MAPOVÁNÍ ID PŘI OBNOVĚ (implementuje ArchiveRestoreService) ───────────────
 * Obnova zakládá NOVÝ řádek supplier a všechna AUTO_INCREMENT id remapuje na nová
 * (per tabulka mapa old→new, FK sloupce se překládají při insertu). FK graf se čte
 * z information_schema, takže se remapuje KAŽDÝ FK na tenant tabulku (jinak by staré
 * id tiše ukázalo na cizí firmu). Cykly (supplier↔currencies, manufacturers↔stock_media,
 * self-ref reversed_by/parent_id) a dopředné reference (cash_documents.journal_entry_id)
 * řeší druhý průchod. Polymorfní journal_entries.source_id se remapuje dle source_type
 * (dokladové typy přes mapy, závěrkové sloty přes mapu období). Uživatelská id
 * (created_by/posted_by/uploaded_by) se ponechávají (users je globální tabulka →
 * auditní stopa zůstane; cross-instance obnova vyžaduje tytéž uživatele). Po importu
 * se ověří Σ MD = Σ D per období; při porušení podvojnosti se celá obnova vrátí.
 */

require __DIR__ . '/../vendor/autoload.php';

use MyInvoice\Bootstrap;
use MyInvoice\Service\Accounting\Archive\ArchiveRestoreService;
use MyInvoice\Service\Accounting\Archive\RestoreException;

const EXIT_OK = 0;
const EXIT_INVALID = 1;
const EXIT_USAGE = 2;

$file = null;
$dryRun = false;
$restore = false;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--file=')) {
        $file = substr($arg, 7);
    } elseif ($arg === '--dry-run') {
        $dryRun = true;
    } elseif ($arg === '--restore') {
        $restore = true;
    } else {
        fwrite(STDERR, "Neznámý argument: {$arg}\n");
        fwrite(STDERR, "Použití: php archive-restore.php --file=<cesta.zip> [--dry-run|--restore]\n");
        exit(EXIT_USAGE);
    }
}

if ($file === null || $file === '') {
    fwrite(STDERR, "Chybí --file=<cesta.zip>.\n");
    exit(EXIT_USAGE);
}
if (!$dryRun && !$restore) {
    echo "Zadej režim: --dry-run (jen validace) nebo --restore (obnova jako nová firma).\n";
    echo "Plán mapování id a pořadí tabulek je dokumentován v hlavičce tohoto skriptu.\n";
    exit(EXIT_USAGE);
}
if ($dryRun && $restore) {
    fwrite(STDERR, "Zvol jen jeden režim (--dry-run NEBO --restore).\n");
    exit(EXIT_USAGE);
}
if (!is_file($file)) {
    fwrite(STDERR, "Soubor neexistuje: {$file}\n");
    exit(EXIT_INVALID);
}

// ── REŽIM --restore ──────────────────────────────────────────────────────────
if ($restore) {
    try {
        $service = Bootstrap::buildApp()->getContainer()->get(ArchiveRestoreService::class);
    } catch (\Throwable $e) {
        fwrite(STDERR, "DI nedostupné (chybí cfg.php / DB?): " . $e->getMessage() . "\n");
        exit(EXIT_INVALID);
    }
    echo "== Obnova archivu jako NOVÁ firma ==\n";
    echo "Soubor: {$file}\n";
    try {
        $report = $service->restore($file);
    } catch (RestoreException $e) {
        fwrite(STDERR, "\n== OBNOVA SELHALA ({$e->errorCode}) ==\n  ✗ " . $e->getMessage() . "\n");
        exit(EXIT_INVALID);
    }
    echo "Nová firma: supplier #{$report['new_supplier_id']} (z archivu firmy #{$report['old_supplier_id']})\n";
    echo "Obnoveno tabulek: " . count($report['counts']) . "\n";
    foreach ($report['counts'] as $table => $rows) {
        if ($rows > 0) {
            printf("  %-30s %6d řádků\n", $table, $rows);
        }
    }
    echo "Bilanční kontrola Σ MD = Σ D per období:\n";
    foreach ($report['balance'] as $b) {
        printf("  období #%d: MD %s / D %s (rozdíl %s)\n", $b['period_id'], $b['debit'], $b['credit'], $b['diff']);
    }
    if ($report['warnings'] !== []) {
        echo "Varování (" . count($report['warnings']) . "):\n";
        foreach ($report['warnings'] as $w) {
            echo "  ! {$w}\n";
        }
    }
    echo "\n== Obnova dokončena ==\n";
    exit(EXIT_OK);
}

// ── REŽIM --dry-run ──────────────────────────────────────────────────────────
$errors = [];
$tmpDir = sys_get_temp_dir() . '/myucto-archive-' . bin2hex(random_bytes(8));
if (!mkdir($tmpDir, 0700, true)) {
    fwrite(STDERR, "Nelze vytvořit temp adresář: {$tmpDir}\n");
    exit(EXIT_INVALID);
}

register_shutdown_function(static function () use ($tmpDir): void {
    if (!is_dir($tmpDir)) {
        return;
    }
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($tmpDir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($items as $item) {
        $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    }
    @rmdir($tmpDir);
});

echo "== Dry-run validace archivu ==\n";
echo "Soubor: {$file}\n";

// 1) rozbalení
$zip = new ZipArchive();
if ($zip->open($file) !== true) {
    fwrite(STDERR, "ZIP nelze otevřít.\n");
    exit(EXIT_INVALID);
}
if (!$zip->extractTo($tmpDir)) {
    fwrite(STDERR, "ZIP nelze rozbalit do {$tmpDir}.\n");
    $zip->close();
    exit(EXIT_INVALID);
}
$zip->close();

// 2) manifest
$manifestPath = $tmpDir . '/manifest.json';
if (!is_file($manifestPath)) {
    fwrite(STDERR, "manifest.json v archivu chybí.\n");
    exit(EXIT_INVALID);
}
$manifest = json_decode((string) file_get_contents($manifestPath), true);
if (!is_array($manifest)) {
    fwrite(STDERR, "manifest.json není parsovatelný JSON.\n");
    exit(EXIT_INVALID);
}
if (($manifest['format'] ?? null) !== 'myucto-archive') {
    $errors[] = "manifest: neočekávaný formát '" . (string) ($manifest['format'] ?? '') . "' (čekám 'myucto-archive')";
}
$version = (int) ($manifest['version'] ?? 0);
if ($version < 1 || $version > 2) {
    $errors[] = 'manifest: nepodporovaná verze ' . (string) ($manifest['version'] ?? '?');
}
$supplier = $manifest['supplier'] ?? [];
echo 'Firma: #' . (string) ($supplier['id'] ?? '?') . ' ' . (string) ($supplier['name'] ?? '?')
    . ' (IČO ' . (string) ($supplier['ico'] ?? '—') . "), exportováno " . (string) ($manifest['exported_at'] ?? '?') . "\n";

// 3) schema_version vs. lokální migrace
$schemaVersion = (string) ($manifest['schema_version'] ?? '');
$migrationsDir = Bootstrap::rootDir() . '/db/migrations';
$localMigrations = array_map('basename', glob($migrationsDir . '/*.sql') ?: []);
sort($localMigrations, SORT_STRING);
$localMax = $localMigrations === [] ? '' : end($localMigrations);
if ($schemaVersion === '' || $schemaVersion === 'unknown') {
    $errors[] = 'manifest: schema_version chybí';
} elseif ($localMax === '' || strcmp($schemaVersion, $localMax) > 0) {
    $errors[] = "schema_version archivu ({$schemaVersion}) je NOVĚJŠÍ než lokální migrace ({$localMax}) — obnova by vyžadovala novější aplikaci";
} else {
    echo "Schema: archiv {$schemaVersion} <= lokální {$localMax} OK\n";
}

// 4–6) per tabulka: sha256, počty řádků, JSON parsovatelnost, PK sloupce
$tables = $manifest['tables'] ?? [];
if (!is_array($tables) || $tables === []) {
    $errors[] = 'manifest: sekce tables chybí nebo je prázdná';
    $tables = [];
}

$pkColumns = [
    'accounting_supplier_settings' => ['supplier_id'],
    'exchange_rates' => ['rate_date', 'currency_code'],
    'stock_levels' => ['supplier_id', 'warehouse_id', 'stock_item_id'],
    'stock_item_categories' => ['stock_item_id', 'category_id'],
    'stock_item_tags' => ['stock_item_id', 'tag_id'],
];
$ids = [];          // tabulka → set id (pro FK sanity)
$fkValues = [];     // tabulka → sloupec → list hodnot

foreach ($tables as $table => $info) {
    $path = $tmpDir . '/' . $table . '.jsonl';
    $expectedRows = (int) ($info['rows'] ?? -1);
    $expectedSha = (string) ($info['sha256'] ?? '');
    if (!is_file($path)) {
        if ($expectedRows > 0) {
            $errors[] = "{$table}: soubor {$table}.jsonl v archivu chybí";
        }
        continue;
    }
    $actualSha = hash_file('sha256', $path);
    if ($actualSha !== $expectedSha) {
        $errors[] = "{$table}: sha256 nesouhlasí (manifest {$expectedSha}, soubor {$actualSha})";
    }

    $pk = $pkColumns[$table] ?? ['id'];
    $rows = 0;
    $jsonErrors = 0;
    $pkMissing = 0;
    $fh = fopen($path, 'rb');
    if ($fh === false) {
        $errors[] = "{$table}: soubor nelze číst";
        continue;
    }
    while (($line = fgets($fh)) !== false) {
        if (trim($line) === '') {
            continue;
        }
        $rows++;
        $row = json_decode($line, true);
        if (!is_array($row)) {
            $jsonErrors++;
            continue;
        }
        foreach ($pk as $col) {
            if (!array_key_exists($col, $row)) {
                $pkMissing++;
                break;
            }
        }
        if (isset($row['id'])) {
            $ids[$table][(string) $row['id']] = true;
        }
        foreach ([
            'journal_entry_lines' => ['entry_id'],
            'invoice_items' => ['invoice_id'],
            'journal_entries' => ['period_id'],
            'cash_document_vat_lines' => ['cash_document_id'],
        ][$table] ?? [] as $fkCol) {
            if (isset($row[$fkCol])) {
                $fkValues[$table][$fkCol][] = (string) $row[$fkCol];
            }
        }
    }
    fclose($fh);

    if ($rows !== $expectedRows) {
        $errors[] = "{$table}: počet řádků nesouhlasí (manifest {$expectedRows}, soubor {$rows})";
    }
    if ($jsonErrors > 0) {
        $errors[] = "{$table}: {$jsonErrors} řádků není parsovatelný JSON";
    }
    if ($pkMissing > 0) {
        $errors[] = "{$table}: {$pkMissing} řádků nemá PK sloupce (" . implode('+', $pk) . ')';
    }
    printf("  %-30s %6d řádků, sha256 %s\n", $table, $rows, $actualSha === $expectedSha ? 'OK' : 'FAIL');
}

// 6b) binárky příloh deníku (sekce files): sha256
foreach (($manifest['files'] ?? []) as $zipName => $info) {
    if (($info['missing'] ?? false) === true) {
        echo "  {$zipName}: chyběla při exportu (přeskočeno)\n";
        continue;
    }
    $path = $tmpDir . '/' . $zipName;
    if (!is_file($path)) {
        $errors[] = "{$zipName}: binárka v archivu chybí";
        continue;
    }
    $actual = hash_file('sha256', $path);
    if ($actual !== (string) ($info['sha256'] ?? '')) {
        $errors[] = "{$zipName}: sha256 binárky nesouhlasí";
    } else {
        printf("  %-40s sha256 OK\n", $zipName);
    }
}

// 7) FK sanity
$fkChecks = [
    ['journal_entry_lines', 'entry_id', 'journal_entries'],
    ['invoice_items', 'invoice_id', 'invoices'],
    ['journal_entries', 'period_id', 'accounting_periods'],
    ['cash_document_vat_lines', 'cash_document_id', 'cash_documents'],
];
foreach ($fkChecks as [$childTable, $fkCol, $parentTable]) {
    $values = $fkValues[$childTable][$fkCol] ?? [];
    if ($values === []) {
        continue;
    }
    $parentIds = $ids[$parentTable] ?? [];
    $orphans = 0;
    foreach ($values as $v) {
        if (!isset($parentIds[$v])) {
            $orphans++;
        }
    }
    if ($orphans > 0) {
        $errors[] = "FK sanity: {$childTable}.{$fkCol} má {$orphans} hodnot bez rodiče v {$parentTable}";
    } else {
        echo "FK sanity: {$childTable}.{$fkCol} → {$parentTable} OK\n";
    }
}

if ($errors !== []) {
    echo "\n== VALIDACE SELHALA (" . count($errors) . " chyb) ==\n";
    foreach ($errors as $e) {
        fwrite(STDERR, "  ✗ {$e}\n");
    }
    exit(EXIT_INVALID);
}

echo "\n== Archiv je validní ==\n";
echo "Skutečnou obnovu jako novou firmu spustíš přepínačem --restore.\n";
exit(EXIT_OK);
