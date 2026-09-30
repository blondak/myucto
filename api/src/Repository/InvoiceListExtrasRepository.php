<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

final class InvoiceListExtrasRepository
{
    public function __construct(private readonly Connection $db) {}

    public function forDocuments(int $supplierId, string $source, array $ids, bool $includeNotes, ?DocumentViewerContext $tagViewer): array
    {
        if ($ids === []) return [];
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $result = [];
        if ($includeNotes) {
            $stmt = $this->db->pdo()->prepare(
                "SELECT je.source_id AS document_id, n.body
                   FROM journal_entries je
                   JOIN journal_entry_notes n ON n.entry_id = je.id AND n.supplier_id = je.supplier_id
                  WHERE je.supplier_id = ? AND je.source_type = ? AND je.source_id IN ({$placeholders})
                    AND je.posted_at IS NOT NULL AND je.reversed_by IS NULL AND n.deleted_at IS NULL
                  ORDER BY je.source_id, n.pinned DESC, n.created_at DESC, n.id DESC"
            );
            $stmt->execute([$supplierId, $source, ...$ids]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $result[(int) $row['document_id']]['journal_notes'][] = (string) $row['body'];
            }
        }
        if ($tagViewer !== null) {
            [$visibilitySql, $visibilityParams] = DocumentVisibility::clause($tagViewer, 'd');
            $stmt = $this->db->pdo()->prepare(
                "SELECT DISTINCT l.entity_id AS document_id, t.name
                   FROM document_links l
                   JOIN documents d ON d.id = l.document_id AND d.supplier_id = l.supplier_id
                   JOIN document_tag_map m ON m.document_id = d.id
                   JOIN document_tags t ON t.id = m.tag_id AND t.supplier_id = d.supplier_id
                  WHERE d.supplier_id = ? AND l.entity_type = ? AND l.entity_id IN ({$placeholders})
                    AND d.deleted_at IS NULL {$visibilitySql}
                  ORDER BY l.entity_id, t.name"
            );
            $stmt->execute([$supplierId, $source, ...$ids, ...$visibilityParams]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $result[(int) $row['document_id']]['document_tags'][] = (string) $row['name'];
            }
        }
        foreach ($ids as $id) {
            if ($includeNotes) $result[$id]['journal_notes'] ??= [];
            if ($tagViewer !== null) $result[$id]['document_tags'] ??= [];
        }
        return $result;
    }
}
