<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Repository pro stock_landed_costs — vedlejší pořizovací náklady příjemky
 * (Epic SKLAD, §49/1 vyhl. 500/2002 — doprava, clo, provize). Per tenant
 * (supplier_id); document_id = příjemka (stock_documents.doc_type=receipt),
 * v1 jen ve stavu draft (rozhodnutí A8).
 */
final class StockLandedCostRepository
{
    private const COLUMNS =
        'id, supplier_id, document_id, purchase_invoice_id, purchase_invoice_item_id,
         description, amount, allocation, created_at';

    public function __construct(private readonly Connection $db) {}

    public function find(int $supplierId, int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM stock_landed_costs WHERE supplier_id = ? AND id = ?'
        );
        $stmt->execute([$supplierId, $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : self::cast($row);
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listForDocument(int $supplierId, int $documentId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM stock_landed_costs
              WHERE supplier_id = ? AND document_id = ?
              ORDER BY id ASC'
        );
        $stmt->execute([$supplierId, $documentId]);
        return array_map([self::class, 'cast'], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /**
     * @param array{document_id:int, purchase_invoice_id?:?int, purchase_invoice_item_id?:?int,
     *              description:string, amount:string, allocation?:string} $data
     */
    public function insert(int $supplierId, array $data): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO stock_landed_costs
                (supplier_id, document_id, purchase_invoice_id, purchase_invoice_item_id,
                 description, amount, allocation)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $supplierId,
            (int) $data['document_id'],
            isset($data['purchase_invoice_id']) ? (int) $data['purchase_invoice_id'] : null,
            isset($data['purchase_invoice_item_id']) ? (int) $data['purchase_invoice_item_id'] : null,
            (string) $data['description'],
            (string) $data['amount'],
            (string) ($data['allocation'] ?? 'by_value'),
        ]);
        return (int) $pdo->lastInsertId();
    }

    public function delete(int $supplierId, int $id): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'DELETE FROM stock_landed_costs WHERE id = ? AND supplier_id = ?'
        );
        $stmt->execute([$id, $supplierId]);
        return $stmt->rowCount() > 0;
    }

    public function deleteForDocument(int $supplierId, int $documentId): void
    {
        $this->db->pdo()
            ->prepare('DELETE FROM stock_landed_costs WHERE supplier_id = ? AND document_id = ?')
            ->execute([$supplierId, $documentId]);
    }

    /** @return array<string,mixed> */
    private static function cast(array $r): array
    {
        $r['id'] = (int) $r['id'];
        $r['supplier_id'] = (int) $r['supplier_id'];
        $r['document_id'] = (int) $r['document_id'];
        $r['purchase_invoice_id'] = $r['purchase_invoice_id'] !== null ? (int) $r['purchase_invoice_id'] : null;
        $r['purchase_invoice_item_id'] = $r['purchase_invoice_item_id'] !== null ? (int) $r['purchase_invoice_item_id'] : null;
        return $r;
    }
}
