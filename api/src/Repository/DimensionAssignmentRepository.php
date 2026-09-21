<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Přiřazení hodnot dimenzí dokladům (`document_dimensions`) a řádkům deníku
 * (`journal_entry_line_dimensions`), migrace 1860. Dimenze se ukládají jako mapa
 * typ => hodnota; jedna hodnota za typ vynucuje primární klíč.
 *
 * Validaci (hodnota viditelná firmě, patří k typu) dělá volající přes
 * {@see \MyInvoice\Service\Accounting\Dimension\DimensionService::normalize()} —
 * tady se jen zapisuje.
 */
final class DimensionAssignmentRepository
{
    public const DOC_TYPES = ['purchase_invoice', 'invoice', 'cash_document', 'bank_transaction', 'journal_template'];

    public function __construct(private readonly Connection $db) {}

    /**
     * @return array{header:array<int,int>, items:array<int,array<int,int>>}
     */
    public function documentDimensions(int $supplierId, string $docType, int $docId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT item_no, dimension_type_id, dimension_value_id FROM document_dimensions
              WHERE supplier_id = ? AND doc_type = ? AND doc_id = ?
              ORDER BY item_no, dimension_type_id'
        );
        $stmt->execute([$supplierId, $docType, $docId]);
        $out = ['header' => [], 'items' => []];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $itemNo = (int) $r['item_no'];
            if ($itemNo === 0) {
                $out['header'][(int) $r['dimension_type_id']] = (int) $r['dimension_value_id'];
            } else {
                $out['items'][$itemNo][(int) $r['dimension_type_id']] = (int) $r['dimension_value_id'];
            }
        }
        return $out;
    }

    /**
     * @param array<int,int> $header typ => hodnota
     * @param array<int,array<int,int>> $items pořadí položky (od 1) => typ => hodnota
     */
    public function replaceDocumentDimensions(int $supplierId, string $docType, int $docId, array $header, array $items): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare('DELETE FROM document_dimensions WHERE supplier_id = ? AND doc_type = ? AND doc_id = ?')
            ->execute([$supplierId, $docType, $docId]);
        $rows = [];
        foreach ($header as $typeId => $valueId) {
            $rows[] = [$supplierId, $docType, $docId, 0, (int) $typeId, (int) $valueId];
        }
        foreach ($items as $itemNo => $dims) {
            if ((int) $itemNo <= 0) {
                continue;
            }
            foreach ($dims as $typeId => $valueId) {
                $rows[] = [$supplierId, $docType, $docId, (int) $itemNo, (int) $typeId, (int) $valueId];
            }
        }
        $this->insertDocumentRows($rows);
    }

    /**
     * Doplní hodnotu do hlavičky dokladu jen tam, kde pro typ ještě žádná není —
     * ruční volba uživatele má přednost (převody z jiných systémů).
     */
    public function addHeaderDimensionIfMissing(int $supplierId, string $docType, int $docId, int $typeId, int $valueId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT IGNORE INTO document_dimensions (supplier_id, doc_type, doc_id, item_no, dimension_type_id, dimension_value_id)
             VALUES (?, ?, ?, 0, ?, ?)'
        );
        $stmt->execute([$supplierId, $docType, $docId, $typeId, $valueId]);
        return $stmt->rowCount() > 0;
    }

    public function deleteDocument(int $supplierId, string $docType, int $docId): void
    {
        $this->db->pdo()->prepare('DELETE FROM document_dimensions WHERE supplier_id = ? AND doc_type = ? AND doc_id = ?')
            ->execute([$supplierId, $docType, $docId]);
    }

    /**
     * Hlavičkové dimenze dokladů pro seznam, jen je-li sekce u firmy zapnutá.
     * Null = dimenze vypnuté, seznam je vůbec neposílá.
     *
     * @param list<int> $docIds
     * @return array<int,array<int,int>>|null
     */
    public function headerDimensionsIfEnabled(int $supplierId, string $docType, array $docIds): ?array
    {
        if ($docIds === [] || !(new DimensionRepository($this->db))->enabled($supplierId)) {
            return null;
        }
        return $this->headerDimensionsFor($supplierId, $docType, $docIds);
    }

    /**
     * Hlavičkové dimenze více dokladů najednou (seznamy, přehledy).
     *
     * @param list<int> $docIds
     * @return array<int,array<int,int>> doklad => typ => hodnota
     */
    public function headerDimensionsFor(int $supplierId, string $docType, array $docIds): array
    {
        $docIds = array_values(array_unique(array_map('intval', $docIds)));
        if ($docIds === []) {
            return [];
        }
        $marks = implode(',', array_fill(0, count($docIds), '?'));
        $stmt = $this->db->pdo()->prepare(
            "SELECT doc_id, dimension_type_id, dimension_value_id FROM document_dimensions
              WHERE supplier_id = ? AND doc_type = ? AND item_no = 0 AND doc_id IN ({$marks})"
        );
        $stmt->execute([$supplierId, $docType, ...$docIds]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int) $r['doc_id']][(int) $r['dimension_type_id']] = (int) $r['dimension_value_id'];
        }
        return $out;
    }

    /**
     * @param list<int> $lineIds
     * @return array<int,array<int,int>> řádek => typ => hodnota
     */
    public function lineDimensions(int $supplierId, array $lineIds): array
    {
        $lineIds = array_values(array_unique(array_map('intval', $lineIds)));
        if ($lineIds === []) {
            return [];
        }
        $out = [];
        foreach (array_chunk($lineIds, 1000) as $chunk) {
            $marks = implode(',', array_fill(0, count($chunk), '?'));
            $stmt = $this->db->pdo()->prepare(
                "SELECT line_id, dimension_type_id, dimension_value_id FROM journal_entry_line_dimensions FORCE INDEX (PRIMARY)
                  WHERE supplier_id = ? AND line_id IN ({$marks})"
            );
            $stmt->execute([$supplierId, ...$chunk]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $out[(int) $r['line_id']][(int) $r['dimension_type_id']] = (int) $r['dimension_value_id'];
            }
        }
        return $out;
    }

    /** @return array<int,array<int,int>> řádek => typ => hodnota */
    public function entryLineDimensions(int $supplierId, int $entryId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT d.line_id, d.dimension_type_id, d.dimension_value_id
               FROM journal_entry_lines l
               STRAIGHT_JOIN journal_entry_line_dimensions d ON d.line_id = l.id
              WHERE l.supplier_id = ? AND l.entry_id = ?'
        );
        $stmt->execute([$supplierId, $entryId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int) $r['line_id']][(int) $r['dimension_type_id']] = (int) $r['dimension_value_id'];
        }
        return $out;
    }

    /**
     * Přepíše dimenze řádku deníku. Mění jen analytické členění — účet, strana,
     * částka ani období řádku se nemění, proto smí i nad uzavřeným obdobím.
     *
     * @param array<int,int> $dims typ => hodnota
     * @return bool zda se něco změnilo
     */
    public function replaceLineDimensions(int $supplierId, int $lineId, array $dims): bool
    {
        $current = $this->lineDimensions($supplierId, [$lineId])[$lineId] ?? [];
        ksort($current);
        ksort($dims);
        if ($current === array_map('intval', $dims)) {
            return false;
        }
        $pdo = $this->db->pdo();
        $pdo->prepare('DELETE FROM journal_entry_line_dimensions WHERE supplier_id = ? AND line_id = ?')
            ->execute([$supplierId, $lineId]);
        $this->insertLineDimensions($supplierId, $lineId, $dims);
        return true;
    }

    /** @param array<int,int> $dims typ => hodnota */
    public function insertLineDimensions(int $supplierId, int $lineId, array $dims): void
    {
        if ($dims === []) {
            return;
        }
        $rows = [];
        $params = [];
        foreach ($dims as $typeId => $valueId) {
            $rows[] = '(?, ?, ?, ?)';
            array_push($params, $lineId, (int) $typeId, $supplierId, (int) $valueId);
        }
        $this->db->pdo()->prepare(
            'INSERT INTO journal_entry_line_dimensions (line_id, dimension_type_id, supplier_id, dimension_value_id) VALUES '
            . implode(',', $rows)
        )->execute($params);
    }

    /** @param list<array{0:int,1:string,2:int,3:int,4:int,5:int}> $rows */
    private function insertDocumentRows(array $rows): void
    {
        foreach (array_chunk($rows, 500) as $chunk) {
            $this->db->pdo()->prepare(
                'INSERT INTO document_dimensions (supplier_id, doc_type, doc_id, item_no, dimension_type_id, dimension_value_id) VALUES '
                . implode(',', array_fill(0, count($chunk), '(?, ?, ?, ?, ?, ?)'))
            )->execute(array_merge(...$chunk));
        }
    }
}
