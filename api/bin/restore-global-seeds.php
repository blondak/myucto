<?php

declare(strict_types=1);

/**
 * Dotah globálních seedů z migrací (svátky, katalog klíčových slov nákladů, příjemci
 * podání, značky převodů v migracích).
 *
 * PROČ TO EXISTUJE
 * ------------------------------------------------------------------------------
 * `reset.php` mazal tabulky, které v jeho keep-listu chyběly, a mezi nimi i globální
 * číselníky naseedované migracemi. `migrate.php` je nevrátí, protože migrace jsou
 * evidované jako proběhlé. Prázdný číselník svátků pak znamenal, že lhůty mezd
 * a podání běžely z pojistky v kódu.
 *
 * Data se NEOPISUJÍ: přehrávají se INSERT/UPDATE statementy migrací z
 * GlobalSeedTables::RESTORABLE. INSERTy jsou idempotentní, nic se nemaže
 * a per-tenant řádky zůstávají. Na zdravé instalaci je skript no-op.
 *
 * Použití:
 *   php api/bin/restore-global-seeds.php            # náhled, nic nezapíše
 *   php api/bin/restore-global-seeds.php --apply    # doplní chybějící řádky
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit("Tento skript lze spustit pouze z příkazové řádky (CLI).\n");
}

require __DIR__ . '/../vendor/autoload.php';

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\System\GlobalSeedRestorer;

$apply = in_array('--apply', $argv, true);
$rootDir = Bootstrap::rootDir();

try {
    $config = Config::load($rootDir);
    $pdo = (new Connection($config))->pdo();
} catch (\Throwable $e) {
    fwrite(STDERR, "[global-seeds] Chyba připojení k DB: " . $e->getMessage() . "\n");
    exit(1);
}

echo "[global-seeds] DB: " . $config->get('db.name') . "\n";

$restorer = new GlobalSeedRestorer($pdo, $rootDir . '/db/migrations');

try {
    $result = $apply ? $restorer->apply() : $restorer->pending();
} catch (\Throwable $e) {
    fwrite(STDERR, "[global-seeds] Dotah selhal: " . $e->getMessage() . "\n");
    exit(1);
}

if ($result === []) {
    echo "[global-seeds] Vše kompletní, nic k doplnění.\n";
} else {
    foreach ($result as $table => $rows) {
        printf("[global-seeds] %-32s %s %d řádků\n", $table, $apply ? 'doplněno' : 'chybí', $rows);
    }
    if (!$apply) {
        echo "[global-seeds] Náhled, nic se nezapsalo. Pro doplnění spusťte s --apply.\n";
    }
}

// V náhledu se nic nezapsalo, takže číselník, který by dotah naplnil, je pořád prázdný.
$empty = array_values(array_diff($restorer->emptyCodebooks(), $apply ? [] : array_keys($result)));
if ($empty !== []) {
    fwrite(STDERR, "[global-seeds] Prázdné globální číselníky, které skript neobnoví: " . implode(', ', $empty) . "\n");
    exit($apply ? 2 : 0);
}

exit(0);
