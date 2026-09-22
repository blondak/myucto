<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Migration\Pohoda\PohodaException;
use PDO;

/**
 * Převody z POHODY: běhy průvodce s protokolem (`pohoda_imports`) a mapa „co už
 * z exportu v MyÚčtu vzniklo" (`pohoda_import_map`, migrace 1844). Stejný princip jako
 * {@see MoneyS3ImportRepository}: mapa nese idempotenci, všechno je tenantové a převod
 * jedné firmy smí běžet jen jednou naráz ({@see acquireLock()}).
 */
final class PohodaImportRepository
{
    public const KIND_PERIOD = 'period';
    public const KIND_ACCOUNT = 'account';
    public const KIND_JOURNAL_ENTRY = 'journal_entry';
    public const KIND_CLIENT = 'client';
    public const KIND_CLIENT_MATCH = 'client_match';
    public const KIND_POSTING_RULE = 'posting_rule';
    public const KIND_INVOICE = 'invoice';
    public const KIND_PURCHASE_INVOICE = 'purchase_invoice';
    public const KIND_CASH_REGISTER = 'cash_register';
    public const KIND_CASH_DOCUMENT = 'cash_document';
    public const KIND_BANK_STATEMENT = 'bank_statement';
    public const KIND_BANK_TRANSACTION = 'bank_transaction';
    public const KIND_PAYMENT = 'payment';
    /**
     * Úhrada, kterou převod odvodil u pohybu bez zápisu v deníku POHODY
     * ({@see \MyInvoice\Service\Migration\Pohoda\UnbookedBankPayments}): `tx|id pohybu` => payment_matches.id.
     */
    public const KIND_DERIVED_MATCH = 'derived_match';
    /** Zápis odvozené úhrady a jeho storno: `entry|pohyb|zápis`, `reversal|pohyb|zápis` => journal_entries.id. */
    public const KIND_DERIVED_ENTRY = 'derived_entry';
    public const KIND_ASSET = 'asset';
    public const KIND_SMALL_ASSET = 'small_asset';
    /** Převedený měsíc mezd: `období|otisk sešitu` => id dávky importu docházky. */
    public const KIND_PAYROLL_MONTH = 'payroll_month';
    /** Převedená trvalá srážka: reference srážky v PAMICA => id případu nebo dohody. */
    public const KIND_PAYROLL_DEDUCTION = 'payroll_deduction';

    /** Jméno zámku je na serveru globální - obsahuje proto i databázi. */
    private const LOCK_SQL = "CONCAT('pohoda:', DATABASE(), ':', ?)";

    public function __construct(private readonly Connection $db) {}

    public function get(int $supplierId, string $kind, string $key): ?int
    {
        $stmt = $this->db->pdo()->prepare('SELECT target_id FROM pohoda_import_map WHERE supplier_id = ? AND kind = ? AND pohoda_key = ?');
        $stmt->execute([$supplierId, $kind, self::key($key)]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int) $id;
    }

    /** @return array<string,int> pohoda_key => target_id */
    public function all(int $supplierId, string $kind): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT pohoda_key, target_id FROM pohoda_import_map WHERE supplier_id = ? AND kind = ? ORDER BY pohoda_key');
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
        $stmt = $this->db->pdo()->prepare('SELECT DISTINCT target_id FROM pohoda_import_map WHERE supplier_id = ? AND kind = ?');
        $stmt->execute([$supplierId, $kind]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Klíč, který už v mapě je, je chyba: tentýž záznam z POHODY by v MyÚčtu založil dva
     * doklady a další běh by jeden z nich z mapy ztratil.
     */
    public function put(int $supplierId, string $kind, string $key, int $targetId, ?int $runId): void
    {
        try {
            $this->db->pdo()->prepare(
                'INSERT INTO pohoda_import_map (supplier_id, kind, pohoda_key, target_id, run_id) VALUES (?, ?, ?, ?, ?)'
            )->execute([$supplierId, $kind, self::key($key), $targetId, $runId]);
        } catch (\PDOException $e) {
            if ((string) $e->getCode() !== '23000') {
                throw $e;
            }
            throw new PohodaException('map_conflict', "Záznam {$kind} {$key} z POHODY už v MyÚčtu převedený je - převod se zastavil, aby nic nezdvojil.");
        }
    }

    /** @return array<string,int> druh => počet */
    public function counts(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT kind, COUNT(*) FROM pohoda_import_map WHERE supplier_id = ? GROUP BY kind ORDER BY kind');
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
        $this->db->pdo()->prepare('DELETE FROM pohoda_import_map WHERE supplier_id = ?')->execute([$supplierId]);
    }

    /** @param array<string,mixed> $meta */
    public function startRun(int $supplierId, ?int $jobId, string $mode, array $meta, ?int $userId): int
    {
        $this->db->pdo()->prepare(
            'INSERT INTO pohoda_imports (supplier_id, job_id, mode, status, agenda_ico, agenda_year, pohoda_version, export_sha256, created_by)
             VALUES (?, ?, ?, "running", ?, ?, ?, ?, ?)'
        )->execute([
            $supplierId,
            $jobId,
            $mode === 'import' ? 'import' : 'dry_run',
            self::str($meta['ico'] ?? null, 20),
            isset($meta['year']) ? (int) $meta['year'] : null,
            self::str($meta['program'] ?? null, 60),
            self::str($meta['sha256'] ?? null, 64),
            $userId,
        ]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    /** @param array<string,mixed> $protocol */
    public function finishRun(int $id, int $supplierId, string $status, array $protocol): void
    {
        $json = json_encode($protocol, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
        $this->db->pdo()->prepare('UPDATE pohoda_imports SET status = ?, protocol = ?, finished_at = NOW() WHERE id = ? AND supplier_id = ?')
            ->execute([$status, $json === false ? null : $json, $id, $supplierId]);
    }

    /**
     * Běhy, které zůstaly „running", ale žádný worker je nedrží. Volá se se zámkem firmy -
     * kdo ho drží, je jediný živý převod.
     */
    public function closeInterruptedRuns(int $supplierId): int
    {
        $protocol = json_encode(['status' => 'failed', 'failure' => 'interrupted', 'error' => 'Převod byl přerušen (worker neodpovídá).', 'steps' => []], JSON_UNESCAPED_UNICODE);
        $stmt = $this->db->pdo()->prepare(
            "UPDATE pohoda_imports SET status = 'failed', finished_at = NOW(), protocol = COALESCE(protocol, ?)
              WHERE supplier_id = ? AND status = 'running'"
        );
        $stmt->execute([$protocol, $supplierId]);
        return $stmt->rowCount();
    }

    /** @return array<string,mixed>|null */
    public function findRun(int $id, int $supplierId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, supplier_id, job_id, mode, status, agenda_ico, agenda_year, pohoda_version, export_sha256,
                    protocol, created_by, created_at, finished_at
               FROM pohoda_imports WHERE id = ? AND supplier_id = ?'
        );
        $stmt->execute([$id, $supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        $row = self::cast($row);
        $decoded = $row['protocol'] !== null ? json_decode((string) $row['protocol'], true) : null;
        $row['protocol'] = is_array($decoded) ? $decoded : null;
        return $row;
    }

    /**
     * Smaže protokol doběhlé zkoušky nanečisto. Zkouška se na konci celá vrací, v MyÚčtu po ní
     * nic nezůstává, takže jde jen o záznam. Protokol ostrého převodu ani běžící zkoušku
     * smazat nejde - ostrý převod je auditní stopa převzatých dat.
     */
    public function deleteDryRun(int $id, int $supplierId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            "DELETE FROM pohoda_imports WHERE id = ? AND supplier_id = ? AND mode = 'dry_run' AND status <> 'running'"
        );
        $stmt->execute([$id, $supplierId]);
        return $stmt->rowCount() > 0;
    }

    /** @return list<array<string,mixed>> běhy bez protokolu (ten je velký - stahuje se v detailu) */
    public function listRuns(int $supplierId, int $limit = 20): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT id, supplier_id, job_id, mode, status, agenda_ico, agenda_year, pohoda_version, export_sha256,
                    created_by, created_at, finished_at,
                    COALESCE(JSON_UNQUOTE(JSON_EXTRACT(protocol, '$.kind')), 'accounting') AS kind
               FROM pohoda_imports WHERE supplier_id = ? ORDER BY id DESC LIMIT ?"
        );
        $stmt->bindValue(1, $supplierId, PDO::PARAM_INT);
        $stmt->bindValue(2, max(1, min(100, $limit)), PDO::PARAM_INT);
        $stmt->execute();
        return array_map(self::cast(...), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Stav automatiky před PRVNÍM ostrým během, po kterém se automatika neobnovila - stejné
     * pravidlo jako u Money S3.
     *
     * @return array<string,mixed>|null
     */
    public function pendingAutomationSnapshot(int $supplierId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT automation_snapshot FROM pohoda_imports
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
        $this->db->pdo()->prepare('UPDATE pohoda_imports SET automation_snapshot = ? WHERE id = ? AND supplier_id = ? AND automation_snapshot IS NULL')
            ->execute([json_encode($snapshot, JSON_UNESCAPED_UNICODE), $runId, $supplierId]);
    }

    public function markAutomationRestored(int $supplierId): void
    {
        $this->db->pdo()->prepare(
            'UPDATE pohoda_imports SET automation_restored_at = NOW()
              WHERE supplier_id = ? AND automation_snapshot IS NOT NULL AND automation_restored_at IS NULL'
        )->execute([$supplierId]);
    }

    /** Zámek převodu firmy (MariaDB named lock); neblokuje, druhý běh se odmítne. */
    public function acquireLock(int $supplierId): bool
    {
        $stmt = $this->db->pdo()->prepare('SELECT GET_LOCK(' . self::LOCK_SQL . ', 0)');
        $stmt->execute([$supplierId]);
        return (int) $stmt->fetchColumn() === 1;
    }

    public function releaseLock(int $supplierId): void
    {
        $this->db->pdo()->prepare('SELECT RELEASE_LOCK(' . self::LOCK_SQL . ')')->execute([$supplierId]);
    }

    public function isLockFree(int $supplierId): bool
    {
        $stmt = $this->db->pdo()->prepare('SELECT IS_FREE_LOCK(' . self::LOCK_SQL . ')');
        $stmt->execute([$supplierId]);
        return (int) $stmt->fetchColumn() === 1;
    }

    private static function key(string $key): string
    {
        return mb_substr($key, 0, 190);
    }

    private static function str(mixed $value, int $max): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        return mb_substr((string) $value, 0, $max);
    }

    /** @param array<string,mixed> $row */
    private static function cast(array $row): array
    {
        foreach (['id', 'supplier_id'] as $k) {
            $row[$k] = (int) $row[$k];
        }
        foreach (['job_id', 'created_by', 'agenda_year'] as $k) {
            $row[$k] = $row[$k] !== null ? (int) $row[$k] : null;
        }
        return $row;
    }
}
