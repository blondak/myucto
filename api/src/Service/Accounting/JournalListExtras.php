<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Volitelné sloupce seznamu deníku: poznámka a počet dokumentů zápisu. Načítají se jedním
 * dotazem za stránku a jen tehdy, když si je uživatel zapne (`include_notes`,
 * `include_documents` v GET /accounting/journal), ať výchozí seznam nic nezpomalí.
 */
final class JournalListExtras
{
    private const PREVIEW_LENGTH = 200;

    public function __construct(private readonly Connection $db) {}

    /**
     * Připnutá, jinak nejnovější poznámka zápisu a počet jeho poznámek.
     *
     * @param list<int> $entryIds
     * @return array<int, array{preview:string, count:int}>
     */
    public function notes(int $supplierId, array $entryIds): array
    {
        if ($entryIds === []) {
            return [];
        }
        $in = implode(',', array_fill(0, count($entryIds), '?'));
        $stmt = $this->db->pdo()->prepare(
            "SELECT entry_id, body, cnt FROM (
                SELECT n.entry_id, n.body,
                       COUNT(*) OVER (PARTITION BY n.entry_id) AS cnt,
                       ROW_NUMBER() OVER (PARTITION BY n.entry_id ORDER BY n.pinned DESC, n.created_at DESC, n.id DESC) AS rn
                  FROM journal_entry_notes n
                 WHERE n.supplier_id = ? AND n.deleted_at IS NULL AND n.entry_id IN ({$in})
             ) x
             WHERE rn = 1"
        );
        $stmt->execute([$supplierId, ...$entryIds]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $body = trim((string) preg_replace('/\s+/u', ' ', (string) $row['body']));
            $out[(int) $row['entry_id']] = [
                'preview' => mb_strlen($body) > self::PREVIEW_LENGTH ? mb_substr($body, 0, self::PREVIEW_LENGTH) . '…' : $body,
                'count' => (int) $row['cnt'],
            ];
        }
        return $out;
    }

    /**
     * Počet dokumentů zápisu: jeho přílohy a dokumenty z úložiště navázané na zápis.
     *
     * @param list<int> $entryIds
     * @return array<int, int>
     */
    public function documentCounts(int $supplierId, array $entryIds): array
    {
        if ($entryIds === []) {
            return [];
        }
        $in = implode(',', array_fill(0, count($entryIds), '?'));
        $stmt = $this->db->pdo()->prepare(
            "SELECT entry_id, SUM(cnt) FROM (
                SELECT a.entry_id, COUNT(*) AS cnt
                  FROM journal_entry_attachments a
                 WHERE a.supplier_id = ? AND a.entry_id IN ({$in})
                 GROUP BY a.entry_id
                UNION ALL
                SELECT dl.entity_id, COUNT(*)
                  FROM document_links dl
                 WHERE dl.supplier_id = ? AND dl.entity_type = 'journal_entry' AND dl.entity_id IN ({$in})
                 GROUP BY dl.entity_id
             ) t
             GROUP BY entry_id"
        );
        $stmt->execute([$supplierId, ...$entryIds, $supplierId, ...$entryIds]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_KEY_PAIR));
    }
}
