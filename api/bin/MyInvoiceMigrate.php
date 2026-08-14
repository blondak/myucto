<?php

declare(strict_types=1);

/**
 * MyInvoiceMigrate — přenese KOMPLETNÍ data z instalace MyInvoice do MyÚčta.
 *
 * Kopíruje OPRAVDU VŠECHNO: uživatele, jejich členství ve firmách (user_suppliers),
 * dodavatele (supplier) a jejich konfiguraci, klienty, ceník, projekty, doklady,
 * položky, banku, výpisy, dokumenty, podpisy, API tokeny, IMAP/e-mail nastavení,
 * importy, daňová podání, CRM, cache… — celý datový obsah.
 *
 * JEDEN PŘÍKAZ, ŽÁDNÉ RUČNÍ `migrate.php`. Skript sám:
 *   1. zjistí stav cílové DB (prázdná / upstream schéma / plné MyÚčto schéma),
 *   2. připraví cíl tak, aby měl kam uložit všechna data zdroje,
 *   3. ověří preflightem, že se nic tiše neztratí,
 *   4. přenese data,
 *   5. dokončí schéma MyÚčta zbylými migracemi 1000+ včetně backfillů.
 *
 * Použití — zdroj na TÉMŽE serveru (název databáze):
 *   php api/bin/MyInvoiceMigrate.php myinvoice
 *   php api/bin/MyInvoiceMigrate.php myinvoice --yes
 *
 * Použití — zdroj na JINÉM serveru / v jiném Docker kontejneru (URL):
 *   php api/bin/MyInvoiceMigrate.php mysql://user:heslo@myinvoice-db:3306/myinvoice --yes
 *   (totéž lze předat přes --source-url= nebo ENV MYINVOICE_SOURCE_URL)
 *
 * Docker → Docker viz manual/06_Prevod_z_MyInvoice.md, sekce „Převod v Dockeru".
 *
 * Přepínače:
 *   --yes / -y        bez interaktivního potvrzení
 *   --no-truncate     nepromazávat cílové tabulky před kopií
 *   --tables=a,b,c    kopírovat jen vyjmenované tabulky
 *   --allow-missing   nezastavit se na datech, pro která cíl nemá kam (jen varovat)
 *   --no-prepare      nepřipravovat schéma cíle (expert; cíl už je připravený)
 *   --no-finalize     nespouštět po importu dokončovací migrace 1000+
 *   --keep-schema     nepřestavovat cíl, i když už má aplikované migrace 1000+
 *   --stream          vynutit proudovou kopii i pro zdroj na témže serveru
 *   --batch=N         velikost dávky při proudové kopii (default 2000 řádků)
 *
 * Mechanika:
 *   • Zdroj na témže serveru → čistě server-side `INSERT ... SELECT` přes kvalifikované
 *     názvy `zdroj`.`tabulka` (žádné marshalování řádků v PHP, rychlé). Zdroj jinde →
 *     druhé spojení a proudová kopie po dávkách (nebufferovaný SELECT, konstantní paměť).
 *   • Kopíruje se PRŮNIK sloupců (cíl ∩ zdroj). Generované/persistentní sloupce
 *     (STORED/VIRTUAL GENERATED — např. invoices.amount_to_pay) se z insertu vynechávají,
 *     DB si je dopočítá sama.
 *   • FOREIGN_KEY_CHECKS=0 po celou dobu → nezáleží na pořadí tabulek; explicitní
 *     ID (PK) se kopírují 1:1, takže cizí klíče zůstanou konzistentní.
 *   • `migrations` se NIKDY nekopíruje — cíl si drží vlastní evidenci schématu.
 *   • Kopírují se DATA tabulek (včetně SYSTEM VERSIONED); VIEW, procedury a triggery
 *     dodá `migrate.php`.
 *
 * Proč se schéma připravuje na dvakrát:
 *   MyÚčto přidává migrace 1000+ a některé z nich nejen vytvářejí tabulky, ale i
 *   DOPLŇUJÍ DATA existujících uživatelů a firem (role, oprávnění, historie účetního
 *   režimu, backfilly). Kdyby proběhly před importem, běžely by nad prázdnou databází
 *   a už se nezopakují — v tabulce `migrations` jsou označené jako hotové. Proto se
 *   před importem staví jen upstream schéma (`--below=1000`) a po importu se dojede
 *   zbytek. Pozor: MyÚčto přečíslovalo některé upstream featury nad 1000
 *   (`user_suppliers` → 1000, ceník → 1121), takže samotné `--below=1000` by o ně
 *   data připravilo. Skript si tyhle čistě DDL migrace dohledá a doplní sám.
 *
 * Pozn.: kopírují se i globální číselníky (countries, vat_rates, units, …). Cíl se
 *   tím sjednotí se zdrojem. Pokud chceš ponechat číselníky cíle beze změny, spusť
 *   s `--tables=` jen na vybraných byznys tabulkách, nebo s `--no-truncate` a přijmi
 *   případné duplicity/PK kolize na číselnících.
 */

// === CLI guard — odmítni HTTP přístup ===
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit("Tento skript lze spustit pouze z příkazové řádky (CLI).\n");
}

require __DIR__ . '/../vendor/autoload.php';

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;

// Hranice mezi upstream schématem MyInvoice a rozšířeními MyÚčta.
const MYUCTO_MIGRATION_FLOOR = 1000;

// PHP 8.5 přesunulo PDO konstanty pod Pdo\Mysql; stará jména jsou deprecated.
// Ternární operátor vyhodnotí jen jednu větev, takže deprecace nezazní.
$bufferedQueryAttr = class_exists('\Pdo\Mysql')
    ? \Pdo\Mysql::ATTR_USE_BUFFERED_QUERY
    : \PDO::MYSQL_ATTR_USE_BUFFERED_QUERY;

// --- Parse argumentů: první ne-flag pozicionální = zdroj (název DB nebo URL) ---
$rawArgs      = array_slice($argv, 1);
$sourceArg    = null;
$sourceUrl    = getenv('MYINVOICE_SOURCE_URL') ?: null;
$autoYes      = false;
$noTruncate   = false;
$allowMissing = false;
$noPrepare    = false;
$noFinalize   = false;
$keepSchema   = false;
$forceStream  = false;
$batchSize    = 2000;
$onlyTables   = null; // null = všechny; jinak pole názvů

foreach ($rawArgs as $arg) {
    if ($arg === '--yes' || $arg === '-y') {
        $autoYes = true;
    } elseif ($arg === '--no-truncate') {
        $noTruncate = true;
    } elseif ($arg === '--allow-missing') {
        $allowMissing = true;
    } elseif ($arg === '--no-prepare') {
        $noPrepare = true;
    } elseif ($arg === '--no-finalize') {
        $noFinalize = true;
    } elseif ($arg === '--keep-schema') {
        $keepSchema = true;
    } elseif ($arg === '--stream') {
        $forceStream = true;
    } elseif (str_starts_with($arg, '--source-url=') || str_starts_with($arg, '--source-dsn=')) {
        $sourceUrl = substr($arg, (int) strpos($arg, '=') + 1);
    } elseif (str_starts_with($arg, '--batch=')) {
        $batchSize = max(100, (int) substr($arg, strlen('--batch=')));
    } elseif (str_starts_with($arg, '--tables=')) {
        $list = array_filter(array_map('trim', explode(',', substr($arg, strlen('--tables=')))));
        $onlyTables = $list ? array_values($list) : null;
    } elseif (str_starts_with($arg, '-')) {
        fwrite(STDERR, "[migrate] Neznámý přepínač: {$arg}\n");
        exit(1);
    } elseif ($sourceArg === null) {
        $sourceArg = $arg;
    } else {
        fwrite(STDERR, "[migrate] Nečekaný argument: {$arg}\n");
        exit(1);
    }
}

// Zdroj zadaný pozicionálně jako URL je totéž co --source-url.
if ($sourceArg !== null && preg_match('~^(mysql|mariadb)://~i', $sourceArg) === 1) {
    $sourceUrl = $sourceArg;
    $sourceArg = null;
}

if ($sourceArg === null && ($sourceUrl === null || $sourceUrl === '')) {
    fwrite(STDERR, "Použití: php api/bin/MyInvoiceMigrate.php <zdrojova_databaze|mysql://user:heslo@host:port/db> [přepínače]\n");
    fwrite(STDERR, "Např.:   php api/bin/MyInvoiceMigrate.php myinvoice --yes\n");
    fwrite(STDERR, "         php api/bin/MyInvoiceMigrate.php mysql://root:heslo\@myinvoice-db:3306/myinvoice --yes\n");
    fwrite(STDERR, "Přepínače: --yes --no-truncate --tables=a,b,c --allow-missing --no-prepare --no-finalize --keep-schema --batch=N\n");
    exit(1);
}

$rootDir       = Bootstrap::rootDir();
$migrationsDir = $rootDir . '/db/migrations';

try {
    $config = Config::load($rootDir);
    $pdo    = (new Connection($config))->pdo();
} catch (\Throwable $e) {
    fwrite(STDERR, "[migrate] Chyba připojení k cíli: " . $e->getMessage() . "\n");
    fwrite(STDERR, "[migrate] Zkontroluj cfg.php a běžící DB.\n");
    exit(1);
}

$targetDb   = (string) $config->get('db.name');
$targetHost = (string) $config->get('db.host');

/** Backtick-escape identifikátoru pro bezpečné vložení do SQL. */
$q = static fn (string $id): string => '`' . str_replace('`', '``', $id) . '`';

/** Otisk serveru — rozhodne, jestli jde použít rychlá cesta `INSERT ... SELECT`. */
$serverFingerprint = static function (\PDO $p): string {
    foreach (['SELECT @@global.server_uuid', "SELECT CONCAT(@@hostname, ':', @@port, ':', @@server_id)"] as $sql) {
        try {
            $v = $p->query($sql)?->fetchColumn();
            if (is_string($v) && $v !== '') {
                return $v;
            }
        } catch (\PDOException) {
            // zkus další variantu (server_uuid nemá MariaDB)
        }
    }
    return '';
};

// --- Připoj zdroj -------------------------------------------------------------
$srcPdo      = $pdo;
$sourceDb    = $sourceArg;
$sourceHost  = $targetHost;
$sameServer  = true;

if ($sourceUrl !== null && $sourceUrl !== '') {
    $parts = parse_url($sourceUrl);
    if ($parts === false || !isset($parts['host'])) {
        fwrite(STDERR, "[migrate] Neplatná zdrojová URL. Očekávám mysql://uzivatel:heslo@host:port/databaze\n");
        exit(1);
    }
    $srcName = isset($parts['path']) ? rawurldecode(ltrim($parts['path'], '/')) : '';
    if ($srcName === '') {
        fwrite(STDERR, "[migrate] Zdrojová URL neobsahuje název databáze (chybí /databaze na konci).\n");
        exit(1);
    }
    $srcHost = $parts['host'];
    $srcPort = (int) ($parts['port'] ?? 3306);
    $srcUser = isset($parts['user']) ? rawurldecode($parts['user']) : '';
    $srcPass = isset($parts['pass']) ? rawurldecode($parts['pass']) : '';

    try {
        $srcPdo = new \PDO(
            "mysql:host={$srcHost};port={$srcPort};dbname={$srcName};charset=utf8mb4",
            $srcUser,
            $srcPass,
            [
                \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_EMULATE_PREPARES   => false,
            ]
        );
    } catch (\Throwable $e) {
        fwrite(STDERR, "[migrate] Chyba připojení ke zdroji ({$srcHost}:{$srcPort}/{$srcName}): " . $e->getMessage() . "\n");
        exit(1);
    }

    $sourceDb   = $srcName;
    $sourceHost = $srcHost . ':' . $srcPort;

    // Tentýž server pod jinou adresou? Pak se vyplatí rychlá server-side cesta.
    $fpSrc = $serverFingerprint($srcPdo);
    $fpDst = $serverFingerprint($pdo);
    $sameServer = $fpSrc !== '' && $fpSrc === $fpDst;
    if ($sameServer) {
        // Ověř, že cílové spojení na zdrojové schéma opravdu vidí (práva).
        $vis = $pdo->prepare('SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?');
        $vis->execute([$sourceDb]);
        $sameServer = (int) $vis->fetchColumn() > 0;
    }
    $srcPdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
}

// --stream vynutí proudovou kopii i tam, kde by šla rychlá server-side cesta.
// Hodí se, když cílové spojení na zdrojové schéma nemá práva, a pro testy.
if ($forceStream) {
    $sameServer = false;
}

if ($sameServer && $sourceDb === $targetDb) {
    fwrite(STDERR, "[migrate] Zdrojová a cílová DB jsou tatáž ('{$sourceDb}' na témže serveru). Zkontroluj cfg.php.\n");
    exit(1);
}

// --- Ověř, že zdrojová DB existuje a je dostupná ---
$exists = $srcPdo->prepare('SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?');
$exists->execute([$sourceDb]);
if ((int) $exists->fetchColumn() === 0) {
    fwrite(STDERR, "[migrate] Zdrojová databáze '{$sourceDb}' neexistuje (nebo k ní není přístup).\n");
    exit(1);
}

// ------------------------------------------------------------------------------
// Pomocné dotazy nad schématy
// ------------------------------------------------------------------------------

/**
 * Vrátí tabulky daného schématu — VŠE kromě VIEW.
 * Záměrně NEfiltruje na TABLE_TYPE='BASE TABLE': systémově verzované tabulky
 * (SYSTEM VERSIONED) by tím vypadly a jejich data by se tiše nepřenesla.
 * @return string[]
 */
$tablesOf = static function (\PDO $pdo, string $schema): array {
    $st = $pdo->prepare(
        "SELECT TABLE_NAME FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = ? AND TABLE_TYPE <> 'VIEW'
          ORDER BY TABLE_NAME"
    );
    $st->execute([$schema]);
    return $st->fetchAll(\PDO::FETCH_COLUMN);
};

/**
 * Vrátí VŠECHNY sloupce tabulky (včetně generovaných).
 * Pro preflight je podstatné, že sloupec v cíli existuje — generovaný sloupec
 * se sice neinsertuje, ale hodnotu si DB dopočítá, takže o data nepřijdeme.
 * @return string[]
 */
$allColumns = static function (\PDO $pdo, string $schema, string $table): array {
    $st = $pdo->prepare(
        "SELECT COLUMN_NAME FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?
          ORDER BY ORDINAL_POSITION"
    );
    $st->execute([$schema, $table]);
    return $st->fetchAll(\PDO::FETCH_COLUMN);
};

/**
 * Insertovatelné sloupce cíle (bez generovaných) protnuté se sloupci zdroje.
 * @return string[]
 */
$insertableColumns = static function (\PDO $dstPdo, \PDO $srcPdo, string $dstSchema, string $srcSchema, string $table): array {
    $st = $dstPdo->prepare(
        "SELECT COLUMN_NAME FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?
            AND (GENERATION_EXPRESSION IS NULL OR GENERATION_EXPRESSION = '')
            AND EXTRA NOT LIKE '%GENERATED%'
          ORDER BY ORDINAL_POSITION"
    );
    $st->execute([$dstSchema, $table]);
    $dstCols = $st->fetchAll(\PDO::FETCH_COLUMN);

    $st2 = $srcPdo->prepare(
        "SELECT COLUMN_NAME FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?"
    );
    $st2->execute([$srcSchema, $table]);
    $srcCols = array_flip($st2->fetchAll(\PDO::FETCH_COLUMN));

    return array_values(array_filter($dstCols, fn ($c) => isset($srcCols[$c])));
};

/** Bezpečný COUNT — chybějící tabulka/sloupec nesmí shodit preflight. */
$countOrNull = static function (\PDO $pdo, string $sql): ?int {
    try {
        return (int) $pdo->query($sql)->fetchColumn();
    } catch (\PDOException) {
        return null;
    }
};

/**
 * Číselná předpona migrace: `1121_price_list_items.sql` → 1121, jinak null.
 * Připouští písmennou vsuvku (`0026a_clients_roles.sql` → 26).
 */
$migrationNumber = static function (string $filename): ?int {
    return preg_match('/^(\d+)[a-z]*_/i', $filename, $m) === 1 ? (int) $m[1] : null;
};

/** Migrace zapsané v cílové DB (prázdné pole, pokud tabulka `migrations` není). */
$recordedMigrations = static function (\PDO $pdo): array {
    try {
        return $pdo->query('SELECT filename FROM migrations')->fetchAll(\PDO::FETCH_COLUMN);
    } catch (\PDOException) {
        return [];
    }
};

/** Spustí `migrate.php` nad TOUTÉŽ cílovou konfigurací a vrátí exit code. */
$runMigrate = static function (array $args): int {
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/migrate.php');
    foreach ($args as $a) {
        $cmd .= ' ' . escapeshellarg($a);
    }
    echo "\n[migrate] → " . implode(' ', array_merge(['migrate.php'], $args)) . "\n";
    passthru($cmd, $exitCode);
    return (int) $exitCode;
};

// ------------------------------------------------------------------------------
// Statická analýza migrací — které DDL migrace 1000+ musí proběhnout PŘED importem
// ------------------------------------------------------------------------------

/** @var array<string,string> basename => SQL */
$migrationSql = [];
foreach (glob($migrationsDir . '/*.sql') ?: [] as $f) {
    $migrationSql[basename($f)] = (string) file_get_contents($f);
}
ksort($migrationSql, SORT_STRING);

/** Migrace bez DML — smí proběhnout mimo pořadí, nemá co doplnit nad daty. */
$isPureDdl = static function (string $sql): bool {
    return preg_match('/^\s*(INSERT|UPDATE|DELETE|REPLACE)\b/im', $sql) !== 1;
};

/** Najde první migraci, která vytváří danou tabulku. */
$creatorOf = static function (string $table) use ($migrationSql): ?string {
    $re = '/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?' . preg_quote($table, '/') . '`?\s*[(\s]/i';
    foreach ($migrationSql as $name => $sql) {
        if (preg_match($re, $sql) === 1) {
            return $name;
        }
    }
    return null;
};

/** Najde první migraci, která do dané tabulky přidává daný sloupec. */
$adderOf = static function (string $table, string $column) use ($migrationSql): ?string {
    $alterRe = '/ALTER\s+TABLE\s+`?' . preg_quote($table, '/') . '`?\b[^;]*/is';
    $addRe   = '/\bADD\s+(?:COLUMN\s+)?(?:IF\s+NOT\s+EXISTS\s+)?`?' . preg_quote($column, '/') . '`?\b/i';
    foreach ($migrationSql as $name => $sql) {
        if (preg_match_all($alterRe, $sql, $m) < 1) {
            continue;
        }
        foreach ($m[0] as $stmt) {
            if (preg_match($addRe, $stmt) === 1) {
                return $name;
            }
        }
    }
    return null;
};

// ------------------------------------------------------------------------------
// Preflight — má cíl kam uložit VŠECHNA data zdroje?
// ------------------------------------------------------------------------------

/**
 * Porovná zdroj s aktuálním stavem cíle a vrátí, co by se ztratilo.
 * Rozlišuje skutečnou ztrátu (jsou tam data) od mrtvého zbytku (0 řádků / samé
 * NULL) — staré nepoužívané sloupce v dávno běžící DB nesmí brzdit převod.
 *
 * @return array{loss_tables: array<string,int>, loss_columns: array<string,array<string,int>>,
 *               dead_tables: string[], dead_columns: array<string,string[]>}
 */
$preflight = static function () use (
    $pdo, $srcPdo, $tablesOf, $allColumns, $countOrNull, $q,
    $sourceDb, $targetDb, $onlyTables
): array {
    $srcTables = $tablesOf($srcPdo, $sourceDb);
    $dstSet    = array_flip($tablesOf($pdo, $targetDb));

    $lossTables = $lossColumns = $deadColumns = [];
    $deadTables = [];

    foreach ($srcTables as $table) {
        if ($table === 'migrations') {
            continue;
        }
        if ($onlyTables !== null && !in_array($table, $onlyTables, true)) {
            continue;
        }
        $srcRef = $q($sourceDb) . '.' . $q($table);

        if (!isset($dstSet[$table])) {
            $rows = $countOrNull($srcPdo, "SELECT COUNT(*) FROM {$srcRef}");
            if ($rows === null) {
                continue;
            }
            if ($rows > 0) {
                $lossTables[$table] = $rows;
            } else {
                $deadTables[] = $table;
            }
            continue;
        }

        $dstAll  = array_flip($allColumns($pdo, $targetDb, $table));
        $missing = array_values(array_filter(
            $allColumns($srcPdo, $sourceDb, $table),
            fn ($c) => !isset($dstAll[$c])
        ));
        foreach ($missing as $col) {
            // COUNT(col) počítá jen NOT NULL — sloupec plný NULL nenese data.
            $filled = $countOrNull($srcPdo, "SELECT COUNT(" . $q($col) . ") FROM {$srcRef}");
            if ($filled === null) {
                continue;
            }
            if ($filled > 0) {
                $lossColumns[$table][$col] = $filled;
            } else {
                $deadColumns[$table][] = $col;
            }
        }
    }

    return [
        'loss_tables'  => $lossTables,
        'loss_columns' => $lossColumns,
        'dead_tables'  => $deadTables,
        'dead_columns' => $deadColumns,
    ];
};

// ------------------------------------------------------------------------------
// Fáze 0 — stav cíle a plán
// ------------------------------------------------------------------------------

$dstTablesNow = $tablesOf($pdo, $targetDb);
$recorded     = $recordedMigrations($pdo);
$myuctoApplied = array_values(array_filter(
    $recorded,
    fn (string $f) => ($migrationNumber($f) ?? -1) >= MYUCTO_MIGRATION_FLOOR
));

// Plné MyÚčto schéma už proběhlo → datové kroky migrací 1000+ se nad importem
// samy neopakují. Cíl proto postavíme znovu od nuly (pokud to uživatel nezakáže).
$needsRebuild = $myuctoApplied !== [] && !$noPrepare && !$keepSchema;

if ($dstTablesNow === []) {
    $targetState = 'prázdná databáze';
} elseif ($myuctoApplied === []) {
    $targetState = 'upstream schéma MyInvoice (' . count($recorded) . ' migrací)';
} else {
    $targetState = 'plné schéma MyÚčta (' . count($recorded) . ' migrací, z toho '
        . count($myuctoApplied) . ' nad ' . MYUCTO_MIGRATION_FLOOR . ')';
}

// Kolik dat v cíli je (kvůli ochraně před omylem mířeným na ostrou DB).
$targetRows = 0;
foreach (['invoices', 'purchase_invoices', 'clients', 'supplier', 'users', 'journal_entries'] as $probe) {
    $targetRows += $countOrNull($pdo, "SELECT COUNT(*) FROM " . $q($targetDb) . '.' . $q($probe)) ?? 0;
}

echo "================================================\n";
echo "  MyInvoiceMigrate — PŘEVOD Z MYINVOICE DO MYÚČTA\n";
echo "================================================\n";
echo "  Zdroj:  {$sourceDb} @ {$sourceHost}" . ($sameServer ? '' : '  (vzdálený — proudová kopie)') . "\n";
echo "  Cíl:    {$targetDb} @ {$targetHost}\n";
echo "  Stav cíle: {$targetState}\n";
if ($targetRows > 0) {
    echo "  ⚠ Cíl NENÍ prázdný — v klíčových tabulkách je {$targetRows} řádků.\n";
}
echo "------------------------------------------------\n";
echo "  Plán:\n";
$step = 1;
if ($needsRebuild) {
    echo "   {$step}. ZBOURAT schéma cíle (DROP všech tabulek a pohledů) — migrace 1000+\n";
    echo "      už proběhly nad prázdnou DB a jejich datové kroky by se po importu\n";
    echo "      neopakovaly. Cíl se postaví znovu ve správném pořadí.\n";
    $step++;
}
if (!$noPrepare) {
    echo "   {$step}. Postavit upstream schéma MyInvoice (migrate.php --below=" . MYUCTO_MIGRATION_FLOOR . ")\n";
    echo "      + čistě DDL migrace 1000+ pro featury, které zdroj už má.\n";
    $step++;
}
echo "   {$step}. Přenést data" . ($noTruncate ? '' : ' (cílové tabulky se nejdřív vyprázdní)') . ".\n";
$step++;
if (!$noFinalize) {
    echo "   {$step}. Dokončit schéma MyÚčta zbylými migracemi 1000+ včetně backfillů.\n";
}
echo "================================================\n\n";

// Preflight ještě nad SOUČASNÝM schématem cíle — pokud MyÚčto danou featuru vůbec
// nemá, ať to víme dřív, než cokoli zbouráme.
if ($dstTablesNow !== []) {
    $pre = $preflight();
    if ($pre['loss_tables'] !== [] || $pre['loss_columns'] !== []) {
        $unresolvable = [];
        foreach (array_keys($pre['loss_tables']) as $t) {
            if ($creatorOf($t) === null) {
                $unresolvable[] = "tabulka {$t}";
            }
        }
        foreach ($pre['loss_columns'] as $t => $cols) {
            foreach (array_keys($cols) as $c) {
                if ($adderOf($t, $c) === null && $creatorOf($t) === null) {
                    $unresolvable[] = "{$t}.{$c}";
                }
            }
        }
        if ($unresolvable !== [] && !$allowMissing) {
            fwrite(STDERR, "✗ PŘEVOD ZASTAVEN: zdroj obsahuje data, pro která MyÚčto nemá\n");
            fwrite(STDERR, "  v žádné migraci protějšek (featura ve MyÚčtu neexistuje):\n");
            foreach ($unresolvable as $u) {
                fwrite(STDERR, "     - {$u}\n");
            }
            fwrite(STDERR, "\n[migrate] Nic nebylo změněno. Vědomé pokračování se ztrátou: --allow-missing\n");
            exit(3);
        }
    }
}

if (!$autoYes) {
    echo "POZOR: zapíše data ze '{$sourceDb}' do '{$targetDb}'.\n";
    if ($needsRebuild) {
        echo "       Cílová databáze bude NEJPRVE KOMPLETNĚ ZBOURÁNA (DROP TABLE).\n";
    } elseif (!$noTruncate) {
        echo "       Každá kopírovaná tabulka v cíli bude NEJPRVE VYPRÁZDNĚNA (TRUNCATE/DELETE).\n";
    }
    echo "Pokračovat? (napiš 'ANO'): ";
    $answer = trim((string) fgets(STDIN));
    if ($answer !== 'ANO') {
        echo "Zrušeno.\n";
        exit(0);
    }
}

// ------------------------------------------------------------------------------
// Fáze 1 — přestavba cíle (jen když už má migrace 1000+)
// ------------------------------------------------------------------------------

if ($needsRebuild) {
    echo "\n[migrate] Bourám schéma cíle '{$targetDb}'…\n";
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');

    $views = $pdo->prepare(
        "SELECT TABLE_NAME FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = 'VIEW'"
    );
    $views->execute([$targetDb]);
    foreach ($views->fetchAll(\PDO::FETCH_COLUMN) as $v) {
        $pdo->exec("DROP VIEW IF EXISTS " . $q($targetDb) . '.' . $q($v));
    }

    $dropped = 0;
    foreach ($tablesOf($pdo, $targetDb) as $t) {
        try {
            $pdo->exec("DROP TABLE IF EXISTS " . $q($targetDb) . '.' . $q($t));
            $dropped++;
        } catch (\PDOException $e) {
            fwrite(STDERR, "[migrate] DROP TABLE {$t} selhal: " . $e->getMessage() . "\n");
            exit(4);
        }
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    echo "[migrate] Zahozeno {$dropped} tabulek.\n";
}

// ------------------------------------------------------------------------------
// Fáze 2 — příprava schématu cíle
// ------------------------------------------------------------------------------

if (!$noPrepare) {
    $rc = $runMigrate(['--below=' . MYUCTO_MIGRATION_FLOOR, '--no-backfills']);
    if ($rc !== 0) {
        fwrite(STDERR, "\n[migrate] Příprava upstream schématu selhala (exit {$rc}). Končím.\n");
        exit(4);
    }

    // Co ze zdroje pořád nemá v cíli kam? Dohledej DDL migrace 1000+, které to řeší.
    $pre    = $preflight();
    $extras = [];
    $blocked = [];

    foreach (array_keys($pre['loss_tables']) as $t) {
        $name = $creatorOf($t);
        if ($name === null) {
            continue; // řešeno níž preflightem
        }
        if (!$isPureDdl($migrationSql[$name])) {
            $blocked[$name] = "tabulka {$t}";
            continue;
        }
        $extras[$name] = true;
    }
    foreach ($pre['loss_columns'] as $t => $cols) {
        foreach (array_keys($cols) as $c) {
            $name = $adderOf($t, $c);
            if ($name === null) {
                continue;
            }
            if (!$isPureDdl($migrationSql[$name])) {
                $blocked[$name] = "{$t}.{$c}";
                continue;
            }
            $extras[$name] = true;
        }
    }

    if ($blocked !== []) {
        fwrite(STDERR, "\n✗ PŘEVOD ZASTAVEN: data zdroje potřebují migraci 1000+, která ale obsahuje\n");
        fwrite(STDERR, "  i datové kroky — nelze ji bezpečně spustit před importem:\n");
        foreach ($blocked as $name => $what) {
            fwrite(STDERR, "     - {$name} (kvůli: {$what})\n");
        }
        fwrite(STDERR, "\n[migrate] Vyřeš ručně, nebo pokračuj se ztrátou: --allow-missing\n");
        if (!$allowMissing) {
            exit(3);
        }
    }

    if ($extras !== []) {
        $list = array_keys($extras);
        sort($list, SORT_STRING);
        echo "\n[migrate] Zdroj už má featury, které MyÚčto čísluje nad " . MYUCTO_MIGRATION_FLOOR . ".\n";
        echo "[migrate] Doplňuji jejich čistě DDL migrace před importem: " . implode(', ', $list) . "\n";
        $rc = $runMigrate(['--only=' . implode(',', $list), '--no-backfills']);
        if ($rc !== 0) {
            fwrite(STDERR, "\n[migrate] Doplnění DDL migrací selhalo (exit {$rc}). Končím.\n");
            exit(4);
        }
    }
}

// ------------------------------------------------------------------------------
// Fáze 3 — závazný preflight nad připraveným cílem
// ------------------------------------------------------------------------------

$pre = $preflight();

if ($pre['dead_tables'] !== [] || $pre['dead_columns'] !== []) {
    echo "\nⓘ Zdroj má prvky, které cíl nezná, ale jsou prázdné (žádná ztráta dat):\n";
    foreach ($pre['dead_tables'] as $t) {
        echo "     - tabulka {$t} (0 řádků)\n";
    }
    foreach ($pre['dead_columns'] as $t => $cols) {
        echo "     - {$t}: " . implode(', ', $cols) . " (samé NULL)\n";
    }
}

if ($pre['loss_tables'] !== [] || $pre['loss_columns'] !== []) {
    $header = $allowMissing ? '⚠ VAROVÁNÍ' : '✗ PŘEVOD ZASTAVEN';
    fwrite(STDERR, "\n{$header}: cíl '{$targetDb}' nemá kam uložit tato data zdroje:\n");
    foreach ($pre['loss_tables'] as $t => $rows) {
        fwrite(STDERR, "     - tabulka {$t} — {$rows} řádků by se ztratilo\n");
    }
    foreach ($pre['loss_columns'] as $t => $cols) {
        foreach ($cols as $col => $filled) {
            fwrite(STDERR, "     - {$t}.{$col} — {$filled} vyplněných hodnot by se ztratilo\n");
        }
    }
    if (!$allowMissing) {
        fwrite(STDERR, "\n[migrate] Data zatím nebyla zapsána. Vědomé pokračování se ztrátou: --allow-missing\n");
        exit(3);
    }
    fwrite(STDERR, "\n[migrate] --allow-missing — pokračuji, uvedená data se NEPŘENESOU.\n");
}

// ------------------------------------------------------------------------------
// Fáze 4 — kopie dat
// ------------------------------------------------------------------------------

$srcTables = $tablesOf($srcPdo, $sourceDb);
$dstSet    = array_flip($tablesOf($pdo, $targetDb));

// Tabulky, které se nikdy nekopírují (cíl si drží vlastní).
$excluded = ['migrations'];

$candidates = [];
foreach ($srcTables as $t) {
    if (in_array($t, $excluded, true) || !isset($dstSet[$t])) {
        continue;
    }
    if ($onlyTables !== null && !in_array($t, $onlyTables, true)) {
        continue;
    }
    $candidates[] = $t;
}

if ($onlyTables !== null) {
    $missing = array_values(array_filter($onlyTables, fn ($t) => !in_array($t, $candidates, true)));
    if ($missing) {
        fwrite(STDERR, "[migrate] Varování: tyto --tables nejsou v obou DB a budou přeskočeny: "
            . implode(', ', $missing) . "\n");
    }
}

echo "\n[migrate] Kopíruji data — " . count($candidates) . " tabulek"
    . ($sameServer ? " (server-side INSERT…SELECT)" : " (proudově po {$batchSize} řádcích)") . "…\n";

$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
$pdo->exec('SET UNIQUE_CHECKS = 0');

// Zdroj je autorita — přenášíme ho tak, jak je, ne jak by dnes vypadal po validaci.
// Bez tohohle by proudová kopie spadla na legacy hodnotách, které server-side
// `INSERT ... SELECT` v pohodě přenese (typicky prázdný řetězec v ENUM sloupci,
// zapsaný ještě před zpřísněním schématu). NO_AUTO_VALUE_ON_ZERO navíc zachová
// explicitní PK = 0, které by jinak auto_increment přepsal.
$originalSqlMode = (string) $pdo->query('SELECT @@session.sql_mode')->fetchColumn();
$pdo->exec("SET SESSION sql_mode = 'NO_AUTO_VALUE_ON_ZERO'");

$copied    = 0;
$totalRows = 0;
$failures  = [];
$skipped   = [];

foreach ($candidates as $table) {
    $cols = $insertableColumns($pdo, $srcPdo, $targetDb, $sourceDb, $table);
    if (!$cols) {
        $skipped[$table] = 'žádný společný insertovatelný sloupec';
        printf("  - %-34s (přeskočeno: žádný společný sloupec)\n", $table);
        continue;
    }

    $srcRef  = $q($sourceDb) . '.' . $q($table);
    $dstRef  = $q($targetDb) . '.' . $q($table);
    $colList = implode(', ', array_map($q, $cols));

    // 1) Vyprázdnit cíl (pokud není --no-truncate). TRUNCATE může u tabulek
    //    odkazovaných cizím klíčem selhat i s FK_CHECKS=0 → fallback DELETE.
    if (!$noTruncate) {
        try {
            $pdo->exec("TRUNCATE TABLE {$dstRef}");
        } catch (\PDOException) {
            try {
                $pdo->exec("DELETE FROM {$dstRef}");
            } catch (\PDOException $e) {
                $failures[$table] = 'truncate/delete selhal: ' . $e->getMessage();
                printf("  ✗ %-34s (truncate selhal: %s)\n", $table, $e->getMessage());
                continue;
            }
        }
    }

    try {
        if ($sameServer) {
            // 2a) Rychlá cesta — vše zůstane v DB, nic neteče přes PHP.
            $affected = (int) $pdo->exec("INSERT INTO {$dstRef} ({$colList}) SELECT {$colList} FROM {$srcRef}");
        } else {
            // 2b) Vzdálený zdroj — nebufferovaný SELECT + dávkové INSERTy.
            //     Limit placeholderů na statement je 65535, dávku podle šířky tabulky.
            $perBatch = max(1, min($batchSize, intdiv(65000, count($cols))));
            // Nebufferovaně MUSÍ být přepnuto před prepare/execute, jinak si mysqlnd
            // celou tabulku natáhne do paměti. Po dočtení se hned vrací zpět —
            // s otevřeným nebufferovaným kurzorem nelze na spojení nic jiného.
            $srcPdo->setAttribute($bufferedQueryAttr, false);
            $read = $srcPdo->prepare("SELECT {$colList} FROM {$srcRef}");
            $read->execute();

            $rowTpl   = '(' . implode(', ', array_fill(0, count($cols), '?')) . ')';
            $affected = 0;
            $buffer   = [];
            $flush    = static function (array &$buffer) use ($pdo, $dstRef, $colList, $rowTpl, &$affected): void {
                if (!$buffer) {
                    return;
                }
                $sql = "INSERT INTO {$dstRef} ({$colList}) VALUES "
                    . implode(', ', array_fill(0, count($buffer), $rowTpl));
                $ins = $pdo->prepare($sql);
                $ins->execute(array_merge(...$buffer));
                $affected += count($buffer);
                $buffer = [];
            };

            while (($row = $read->fetch(\PDO::FETCH_NUM)) !== false) {
                $buffer[] = $row;
                if (count($buffer) >= $perBatch) {
                    $flush($buffer);
                }
            }
            $flush($buffer);
            $read->closeCursor();
            $srcPdo->setAttribute($bufferedQueryAttr, true);
        }

        $copied++;
        $totalRows += $affected;
        printf("  ✓ %-34s %d řádků\n", $table, $affected);
    } catch (\PDOException $e) {
        $failures[$table] = $e->getMessage();
        printf("  ✗ %-34s CHYBA: %s\n", $table, $e->getMessage());
        if (!$sameServer) {
            $srcPdo->setAttribute($bufferedQueryAttr, true);
        }
    }
}

$pdo->exec('SET UNIQUE_CHECKS = 1');
$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
$restore = $pdo->prepare('SET SESSION sql_mode = ?');
$restore->execute([$originalSqlMode]);

echo "\n================================================\n";
echo "  DATA PŘENESENA.\n";
echo "  Zkopírováno tabulek: {$copied} / " . count($candidates) . "\n";
echo "  Celkem řádků:        {$totalRows}\n";
if ($skipped) {
    echo "  Přeskočeno (bez společných sloupců): " . count($skipped) . " — "
        . implode(', ', array_keys($skipped)) . "\n";
}
if ($failures) {
    echo "  ⚠ CHYBY (" . count($failures) . "):\n";
    foreach ($failures as $t => $msg) {
        echo "     - {$t}: {$msg}\n";
    }
}
echo "================================================\n";

if ($failures) {
    fwrite(STDERR, "\n[migrate] Import skončil s chybami — dokončovací migrace NESPOUŠTÍM.\n");
    fwrite(STDERR, "[migrate] Zkontroluj tabulky výše, cílovou DB znovu vytvoř prázdnou a zopakuj.\n");
    exit(2);
}

// ------------------------------------------------------------------------------
// Fáze 5 — dokončení schématu MyÚčta nad přenesenými daty
// ------------------------------------------------------------------------------

if ($noFinalize) {
    echo "\n[migrate] --no-finalize — dokončovací migrace nespouštím. Doplň je ručně:\n";
    echo "  php api/bin/migrate.php\n";
    exit(0);
}

echo "\n[migrate] Dokončuji schéma MyÚčta nad přenesenými daty…\n";
$rc = $runMigrate([]);
if ($rc !== 0) {
    fwrite(STDERR, "\n[migrate] Dokončovací migrace selhaly (exit {$rc}).\n");
    fwrite(STDERR, "[migrate] Data JSOU přenesená; oprav příčinu a spusť `php api/bin/migrate.php` znovu.\n");
    exit(5);
}

// ------------------------------------------------------------------------------
// Fáze 6 — účetní nadstavba zůstává po převodu vypnutá
// ------------------------------------------------------------------------------
//
// MyInvoice je fakturační aplikace: firma, která se z něj převádí, žádné
// účetnictví ani daňovou evidenci v MyÚčtu nezapínala. Kdyby se nadstavba
// zapnula sama, uživatel by hned po převodu dostal do menu celé Účetnictví
// (resp. Daňovou evidenci) postavené nad prázdnými tabulkami — spoustu stránek,
// které nemají co ukázat. `accounting_enabled` (migrace 1179) je proto po
// převodu vypnuté; zapíná se jedním přepínačem v Nastavení → Daně a účetnictví.
//
// Firmy, které už podvojné účetnictví vedou (opakovaný import z jiného MyÚčta),
// se nechávají být — u nich nadstavba není domněnka, ale stav.

$accountingDisabled = 0;
try {
    $hasFlag = (int) $pdo->query(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'supplier'
            AND COLUMN_NAME = 'accounting_enabled'"
    )->fetchColumn();
    if ($hasFlag > 0) {
        $accountingDisabled = (int) $pdo->exec(
            "UPDATE supplier SET accounting_enabled = 0
              WHERE accounting_enabled = 1 AND accounting_mode = 'tax_evidence'"
        );
    }
} catch (\PDOException $e) {
    fwrite(STDERR, "[migrate] Účetní nadstavbu se nepodařilo vypnout: " . $e->getMessage() . "\n");
}

echo "\n================================================\n";
echo "  HOTOVO — data přenesena a schéma MyÚčta dokončeno.\n";
echo "================================================\n";
if ($accountingDisabled > 0) {
    echo "\n[migrate] Účetní nadstavba je po převodu VYPNUTÁ u {$accountingDisabled} firem — aplikace se\n";
    echo "          chová jako MyInvoice, na který jsi zvyklý. Účetnictví (nebo daňovou\n";
    echo "          evidenci) zapneš v Nastavení → Daně a účetnictví přepínačem „Vést účetnictví\".\n";
}
echo "\n[migrate] Zkontroluj:\n";
echo "  • přihlášení původním administrátorským účtem,\n";
echo "  • seznam firem a přístup uživatelů k nim (user_suppliers),\n";
echo "  • počty klientů, vydaných a přijatých faktur, bankovní výpisy a párování,\n";
echo "  • `php api/bin/migrate.php --status` — všechny migrace musí být [x],\n";
echo "  • soubory ze `storage/` (PDF, přílohy, loga) přenes samostatně — DB je nenese.\n";
echo "\n[migrate] Doúčtování historie po zapnutí podvojného účetnictví popisuje\n";
echo "          manual/06_Prevod_z_MyInvoice.md (backfill-accounting / -bank-posting / -cash).\n";
