<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Společný základ evidence převodů z cizích účetních programů: běhy průvodce s protokolem
 * (`<zdroj>_imports`), mapa „co už ze zdroje v MyÚčtu vzniklo" (`<zdroj>_import_map`)
 * a zámek převodu firmy.
 *
 * Mapa je nosič idempotence — opakovaný import nezaloží nic, co už v mapě je. Všechno je
 * tenantové: stejná agenda nahraná do jiné firmy má vlastní mapu. Převod jedné firmy smí
 * běžet jen jednou naráz ({@see acquireLock()}): dva běhy nad toutéž mapou by založily
 * tytéž doklady dvakrát.
 *
 * Zdroj dodá jména tabulek, sloupec klíče mapy, předponu zámku, vlastní sloupce běhu
 * a výjimku konfliktu mapy.
 */
abstract class AbstractMigrationImportRepository
{
    public function __construct(protected readonly Connection $db) {}

    /** Tabulka běhů (`money_s3_imports`, …). */
    abstract protected function runsTable(): string;

    /** Tabulka mapy (`money_s3_import_map`, …). */
    abstract protected function mapTable(): string;

    /** Sloupec klíče zdroje v mapě (`money_key`, …). */
    abstract protected function keyColumn(): string;

    /** Předpona jména zámku firmy (`money_s3`, …). */
    abstract protected function lockPrefix(): string;

    /**
     * Vlastní sloupce běhu zdroje v pořadí, v jakém se čtou i zapisují, a jejich hodnoty
     * z metadat {@see startRun()}.
     *
     * @param array<string,mixed> $meta
     * @return array<string,mixed> sloupec => hodnota
     */
    abstract protected function runMeta(array $meta): array;

    /** Výjimka zdroje pro klíč, který už v mapě je. */
    abstract protected function mapConflict(string $kind, string $key): \RuntimeException;

    /**
     * Doplňkové sloupce přehledu běhů (SQL výrazy včetně aliasu, s úvodní čárkou).
     */
    protected function listExtraColumns(): string
    {
        return '';
    }

    /** @return list<string> vlastní sloupce běhu, které jsou celé číslo nebo NULL */
    protected function nullableIntColumns(): array
    {
        return [];
    }

    public function get(int $supplierId, string $kind, string $key): ?int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT target_id FROM ' . $this->mapTable() . ' WHERE supplier_id = ? AND kind = ? AND ' . $this->keyColumn() . ' = ?'
        );
        $stmt->execute([$supplierId, $kind, self::key($key)]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int) $id;
    }

    /** @return array<string,int> klíč zdroje => target_id */
    public function all(int $supplierId, string $kind): array
    {
        $column = $this->keyColumn();
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . $column . ', target_id FROM ' . $this->mapTable() . ' WHERE supplier_id = ? AND kind = ? ORDER BY ' . $column
        );
        $stmt->execute([$supplierId, $kind]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_NUM) as [$key, $id]) {
            $out[(string) $key] = (int) $id;
        }
        return $out;
    }

    /** @return list<int> */
    public function targets(int $supplierId, string $kind): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT DISTINCT target_id FROM ' . $this->mapTable() . ' WHERE supplier_id = ? AND kind = ?');
        $stmt->execute([$supplierId, $kind]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Zápis do mapy. Klíč, který už v mapě je, je chyba: znamená, že tentýž záznam zdroje
     * založil v MyÚčtu dva doklady (souběžný běh nebo chyba kroku). Tiché přepsání cíle
     * by první doklad z mapy vyřadilo a další běh by ho založil znovu.
     */
    public function put(int $supplierId, string $kind, string $key, int $targetId, ?int $runId): void
    {
        try {
            $this->db->pdo()->prepare(
                'INSERT INTO ' . $this->mapTable() . ' (supplier_id, kind, ' . $this->keyColumn() . ', target_id, run_id) VALUES (?, ?, ?, ?, ?)'
            )->execute([$supplierId, $kind, self::key($key), $targetId, $runId]);
        } catch (\PDOException $e) {
            if ((string) $e->getCode() !== '23000') {
                throw $e;
            }
            throw $this->mapConflict($kind, $key);
        }
    }

    /**
     * Přesměruje existující záznam mapy na jiný cíl — jen tam, kde převod cíl vědomě
     * nahrazuje (cíl mezitím zmizel, např. uživatel smazal hodnotu dimenze).
     */
    public function repoint(int $supplierId, string $kind, string $key, int $targetId, ?int $runId): void
    {
        $this->db->pdo()->prepare(
            'UPDATE ' . $this->mapTable() . ' SET target_id = ?, run_id = ? WHERE supplier_id = ? AND kind = ? AND ' . $this->keyColumn() . ' = ?'
        )->execute([$targetId, $runId, $supplierId, $kind, self::key($key)]);
    }

    public function countAll(int $supplierId): int
    {
        $stmt = $this->db->pdo()->prepare('SELECT COUNT(*) FROM ' . $this->mapTable() . ' WHERE supplier_id = ?');
        $stmt->execute([$supplierId]);
        return (int) $stmt->fetchColumn();
    }

    /** @return array<string,int> druh => počet */
    public function counts(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT kind, COUNT(*) FROM ' . $this->mapTable() . ' WHERE supplier_id = ? GROUP BY kind ORDER BY kind');
        $stmt->execute([$supplierId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_NUM) as [$kind, $n]) {
            $out[(string) $kind] = (int) $n;
        }
        return $out;
    }

    /** Smaže celou mapu firmy (vrácení převodu). */
    public function forgetAll(int $supplierId): void
    {
        $this->db->pdo()->prepare('DELETE FROM ' . $this->mapTable() . ' WHERE supplier_id = ?')->execute([$supplierId]);
    }

    /** @param array<string,mixed> $meta */
    public function startRun(int $supplierId, ?int $jobId, string $mode, array $meta, ?int $userId): int
    {
        $own = $this->runMeta($meta);
        $columns = array_merge(['supplier_id', 'job_id', 'mode', 'status'], array_keys($own), ['created_by']);
        $placeholders = array_merge(['?', '?', '?', '"running"'], array_fill(0, count($own), '?'), ['?']);
        $this->db->pdo()->prepare(
            'INSERT INTO ' . $this->runsTable() . ' (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $placeholders) . ')'
        )->execute(array_merge(
            [$supplierId, $jobId, $mode === 'import' ? 'import' : 'dry_run'],
            array_values($own),
            [$userId],
        ));
        return (int) $this->db->pdo()->lastInsertId();
    }

    /** @param array<string,mixed> $protocol */
    public function finishRun(int $id, int $supplierId, string $status, array $protocol): void
    {
        $json = json_encode($protocol, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
        $this->db->pdo()->prepare(
            'UPDATE ' . $this->runsTable() . ' SET status = ?, protocol = ?, finished_at = NOW() WHERE id = ? AND supplier_id = ?'
        )->execute([$status, $json === false ? null : $json, $id, $supplierId]);
    }

    /**
     * Běhy, které zůstaly „running", ale žádný worker je už nedrží (spadl, byl ukončen
     * pro nečinnost). Volá se se zámkem firmy ({@see acquireLock()}) — kdo ho drží, je
     * jediný živý převod, takže každý jiný „running" řádek je mrtvý.
     */
    public function closeInterruptedRuns(int $supplierId): int
    {
        $protocol = json_encode(['status' => 'failed', 'failure' => 'interrupted', 'error' => 'Převod byl přerušen (worker neodpovídá).', 'steps' => []], JSON_UNESCAPED_UNICODE);
        $stmt = $this->db->pdo()->prepare(
            'UPDATE ' . $this->runsTable() . " SET status = 'failed', finished_at = NOW(), protocol = COALESCE(protocol, ?)
              WHERE supplier_id = ? AND status = 'running'"
        );
        $stmt->execute([$protocol, $supplierId]);
        return $stmt->rowCount();
    }

    /** @return array<string,mixed>|null */
    public function findRun(int $id, int $supplierId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, supplier_id, job_id, mode, status, ' . $this->runMetaColumns() . ',
                    protocol, created_by, created_at, finished_at
               FROM ' . $this->runsTable() . ' WHERE id = ? AND supplier_id = ?'
        );
        $stmt->execute([$id, $supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        $row = $this->cast($row);
        $decoded = $row['protocol'] !== null ? json_decode((string) $row['protocol'], true) : null;
        $row['protocol'] = is_array($decoded) ? $decoded : null;
        return $row;
    }

    /** @return list<array<string,mixed>> běhy bez protokolu (ten je velký — stahuje se v detailu) */
    public function listRuns(int $supplierId, int $limit = 20): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, supplier_id, job_id, mode, status, ' . $this->runMetaColumns() . ',
                    created_by, created_at, finished_at' . $this->listExtraColumns() . '
               FROM ' . $this->runsTable() . ' WHERE supplier_id = ? ORDER BY id DESC LIMIT ?'
        );
        $stmt->bindValue(1, $supplierId, PDO::PARAM_INT);
        $stmt->bindValue(2, max(1, min(100, $limit)), PDO::PARAM_INT);
        $stmt->execute();
        return array_map($this->cast(...), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Smaže protokol doběhlé zkoušky nanečisto. Zkouška se na konci celá vrací, v MyÚčtu po ní
     * nic nezůstává, takže jde jen o záznam. Protokol ostrého převodu ani běžící zkoušku
     * smazat nejde — ostrý převod je auditní stopa převzatých dat.
     */
    public function deleteDryRun(int $id, int $supplierId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'DELETE FROM ' . $this->runsTable() . " WHERE id = ? AND supplier_id = ? AND mode = 'dry_run' AND status <> 'running'"
        );
        $stmt->execute([$id, $supplierId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Stav automatiky, na který se má vrátit: snímek NEJSTARŠÍHO ostrého běhu, po kterém
     * se automatika ještě neobnovila. Novější neobnovené běhy už snímaly automatiku
     * vypnutou po předchozím neúspěšném (nebo spadlém) běhu.
     *
     * Snímek se ukládá před vypnutím automatiky ({@see saveAutomationSnapshot()}), ne až
     * s protokolem na konci běhu — spadlý worker protokol nezapíše.
     *
     * @return array<string,mixed>|null
     */
    public function pendingAutomationSnapshot(int $supplierId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT automation_snapshot FROM ' . $this->runsTable() . "
              WHERE supplier_id = ? AND mode = 'import' AND automation_snapshot IS NOT NULL AND automation_restored_at IS NULL
              ORDER BY id LIMIT 1"
        );
        $stmt->execute([$supplierId]);
        $json = $stmt->fetchColumn();
        $snapshot = is_string($json) ? json_decode($json, true) : null;
        return is_array($snapshot) ? $snapshot : null;
    }

    /** @param array<string,mixed> $snapshot */
    public function saveAutomationSnapshot(int $runId, int $supplierId, array $snapshot): void
    {
        $this->db->pdo()->prepare(
            'UPDATE ' . $this->runsTable() . ' SET automation_snapshot = ? WHERE id = ? AND supplier_id = ? AND automation_snapshot IS NULL'
        )->execute([json_encode($snapshot, JSON_UNESCAPED_UNICODE), $runId, $supplierId]);
    }

    /** Automatika je zpět — žádný dosavadní snímek firmy už nečeká na obnovení. */
    public function markAutomationRestored(int $supplierId): void
    {
        $this->db->pdo()->prepare(
            'UPDATE ' . $this->runsTable() . ' SET automation_restored_at = NOW()
              WHERE supplier_id = ? AND automation_snapshot IS NOT NULL AND automation_restored_at IS NULL'
        )->execute([$supplierId]);
    }

    /**
     * Zámek převodu firmy (MariaDB named lock, drží ho spojení workeru a uvolní se
     * i při pádu procesu). Neblokuje: druhý běh se odmítne, nečeká.
     */
    public function acquireLock(int $supplierId): bool
    {
        $stmt = $this->db->pdo()->prepare('SELECT GET_LOCK(' . $this->lockSql() . ', 0)');
        $stmt->execute([$supplierId]);
        return (int) $stmt->fetchColumn() === 1;
    }

    public function releaseLock(int $supplierId): void
    {
        $this->db->pdo()->prepare('SELECT RELEASE_LOCK(' . $this->lockSql() . ')')->execute([$supplierId]);
    }

    /** Neběží teď převod firmy? (Zámek nedrží žádné spojení.) */
    public function isLockFree(int $supplierId): bool
    {
        $stmt = $this->db->pdo()->prepare('SELECT IS_FREE_LOCK(' . $this->lockSql() . ')');
        $stmt->execute([$supplierId]);
        return (int) $stmt->fetchColumn() === 1;
    }

    protected static function key(string $key): string
    {
        return mb_substr($key, 0, 190);
    }

    protected static function str(mixed $value, int $max): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        return mb_substr((string) $value, 0, $max);
    }

    /** Jméno zámku je na serveru globální — obsahuje proto i databázi (instalace sdílí server). */
    private function lockSql(): string
    {
        return "CONCAT('" . $this->lockPrefix() . ":', DATABASE(), ':', ?)";
    }

    private function runMetaColumns(): string
    {
        return implode(', ', array_keys($this->runMeta([])));
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function cast(array $row): array
    {
        foreach (['id', 'supplier_id'] as $k) {
            $row[$k] = (int) $row[$k];
        }
        foreach (array_merge(['job_id', 'created_by'], $this->nullableIntColumns()) as $k) {
            $row[$k] = $row[$k] !== null ? (int) $row[$k] : null;
        }
        return $row;
    }
}
