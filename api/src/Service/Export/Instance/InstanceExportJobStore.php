<?php

declare(strict_types=1);

namespace MyInvoice\Service\Export\Instance;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;
use PDOException;

/**
 * Perzistence běhů exportu (`instance_exports`, migrace 1520).
 *
 * Vzor je {@see \MyInvoice\Repository\ImportJobRepository} — včetně `reapStale()`,
 * bez kterého by mrtvý worker (spadlý proces, killnutý deploy) navždy blokoval
 * spuštění dalšího exportu, protože by v DB zůstal viset jako `running`.
 *
 * KAŽDÝ dotaz je vázaný na `supplier_id`. Jedinou výjimkou jsou metody, které volá
 * worker sám na sebe podle `id` jobu (`markRunning`, `appendLog`, …) a úklid
 * expirovaných archivů — ty ID dostávají z DB, ne z requestu.
 */
final class InstanceExportJobStore
{
    /** Po téhle době bez update se běžící export považuje za mrtvý. */
    private const STALE_MINUTES = 60;

    /** @var list<string> */
    private const COLUMNS = [
        'id', 'supplier_id', 'status', 'parts', 'date_from', 'date_to',
        'total_steps', 'processed_steps', 'current_step', 'log_text', 'last_error',
        'cancel_requested', 'result_path', 'result_name', 'size_bytes', 'sha256',
        'encrypted', 'manifest', 'expires_at', 'started_at', 'finished_at',
        'created_by', 'created_at', 'updated_at',
    ];

    public function __construct(private readonly Connection $db) {}

    /**
     * Založí job ve stavu `queued`.
     *
     * @param list<string> $parts
     * @throws InstanceExportException když už jeden běží (UNIQUE `uq_instance_exports_active`)
     */
    public function create(
        int $supplierId,
        array $parts,
        ?string $dateFrom,
        ?string $dateTo,
        ?int $userId,
    ): int {
        $this->reapStale($supplierId);
        try {
            $stmt = $this->db->pdo()->prepare(
                'INSERT INTO instance_exports (supplier_id, parts, date_from, date_to, created_by)
                 VALUES (?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $supplierId,
                json_encode(array_values($parts), JSON_UNESCAPED_UNICODE),
                $dateFrom,
                $dateTo,
                $userId,
            ]);
        } catch (PDOException $e) {
            if ($this->isDuplicate($e)) {
                throw new InstanceExportException(
                    'already_running',
                    'Export téhle firmy už běží. Počkej, až doběhne, nebo ho zruš.',
                    409,
                );
            }
            throw $e;
        }
        return (int) $this->db->pdo()->lastInsertId();
    }

    /** @return array<string,mixed>|null */
    public function find(int $id, int $supplierId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . $this->columnList() . ' FROM instance_exports WHERE id = ? AND supplier_id = ?'
        );
        $stmt->execute([$id, $supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->cast($row);
    }

    /**
     * Job podle ID bez tenant filtru — VÝHRADNĚ pro worker, který ID dostal
     * od sebe sama (CLI argument). Nikdy nevolat s hodnotou z HTTP requestu.
     *
     * @return array<string,mixed>|null
     */
    public function findById(int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . $this->columnList() . ' FROM instance_exports WHERE id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->cast($row);
    }

    /** @return list<array<string,mixed>> */
    public function listForSupplier(int $supplierId, int $limit = 20): array
    {
        $limit = max(1, min($limit, 100));
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . $this->columnList() . ' FROM instance_exports
              WHERE supplier_id = ?
              ORDER BY created_at DESC, id DESC
              LIMIT ' . $limit
        );
        $stmt->execute([$supplierId]);
        return array_map(fn (array $r): array => $this->cast($r), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return array<string,mixed>|null běžící/čekající export firmy */
    public function activeFor(int $supplierId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . $this->columnList() . ' FROM instance_exports
              WHERE supplier_id = ? AND status IN ("queued", "running")
              ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([$supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->cast($row);
    }

    /** Atomický přechod queued → running (druhý worker prohraje). */
    public function markRunning(int $id): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE instance_exports SET status = "running", started_at = NOW()
              WHERE id = ? AND status = "queued"'
        );
        $stmt->execute([$id]);
        return $stmt->rowCount() === 1;
    }

    /** @param array<string,mixed> $updates */
    public function updateProgress(int $id, array $updates): void
    {
        $allowed = ['total_steps', 'processed_steps', 'current_step'];
        $set = [];
        $params = [];
        foreach ($allowed as $column) {
            if (array_key_exists($column, $updates)) {
                $set[] = '`' . $column . '` = ?';
                $params[] = $updates[$column];
            }
        }
        if ($set === []) {
            return;
        }
        // updated_at je ON UPDATE CURRENT_TIMESTAMP, ale jen když se hodnota změní —
        // explicitní zápis drží "worker žije" i při stejném progressu (reapStale).
        $set[] = 'updated_at = NOW()';
        $params[] = $id;
        $this->db->pdo()
            ->prepare('UPDATE instance_exports SET ' . implode(', ', $set) . ' WHERE id = ?')
            ->execute($params);
    }

    public function appendLog(int $id, string $line): void
    {
        $this->db->pdo()->prepare(
            'UPDATE instance_exports
                SET log_text = CONCAT(COALESCE(log_text, ""), ?), updated_at = NOW()
              WHERE id = ?'
        )->execute([date('H:i:s') . ' ' . $line . "\n", $id]);
    }

    /** @param array<string,mixed> $manifest */
    public function setResult(
        int $id,
        string $relPath,
        string $name,
        int $size,
        string $sha256,
        bool $encrypted,
        array $manifest,
        string $expiresAt,
    ): void {
        $this->db->pdo()->prepare(
            'UPDATE instance_exports
                SET result_path = ?, result_name = ?, size_bytes = ?, sha256 = ?,
                    encrypted = ?, manifest = ?, expires_at = ?
              WHERE id = ?'
        )->execute([
            $relPath, $name, $size, $sha256, $encrypted ? 1 : 0,
            json_encode($manifest, JSON_UNESCAPED_UNICODE), $expiresAt, $id,
        ]);
    }

    public function markCompleted(int $id): void
    {
        $this->db->pdo()
            ->prepare('UPDATE instance_exports SET status = "completed", finished_at = NOW(), current_step = "Hotovo" WHERE id = ?')
            ->execute([$id]);
    }

    public function markFailed(int $id, string $error): void
    {
        $this->db->pdo()
            ->prepare('UPDATE instance_exports SET status = "failed", finished_at = NOW(), last_error = ? WHERE id = ?')
            ->execute([mb_substr($error, 0, 2000), $id]);
    }

    public function markCancelled(int $id): void
    {
        $this->db->pdo()
            ->prepare('UPDATE instance_exports SET status = "cancelled", finished_at = NOW() WHERE id = ?')
            ->execute([$id]);
    }

    public function requestCancel(int $id, int $supplierId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE instance_exports SET cancel_requested = 1, updated_at = NOW()
              WHERE id = ? AND supplier_id = ? AND status IN ("queued", "running")'
        );
        $stmt->execute([$id, $supplierId]);
        return $stmt->rowCount() === 1;
    }

    public function isCancelRequested(int $id): bool
    {
        $stmt = $this->db->pdo()->prepare('SELECT cancel_requested FROM instance_exports WHERE id = ?');
        $stmt->execute([$id]);
        return (bool) $stmt->fetchColumn();
    }

    public function delete(int $id, int $supplierId): bool
    {
        $stmt = $this->db->pdo()->prepare('DELETE FROM instance_exports WHERE id = ? AND supplier_id = ?');
        $stmt->execute([$id, $supplierId]);
        return $stmt->rowCount() === 1;
    }

    /**
     * Uklidí zaseknuté joby (mrtvý worker). Bez tohohle by jeden spadlý proces
     * navždy zablokoval `uq_instance_exports_active` i souborový zámek dané firmy.
     *
     * @return int počet uklizených
     */
    public function reapStale(?int $supplierId = null, int $staleMinutes = self::STALE_MINUTES): int
    {
        $sql = 'UPDATE instance_exports
                   SET status = "failed", finished_at = NOW(),
                       last_error = "Export ukončen jako neaktivní — proces neodpovídá. Spusť ho znovu."
                 WHERE status IN ("queued", "running")
                   AND updated_at < (NOW() - INTERVAL ? MINUTE)';
        $params = [$staleMinutes];
        if ($supplierId !== null) {
            $sql .= ' AND supplier_id = ?';
            $params[] = $supplierId;
        }
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    /**
     * Hotové archivy po expiraci — kandidáti na smazání.
     *
     * @return list<array<string,mixed>>
     */
    public function expired(int $limit = 200): array
    {
        $limit = max(1, min($limit, 1000));
        $stmt = $this->db->pdo()->query(
            'SELECT ' . $this->columnList() . ' FROM instance_exports
              WHERE expires_at IS NOT NULL AND expires_at < NOW() AND result_path IS NOT NULL
              ORDER BY expires_at ASC
              LIMIT ' . $limit
        );
        return array_map(fn (array $r): array => $this->cast($r), $stmt?->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /** Zapomene soubor u expirovaného archivu (řádek zůstává jako stopa v historii). */
    public function forgetResult(int $id): void
    {
        $this->db->pdo()->prepare(
            'UPDATE instance_exports
                SET result_path = NULL, size_bytes = NULL, expires_at = NULL,
                    current_step = "Archiv expiroval a byl smazán"
              WHERE id = ?'
        )->execute([$id]);
    }

    // ── interní ───────────────────────────────────────────────────────────────

    private function columnList(): string
    {
        return implode(', ', array_map(static fn (string $c): string => '`' . $c . '`', self::COLUMNS));
    }

    /**
     * Právě duplicitní klíč (MySQL 1062), ne libovolné porušení integrity.
     * SQLSTATE 23000 samo nestačí — spadá pod něj i porušený cizí klíč (neexistující
     * firma), a to je chyba volajícího, ne „už běží".
     */
    private function isDuplicate(PDOException $e): bool
    {
        return (int) ($e->errorInfo[1] ?? 0) === 1062;
    }

    /**
     * @param array<string,mixed> $r
     * @return array<string,mixed>
     */
    private function cast(array $r): array
    {
        $r['id'] = (int) $r['id'];
        $r['supplier_id'] = (int) $r['supplier_id'];
        $r['processed_steps'] = (int) $r['processed_steps'];
        $r['total_steps'] = $r['total_steps'] === null ? null : (int) $r['total_steps'];
        $r['size_bytes'] = $r['size_bytes'] === null ? null : (int) $r['size_bytes'];
        $r['created_by'] = $r['created_by'] === null ? null : (int) $r['created_by'];
        $r['cancel_requested'] = (bool) $r['cancel_requested'];
        $r['encrypted'] = (bool) $r['encrypted'];
        foreach (['parts', 'manifest'] as $jsonColumn) {
            if (is_string($r[$jsonColumn] ?? null)) {
                $decoded = json_decode((string) $r[$jsonColumn], true);
                $r[$jsonColumn] = is_array($decoded) ? $decoded : null;
            }
        }
        return $r;
    }
}
