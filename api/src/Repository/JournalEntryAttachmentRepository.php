<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Přílohy účetního zápisu (§33a průkaznost) — dedikovaná tabulka pod accounting
 * RBAC s VLASTNÍM disk namespace (storage/journal/sup-{id}/…), ne DMS asociace.
 *
 * Všechny dotazy jsou scoped tenantem (supplier_id) A zápisem (entry_id), aby
 * příloha zápisu A nikdy neprosákla k zápisu B ani k jinému dodavateli.
 */
final class JournalEntryAttachmentRepository
{
    public function __construct(private readonly Connection $db) {}

    /** @return list<array<string,mixed>> */
    public function list(int $entryId, int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, entry_id, supplier_id, sha256, filename, original_name, mime_type,
                    size_bytes, doc_type, description, uploaded_by, uploaded_at
               FROM journal_entry_attachments
              WHERE entry_id = ? AND supplier_id = ?
              ORDER BY uploaded_at ASC, id ASC'
        );
        $stmt->execute([$entryId, $supplierId]);
        return array_map([$this, 'cast'], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    public function find(int $id, int $entryId, int $supplierId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, entry_id, supplier_id, sha256, filename, original_name, mime_type,
                    size_bytes, doc_type, description, uploaded_by, uploaded_at
               FROM journal_entry_attachments
              WHERE id = ? AND entry_id = ? AND supplier_id = ?'
        );
        $stmt->execute([$id, $entryId, $supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->cast($row);
    }

    /** Dedup guard: existující příloha téhož obsahu u téhož zápisu (→ 409 v Action). */
    public function findBySha(int $entryId, int $supplierId, string $sha256): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, entry_id, supplier_id, sha256, filename, original_name, mime_type,
                    size_bytes, doc_type, description, uploaded_by, uploaded_at
               FROM journal_entry_attachments
              WHERE entry_id = ? AND supplier_id = ? AND sha256 = ?
              LIMIT 1'
        );
        $stmt->execute([$entryId, $supplierId, $sha256]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->cast($row);
    }

    public function totalSize(int $entryId, int $supplierId): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COALESCE(SUM(size_bytes), 0) FROM journal_entry_attachments
              WHERE entry_id = ? AND supplier_id = ?'
        );
        $stmt->execute([$entryId, $supplierId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Ref-counting nad VLASTNÍM namespace: kolik příloh dodavatele ukazuje na daný
     * sha256 (dedup-aware mazání bajtů). ZÁMĚRNĚ počítá JEN journal_entry_attachments —
     * nekříží se s DMS union (documents + document_files, §4.4). $excludeId = právě
     * mazaná příloha (0 = žádná).
     */
    public function countBySha(int $supplierId, string $sha256, int $excludeId = 0): int
    {
        $sql = 'SELECT COUNT(*) FROM journal_entry_attachments WHERE supplier_id = ? AND sha256 = ?';
        $params = [$supplierId, $sha256];
        if ($excludeId > 0) {
            $sql .= ' AND id <> ?';
            $params[] = $excludeId;
        }
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    public function add(
        int $entryId,
        int $supplierId,
        string $sha256,
        string $filename,
        ?string $originalName,
        ?string $mimeType,
        int $sizeBytes,
        ?string $docType,
        ?string $description,
        ?int $uploadedBy,
    ): int {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO journal_entry_attachments
                (entry_id, supplier_id, sha256, filename, original_name, mime_type,
                 size_bytes, doc_type, description, uploaded_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $entryId, $supplierId, $sha256, $filename, $originalName, $mimeType,
            $sizeBytes, $docType, $description, $uploadedBy,
        ]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    public function delete(int $id, int $entryId, int $supplierId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'DELETE FROM journal_entry_attachments WHERE id = ? AND entry_id = ? AND supplier_id = ?'
        );
        $stmt->execute([$id, $entryId, $supplierId]);
        return $stmt->rowCount() > 0;
    }

    public function updateDescription(int $id, int $entryId, int $supplierId, ?string $description): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE journal_entry_attachments SET description = ?
              WHERE id = ? AND entry_id = ? AND supplier_id = ?'
        );
        $stmt->execute([$description, $id, $entryId, $supplierId]);
        return $stmt->rowCount() > 0;
    }

    private function cast(array $r): array
    {
        return [
            'id'            => (int) $r['id'],
            'entry_id'      => (int) $r['entry_id'],
            'supplier_id'   => (int) $r['supplier_id'],
            'sha256'        => (string) $r['sha256'],
            'filename'      => (string) $r['filename'],
            'original_name' => $r['original_name'] !== null ? (string) $r['original_name'] : null,
            'mime_type'     => $r['mime_type'] !== null ? (string) $r['mime_type'] : null,
            'size_bytes'    => $r['size_bytes'] !== null ? (int) $r['size_bytes'] : null,
            'doc_type'      => $r['doc_type'] !== null ? (string) $r['doc_type'] : null,
            'description'   => $r['description'] !== null ? (string) $r['description'] : null,
            'uploaded_by'   => $r['uploaded_by'] !== null ? (int) $r['uploaded_by'] : null,
            'uploaded_at'   => (string) $r['uploaded_at'],
        ];
    }
}
