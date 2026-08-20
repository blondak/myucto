<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

require dirname(__DIR__) . '/vendor/autoload.php';

/**
 * Spustí testy paralelně, ale nikdy nad jednou databází.
 *
 * Pro každý ParaTest worker vytvoří klon již připravené `*_test` databáze.
 * Nepouští migrace ani seed čtyřikrát: kopíruje hotové DDL, data, triggery,
 * views a uložené rutiny. HTTP testy zůstávají sériové nad zdrojovou test DB,
 * protože jejich server musí číst tutéž databázi jako PHPUnit proces.
 *
 * Použití:
 *   php api/bin/test-parallel.php
 *   php api/bin/test-parallel.php --processes=4 --application
 *   php api/bin/test-parallel.php --source=myucto_test --keep-databases
 */

/** @return never */
function fail(string $message): never
{
    fwrite(STDERR, "[PARALLEL TEST] {$message}\n");
    exit(2);
}

function quoteIdentifier(string $identifier): string
{
    return '`' . str_replace('`', '``', $identifier) . '`';
}

function assertTestDatabaseName(string $database, string $label): void
{
    if (preg_match('/^[A-Za-z0-9_]+$/D', $database) !== 1 || !str_ends_with($database, '_test')) {
        fail("{$label} musí být bezpečný název databáze končící _test.");
    }
}

/** @return array<string,mixed> */
function sourceConfig(string $rootDir): array
{
    $path = $rootDir . '/cfg.php';
    if (!is_file($path)) {
        fail('cfg.php chybí; pro izolované integrační testy není znám zdroj DB.');
    }
    $config = require $path;
    if (!is_array($config) || !isset($config['db']) || !is_array($config['db'])) {
        fail('cfg.php neobsahuje konfiguraci db.');
    }

    return $config;
}

/** @return list<string> */
function databaseObjects(PDO $pdo, string $schema, string $type): array
{
    $sql = $type === 'VIEW'
        ? 'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = \'VIEW\' ORDER BY TABLE_NAME'
        : 'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_TYPE <> \'VIEW\' ORDER BY TABLE_NAME';
    $statement = $pdo->prepare($sql);
    $statement->execute([$schema]);

    return array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * CREATE TABLE vyžaduje, aby cílová tabulka referenced FK už existovala i při
 * FOREIGN_KEY_CHECKS=0. Tabulky proto řadíme podle skutečných závislostí, ne
 * podle abecedy (např. journal_entry_lines před journal_entries by jinak selhal).
 *
 * @param list<string> $tables
 * @return list<string>
 */
function tablesInForeignKeyOrder(PDO $pdo, string $schema, array $tables): array
{
    $known = array_fill_keys($tables, true);
    $dependencies = array_fill_keys($tables, []);
    $statement = $pdo->prepare(
        'SELECT TABLE_NAME, REFERENCED_TABLE_NAME
           FROM information_schema.KEY_COLUMN_USAGE
          WHERE TABLE_SCHEMA = ? AND REFERENCED_TABLE_NAME IS NOT NULL',
    );
    $statement->execute([$schema]);
    foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $table = (string) $row['TABLE_NAME'];
        $parent = (string) $row['REFERENCED_TABLE_NAME'];
        if (isset($known[$table]) && isset($known[$parent]) && $table !== $parent) {
            $dependencies[$table][$parent] = true;
        }
    }

    $pending = array_fill_keys($tables, true);
    $ordered = [];
    while ($pending !== []) {
        $ready = [];
        foreach (array_keys($pending) as $table) {
            if (array_diff_key($dependencies[$table], $pending) === []) {
                $ready[] = $table;
            }
        }
        if ($ready === []) {
            throw new RuntimeException('Cyklus cizích klíčů brání klonování tabulek: ' . implode(', ', array_keys($pending)));
        }
        sort($ready);
        foreach ($ready as $table) {
            unset($pending[$table]);
            $ordered[] = $table;
        }
    }

    return $ordered;
}

function showCreate(PDO $pdo, string $kind, string $schema, string $name): string
{
    $statement = $pdo->query('SHOW CREATE ' . $kind . ' ' . quoteIdentifier($schema) . '.' . quoteIdentifier($name));
    $row = $statement?->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        throw new RuntimeException("Nelze načíst DDL {$kind} {$name}.");
    }
    foreach ($row as $key => $value) {
        if (str_starts_with(strtolower((string) $key), 'create ') || $key === 'SQL Original Statement') {
            return (string) $value;
        }
    }

    throw new RuntimeException("SHOW CREATE {$kind} {$name} nevrátilo DDL.");
}

function cloneDatabase(PDO $pdo, string $source, string $target): void
{
    assertTestDatabaseName($source, 'Zdroj');
    assertTestDatabaseName($target, 'Cíl');

    $exists = $pdo->prepare('SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?');
    $exists->execute([$source]);
    if ((int) $exists->fetchColumn() !== 1) {
        fail("Zdrojová testovací databáze {$source} neexistuje.");
    }

    $pdo->exec('DROP DATABASE IF EXISTS ' . quoteIdentifier($target));
    $pdo->exec('CREATE DATABASE ' . quoteIdentifier($target) . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

    try {
        $tables = tablesInForeignKeyOrder($pdo, $source, databaseObjects($pdo, $source, 'BASE TABLE'));
        $pdo->exec('SET SESSION FOREIGN_KEY_CHECKS = 0');
        $pdo->exec('USE ' . quoteIdentifier($target));

        foreach ($tables as $table) {
            try {
                $pdo->exec(showCreate($pdo, 'TABLE', $source, $table));
            } catch (Throwable $e) {
                throw new RuntimeException("DDL tabulky {$table} nelze zkopírovat.", previous: $e);
            }
        }
        $missingTables = array_values(array_diff($tables, databaseObjects($pdo, $target, 'BASE TABLE')));
        if ($missingTables !== []) {
            throw new RuntimeException('Po kopii DDL chybí tabulky: ' . implode(', ', $missingTables));
        }
        foreach ($tables as $table) {
            try {
                $pdo->exec(
                    'INSERT INTO ' . quoteIdentifier($target) . '.' . quoteIdentifier($table)
                    . ' SELECT * FROM ' . quoteIdentifier($source) . '.' . quoteIdentifier($table),
                );
            } catch (Throwable $e) {
                throw new RuntimeException("Data tabulky {$table} nelze zkopírovat.", previous: $e);
            }
        }

        foreach (databaseObjects($pdo, $source, 'VIEW') as $view) {
            $pdo->exec(showCreate($pdo, 'VIEW', $source, $view));
        }

        $triggerStatement = $pdo->prepare(
            'SELECT TRIGGER_NAME, EVENT_OBJECT_TABLE FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = ? ORDER BY TRIGGER_NAME',
        );
        $triggerStatement->execute([$source]);
        foreach ($triggerStatement->fetchAll(PDO::FETCH_ASSOC) as $trigger) {
            $triggerName = (string) $trigger['TRIGGER_NAME'];
            $triggerTable = (string) $trigger['EVENT_OBJECT_TABLE'];
            if (!in_array($triggerTable, databaseObjects($pdo, $target, 'BASE TABLE'), true)) {
                throw new RuntimeException("Trigger {$triggerName} míří na chybějící tabulku {$triggerTable}.");
            }
            try {
                $pdo->exec(showCreate($pdo, 'TRIGGER', $source, $triggerName));
            } catch (Throwable $e) {
                throw new RuntimeException("Trigger {$triggerName} nelze zkopírovat.", previous: $e);
            }
        }

        $routineStatement = $pdo->prepare(
            'SELECT ROUTINE_NAME, ROUTINE_TYPE FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = ? ORDER BY ROUTINE_TYPE, ROUTINE_NAME',
        );
        $routineStatement->execute([$source]);
        foreach ($routineStatement->fetchAll(PDO::FETCH_ASSOC) as $routine) {
            $pdo->exec(showCreate($pdo, (string) $routine['ROUTINE_TYPE'], $source, (string) $routine['ROUTINE_NAME']));
        }
    } catch (Throwable $e) {
        $pdo->exec('DROP DATABASE IF EXISTS ' . quoteIdentifier($target));
        throw $e;
    } finally {
        $pdo->exec('SET SESSION FOREIGN_KEY_CHECKS = 1');
    }
}

function runProcess(array $command, string $cwd, array $environment): int
{
    $process = new Process($command, $cwd, $environment);
    $process->setTimeout(null);
    $process->run(static function (string $type, string $output): void {
        $stream = $type === Process::ERR ? STDERR : STDOUT;
        fwrite($stream, $output);
    });

    return $process->getExitCode() ?? 1;
}

$options = getopt('', ['processes::', 'source::', 'keep-databases', 'testsuite::', 'application']);
if (in_array('--help', $_SERVER['argv'], true) || in_array('-h', $_SERVER['argv'], true)) {
    fwrite(STDOUT, "Použití: php api/bin/test-parallel.php [--processes=N] [--source=DB] [--keep-databases] [--testsuite=Application|Architecture|Invariants|--application]\n");
    exit(0);
}

$rootDir = dirname(__DIR__, 2);
$apiDir = dirname(__DIR__);
$config = sourceConfig($rootDir);
$dbConfig = $config['db'];
$configuredDb = (string) ($dbConfig['name'] ?? '');
$source = (string) ($options['source'] ?? getenv('MYINVOICE_DB_NAME') ?: ($configuredDb . '_test'));
assertTestDatabaseName($source, 'Zdroj');
if ($source === $configuredDb) {
    fail('Zdroj nesmí být databáze z cfg.php.');
}

$processes = (int) ($options['processes'] ?? min(4, max(2, (int) (getenv('NUMBER_OF_PROCESSORS') ?: 4))));
if ($processes < 2 || $processes > 16) {
    fail('--processes musí být mezi 2 a 16.');
}

$prefix = preg_replace('/_test$/', '_parallel', $source);
if (!is_string($prefix) || $prefix === '') {
    fail('Nelze odvodit prefix paralelních databází.');
}
$databases = [];
for ($worker = 1; $worker <= $processes; $worker++) {
    $database = $prefix . '_' . $worker . '_test';
    assertTestDatabaseName($database, 'Cíl');
    if (strlen($database) > 64) {
        fail('Odvozený název paralelní DB je delší než 64 znaků. Použij kratší --source.');
    }
    $databases[] = $database;
}

$dsn = sprintf(
    'mysql:host=%s;port=%d;charset=utf8mb4',
    (string) ($dbConfig['host'] ?? '127.0.0.1'),
    (int) ($dbConfig['port'] ?? 3306),
);
$pdo = new PDO(
    $dsn,
    (string) ($dbConfig['user'] ?? ''),
    (string) ($dbConfig['pass'] ?? ''),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
);

$keepDatabases = array_key_exists('keep-databases', $options);
$environment = getenv();
if (!is_array($environment)) {
    $environment = [];
}
$parallelEnvironment = array_replace($environment, [
    'MYINVOICE_PARALLEL_DB_PREFIX' => $prefix,
    'MYINVOICE_DB_NAME' => $source,
]);
$parallelConfig = $apiDir . '/phpunit-parallel.xml';

$exitCode = 1;
try {
    // Bootstrap jednou aplikuje případné nové migrace a srovná seed zdrojové
    // DB do očekávaného testovacího stavu. Klony tak začínají stejně jako běžný
    // sériový běh, jen bez čtyřnásobného migrátoru a seedování.
    fwrite(STDOUT, "[PARALLEL TEST] Připravuji zdrojovou testovací DB {$source}.\n");
    $sourceEnvironment = array_replace($environment, ['MYINVOICE_DB_NAME' => $source]);
    unset($sourceEnvironment['MYINVOICE_PARALLEL_DB_PREFIX']);
    if (runProcess(
        [
            PHP_BINARY,
            $apiDir . '/vendor/phpunit/phpunit/phpunit',
            '--configuration=' . $parallelConfig,
            '--list-suites',
        ],
        $apiDir,
        $sourceEnvironment,
    ) !== 0) {
        throw new RuntimeException('Příprava zdrojové testovací DB selhala.');
    }

    fwrite(STDOUT, "[PARALLEL TEST] Klonuji {$source} pro {$processes} workery (bez migrací a seedování).\n");
    foreach ($databases as $database) {
        cloneDatabase($pdo, $source, $database);
    }

    $paraTest = $apiDir . '/vendor/brianium/paratest/bin/paratest';
    $command = [
        PHP_BINARY,
        $paraTest,
        '--configuration=' . $parallelConfig,
        '--processes=' . $processes,
        '--exclude-group=http-integration',
        '--colors=auto',
    ];
    $suite = $options['testsuite'] ?? null;
    $application = array_key_exists('application', $options);
    if ($application && is_string($suite) && $suite !== '') {
        fail('--application a --testsuite nelze kombinovat.');
    }
    if ($application) {
        $command[] = '--testsuite=Application';
    } elseif (is_string($suite) && $suite !== '') {
        if (!in_array($suite, ['Application', 'Architecture', 'Invariants'], true)) {
            fail('--testsuite podporuje jen Application, Architecture nebo Invariants.');
        }
        $command[] = '--testsuite=' . $suite;
    }
    $exitCode = runProcess($command, $apiDir, $parallelEnvironment);

    // Black-box HTTP testy nelze sdílet mezi workery: běžící server má jednu DB.
    // Spouštějí se proto až po ParaTestu nad původní izolovanou testovací DB.
    if ($exitCode === 0 && ($application || $suite === null || $suite === '' || $suite === 'Integration')) {
        $httpEnvironment = array_replace($environment, ['MYINVOICE_DB_NAME' => $source]);
        unset($httpEnvironment['MYINVOICE_PARALLEL_DB_PREFIX']);
        $exitCode = runProcess(
            [
                PHP_BINARY,
                $apiDir . '/vendor/phpunit/phpunit/phpunit',
                '--configuration=' . $parallelConfig,
                '--group=http-integration',
                '--colors=auto',
            ],
            $apiDir,
            $httpEnvironment,
        );
    }
} catch (Throwable $e) {
    $details = $e->getMessage();
    for ($previous = $e->getPrevious(); $previous !== null; $previous = $previous->getPrevious()) {
        $details .= ' — ' . $previous->getMessage();
    }
    fwrite(STDERR, '[PARALLEL TEST] ' . $details . "\n");
    $exitCode = 1;
} finally {
    if ($keepDatabases) {
        fwrite(STDOUT, "[PARALLEL TEST] Klony ponechány pro diagnostiku.\n");
    } else {
        foreach ($databases as $database) {
            $pdo->exec('DROP DATABASE IF EXISTS ' . quoteIdentifier($database));
        }
    }
}

exit($exitCode);
