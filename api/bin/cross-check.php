<?php

declare(strict_types=1);

/**
 * Křížové kontroly účetních a daňových výstupů — vrstva L4 auditního plánu (fáze F7).
 *
 * Použití:
 *   php api/bin/cross-check.php                       (všechny uzavřené roky, supplier 1)
 *   php api/bin/cross-check.php --year=2025
 *   php api/bin/cross-check.php --supplier=1 --year=2024
 *
 * Spočítá totéž číslo DVĚMA NEZÁVISLÝMI cestami a porovná (viz {@see CrossCheckSuite}):
 * přiznání DPH ↔ KH ↔ SH ↔ obrat 343, aktiva ↔ pasiva, VH v rozvaze ↔ VH ve VZZ,
 * VZZ ↔ hlavní kniha, Σ MD ↔ Σ D v obratové předvaze.
 *
 * READ-ONLY — buildery sestavují výkazy in-memory, nic se nezapisuje. Lze pustit
 * i proti produkční databázi.
 *
 * EXIT KÓD:
 *   0 = vše sedí
 *   1 = nalezen nesoulad
 *   2 = nebylo co kontrolovat (žádné uzavřené období)
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}
require __DIR__ . '/../vendor/autoload.php';

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Report\CrossCheckSuite;

$opts = getopt('', ['supplier::', 'year::']);
$supplierId = (int) ($opts['supplier'] ?? 1);

$container = Bootstrap::buildApp()->getContainer();
$db = $container->get(Connection::class);
$suite = $container->get(CrossCheckSuite::class);

echo 'Křížové kontroly — databáze: ' . $db->pdo()->query('SELECT DATABASE()')->fetchColumn()
    . ", firma: {$supplierId}\n";

$years = isset($opts['year']) ? [(int) $opts['year']] : $suite->closedYears($supplierId);
if ($years === []) {
    fwrite(STDERR, "CHYBA: firma nemá žádné uzavřené účetní období — není co křížově kontrolovat.\n");
    exit(2);
}

$fmt = static fn (?float $n): string => $n === null ? '—' : number_format($n, 2, ',', ' ');

$mismatches = 0;
$checked = 0;

foreach ($years as $year) {
    echo "\n=== Rok {$year} ===\n";
    foreach ($suite->run($supplierId, $year) as $r) {
        if ($r['skipped']) {
            printf("[SKIP] %s — %s\n", $r['label'], (string) $r['note']);
            continue;
        }
        $checked++;
        if ($r['ok']) {
            printf("[ OK ] %s\n", $r['label']);
            continue;
        }
        $mismatches++;
        printf(
            "[NESOULAD] %s\n           %s = %s, %s = %s, rozdíl %s\n",
            $r['label'],
            $r['a_label'],
            $fmt($r['a']),
            $r['b_label'],
            $fmt($r['b']),
            $fmt($r['difference']),
        );
        if ($r['note'] !== null) {
            echo '           ' . (string) $r['note'] . "\n";
        }
    }
}

if ($checked === 0) {
    fwrite(STDERR, "\nCHYBA: nevyhodnotila se ANI JEDNA kontrola.\n");
    exit(2);
}

printf("\nVyhodnoceno %d kontrol, nesouladů: %d\n", $checked, $mismatches);
exit($mismatches > 0 ? 1 : 0);
