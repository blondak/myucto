<?php

declare(strict_types=1);

namespace MyInvoice\Service\System;

use MyInvoice\Infrastructure\Database\SqlStatementSplitter;
use PDO;

/**
 * Dotah globálních seedů z migrací, které instalace ztratila (typicky `reset.php`
 * se zastaralým keep-listem). `migrate.php` je sám nevrátí, protože migrace jsou
 * evidované jako proběhlé.
 *
 * Data se NEOPISUJÍ: přehrávají se přímo INSERT/UPDATE statementy migrací, které
 * na tabulku míří (seznam v {@see GlobalSeedTables::RESTORABLE}). INSERTy jsou
 * idempotentní (`WHERE NOT EXISTS`, `INSERT IGNORE`, `ON DUPLICATE KEY`), takže na
 * zdravé instalaci je dotah no-op, nic nemaže a per-tenant řádky nechává být.
 */
final class GlobalSeedRestorer
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly string $migrationsDir,
    ) {}

    /**
     * Co statement dělá a s jakou tabulkou. Null = nemění data žádné tabulky
     * (DDL, SET, CREATE TRIGGER s INSERTem v těle …).
     *
     * @return array{kind: 'insert'|'replace'|'update'|'delete', table: string}|null
     */
    public static function statementTarget(string $statement): ?array
    {
        $sql = SqlStatementSplitter::stripLeadingComments($statement);
        $patterns = [
            'insert'  => '/^INSERT\s+(?:(?:LOW_PRIORITY|DELAYED|HIGH_PRIORITY|IGNORE)\s+)*(?:INTO\s+)?`?(\w+)`?/i',
            'replace' => '/^REPLACE\s+(?:(?:LOW_PRIORITY|DELAYED)\s+)*(?:INTO\s+)?`?(\w+)`?/i',
            'update'  => '/^UPDATE\s+(?:(?:LOW_PRIORITY|IGNORE)\s+)*`?(\w+)`?/i',
            'delete'  => '/^(?:DELETE\s+(?:(?:LOW_PRIORITY|QUICK|IGNORE)\s+)*FROM|TRUNCATE(?:\s+TABLE)?)\s+`?(\w+)`?/i',
        ];
        foreach ($patterns as $kind => $pattern) {
            if (preg_match($pattern, $sql, $m) === 1) {
                return ['kind' => $kind, 'table' => strtolower($m[1])];
            }
        }
        return null;
    }

    /** Je INSERT napsaný tak, že opakované spuštění nic nezdvojí? */
    public static function isIdempotentInsert(string $statement): bool
    {
        $sql = SqlStatementSplitter::stripLeadingComments($statement);
        return preg_match('/^INSERT\s+(?:(?:LOW_PRIORITY|DELAYED|HIGH_PRIORITY)\s+)*IGNORE\b/i', $sql) === 1
            || preg_match('/\bWHERE\s+NOT\s+EXISTS\b/i', $sql) === 1
            || preg_match('/\bON\s+DUPLICATE\s+KEY\s+UPDATE\b/i', $sql) === 1;
    }

    /**
     * Všechny statementy migrací, které mění data tabulky, v pořadí migrací.
     *
     * @param list<string>|null $onlyMigrations null = všechny migrace v adresáři
     * @return list<array{migration: string, kind: string, sql: string}>
     */
    public static function tableStatements(string $migrationsDir, string $table, ?array $onlyMigrations = null): array
    {
        $files = glob(rtrim($migrationsDir, '/\\') . '/*.sql') ?: [];
        sort($files);
        $out = [];
        foreach ($files as $file) {
            $name = basename($file);
            if ($onlyMigrations !== null && !in_array($name, $onlyMigrations, true)) {
                continue;
            }
            foreach (SqlStatementSplitter::split((string) file_get_contents($file)) as $statement) {
                $target = self::statementTarget($statement);
                if ($target !== null && $target['table'] === $table) {
                    $out[] = ['migration' => $name, 'kind' => $target['kind'], 'sql' => $statement];
                }
            }
        }
        return $out;
    }

    /**
     * Globální číselníky bez jediného globálního řádku. Jen čte — volá ho Diagnostika.
     * Tabulka, která ve schématu chybí, sem nepatří (to hlásí kontrola struktury).
     *
     * @return list<string>
     */
    public function emptyCodebooks(): array
    {
        $existing = array_fill_keys(
            $this->pdo->query('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()')
                ->fetchAll(PDO::FETCH_COLUMN),
            true,
        );
        $empty = [];
        foreach (GlobalSeedTables::CODEBOOKS as $table => $global) {
            if (!isset($existing[$table])) {
                continue;
            }
            if ($this->globalRows($table, $global) === 0) {
                $empty[] = $table;
            }
        }
        return $empty;
    }

    /**
     * Kolik řádků by dotah doplnil. Statementy proběhnou v transakci, která se
     * vždy vrátí — nic se nezapíše.
     *
     * @return array<string, int> tabulka => chybějících řádků (jen nenulové)
     */
    public function pending(): array
    {
        return $this->run(false);
    }

    /**
     * Doplní chybějící globální řádky.
     *
     * @return array<string, int> tabulka => doplněných řádků (jen nenulové)
     */
    public function apply(): array
    {
        return $this->run(true);
    }

    /** @return array<string, int> */
    private function run(bool $commit): array
    {
        $result = [];
        $applied = $this->appliedMigrations();
        foreach (GlobalSeedTables::RESTORABLE as $table => $spec) {
            if (!$this->tableExists($table)) {
                continue;
            }
            // Jen seedy migrací, které na instalaci proběhly. Nespuštěnou migraci
            // doběhne migrate.php celou; předběhnout ji tady by vložilo řádky do
            // schématu, které ještě nemá její sloupce.
            $migrations = array_values(array_filter(
                $spec['migrations'],
                static fn (string $m): bool => isset($applied[$m]),
            ));
            $statements = self::tableStatements($this->migrationsDir, $table, $migrations);
            if ($statements === []) {
                continue;
            }
            $this->pdo->beginTransaction();
            try {
                $before = $this->globalRows($table, $spec['global']);
                foreach ($statements as $statement) {
                    if ($statement['kind'] === 'insert' || $statement['kind'] === 'update') {
                        $this->pdo->exec($statement['sql']);
                    }
                }
                $added = $this->globalRows($table, $spec['global']) - $before;
                if ($commit) {
                    $this->pdo->commit();
                } else {
                    $this->pdo->rollBack();
                }
            } catch (\Throwable $e) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                throw $e;
            }
            if ($added > 0) {
                $result[$table] = $added;
            }
        }
        return $result;
    }

    /** @return array<string, true> */
    private function appliedMigrations(): array
    {
        if (!$this->tableExists('migrations')) {
            return [];
        }
        return array_fill_keys(
            $this->pdo->query('SELECT filename FROM migrations')->fetchAll(PDO::FETCH_COLUMN),
            true,
        );
    }

    private function globalRows(string $table, ?string $global): int
    {
        $where = $global !== null ? ' WHERE ' . $global : '';
        return (int) $this->pdo->query("SELECT COUNT(*) FROM `{$table}`{$where}")->fetchColumn();
    }

    private function tableExists(string $table): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
        );
        $stmt->execute([$table]);
        return $stmt->fetchColumn() !== false;
    }
}
