<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Poznámky účetního zápisu (1:N) — doplněk k jednořádkovému `description` (§35).
 *
 * Všechny dotazy jsou scoped tenantem (supplier_id) A zápisem (entry_id), aby
 * poznámka zápisu A nikdy neprosákla k zápisu B ani k jinému dodavateli. Samotné
 * `id` se nikdy nedůvěřuje — `find()` vyžaduje celou trojici.
 *
 * Mazání je SOFT (`deleted_at`), tabulka NENÍ system-versioned (viz migrace 1129).
 */
final class JournalEntryNoteRepository
{
    /** Strop délky poznámky (TEXT unese víc, tohle je aplikační sanity limit). */
    public const MAX_BODY_LENGTH = 5000;

    /** Strop počtu živých poznámek na zápis — pojistka proti zaplevelení. */
    public const MAX_NOTES_PER_ENTRY = 200;

    public function __construct(private readonly Connection $db) {}

    /**
     * Živé poznámky zápisu. Připnuté první, pak nejnovější — shodně s indexem
     * idx_jen_entry (supplier_id, entry_id, pinned DESC, created_at DESC).
     *
     * @return list<array<string,mixed>>
     */
    public function list(int $entryId, int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT n.id, n.entry_id, n.supplier_id, n.body, n.pinned,
                    n.created_by, n.created_at, n.updated_by, n.updated_at,
                    cu.name AS created_by_name, uu.name AS updated_by_name
               FROM journal_entry_notes n
               LEFT JOIN users cu ON cu.id = n.created_by
               LEFT JOIN users uu ON uu.id = n.updated_by
              WHERE n.entry_id = ? AND n.supplier_id = ? AND n.deleted_at IS NULL
              ORDER BY n.pinned DESC, n.created_at DESC, n.id DESC'
        );
        $stmt->execute([$entryId, $supplierId]);
        return array_map([$this, 'cast'], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    public function find(int $id, int $entryId, int $supplierId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT n.id, n.entry_id, n.supplier_id, n.body, n.pinned,
                    n.created_by, n.created_at, n.updated_by, n.updated_at,
                    cu.name AS created_by_name, uu.name AS updated_by_name
               FROM journal_entry_notes n
               LEFT JOIN users cu ON cu.id = n.created_by
               LEFT JOIN users uu ON uu.id = n.updated_by
              WHERE n.id = ? AND n.entry_id = ? AND n.supplier_id = ? AND n.deleted_at IS NULL'
        );
        $stmt->execute([$id, $entryId, $supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->cast($row);
    }

    /** Počet živých poznámek zápisu (limit guard + badge v UI). */
    public function countLive(int $entryId, int $supplierId): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM journal_entry_notes
              WHERE entry_id = ? AND supplier_id = ? AND deleted_at IS NULL'
        );
        $stmt->execute([$entryId, $supplierId]);
        return (int) $stmt->fetchColumn();
    }

    public function add(int $entryId, int $supplierId, string $body, bool $pinned, ?int $createdBy): int
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO journal_entry_notes (entry_id, supplier_id, body, pinned, created_by)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$entryId, $supplierId, $body, $pinned ? 1 : 0, $createdBy]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    /**
     * Částečná editace — mění se jen předaná pole. `updated_at` doplní DB
     * (ON UPDATE CURRENT_TIMESTAMP), `updated_by` píšeme explicitně.
     */
    public function update(int $id, int $entryId, int $supplierId, ?string $body, ?bool $pinned, ?int $updatedBy): bool
    {
        $sets   = [];
        $params = [];
        if ($body !== null) {
            $sets[]   = 'body = ?';
            $params[] = $body;
        }
        if ($pinned !== null) {
            $sets[]   = 'pinned = ?';
            $params[] = $pinned ? 1 : 0;
        }
        if ($sets === []) {
            return false;
        }
        $sets[]   = 'updated_by = ?';
        $params[] = $updatedBy;

        $params[] = $id;
        $params[] = $entryId;
        $params[] = $supplierId;

        $stmt = $this->db->pdo()->prepare(
            'UPDATE journal_entry_notes SET ' . implode(', ', $sets)
            . ' WHERE id = ? AND entry_id = ? AND supplier_id = ? AND deleted_at IS NULL'
        );
        $stmt->execute($params);
        return $stmt->rowCount() > 0;
    }

    /** Soft delete — řádek zůstává kvůli dohledatelnosti, z API mizí. */
    public function softDelete(int $id, int $entryId, int $supplierId, ?int $deletedBy): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE journal_entry_notes
                SET deleted_at = CURRENT_TIMESTAMP, updated_by = ?
              WHERE id = ? AND entry_id = ? AND supplier_id = ? AND deleted_at IS NULL'
        );
        $stmt->execute([$deletedBy, $id, $entryId, $supplierId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Počty živých poznámek pro sadu zápisů — badge ve výpisu deníku bez N+1.
     *
     * @param  list<int> $entryIds
     * @return array<int,int> entry_id => count
     */
    public function countsForEntries(array $entryIds, int $supplierId): array
    {
        $entryIds = array_values(array_unique(array_filter(array_map('intval', $entryIds), static fn (int $i): bool => $i > 0)));
        if ($entryIds === []) {
            return [];
        }
        $in   = implode(',', array_fill(0, count($entryIds), '?'));
        $stmt = $this->db->pdo()->prepare(
            'SELECT entry_id, COUNT(*) AS c FROM journal_entry_notes
              WHERE supplier_id = ? AND deleted_at IS NULL AND entry_id IN (' . $in . ')
              GROUP BY entry_id'
        );
        $stmt->execute(array_merge([$supplierId], $entryIds));
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $out[(int) $r['entry_id']] = (int) $r['c'];
        }
        return $out;
    }

    private function cast(array $r): array
    {
        return [
            'id'              => (int) $r['id'],
            'entry_id'        => (int) $r['entry_id'],
            'supplier_id'     => (int) $r['supplier_id'],
            'body'            => (string) $r['body'],
            'pinned'          => (bool) $r['pinned'],
            'created_by'      => $r['created_by'] !== null ? (int) $r['created_by'] : null,
            'created_by_name' => $r['created_by_name'] !== null ? (string) $r['created_by_name'] : null,
            'created_at'      => (string) $r['created_at'],
            'updated_by'      => $r['updated_by'] !== null ? (int) $r['updated_by'] : null,
            'updated_by_name' => $r['updated_by_name'] !== null ? (string) $r['updated_by_name'] : null,
            'updated_at'      => $r['updated_at'] !== null ? (string) $r['updated_at'] : null,
        ];
    }
}
