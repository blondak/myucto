<?php

declare(strict_types=1);

namespace MyInvoice\Service\Stock;

use PDO;

/** Read-only SSOT kontroly materializovaných stavů proti skladové knize. */
final class StockLevelBalanceInspector
{
    public const REASON_LEDGER_MISMATCH = 'ledger_mismatch';
    public const REASON_AVERAGE_INVALID = 'average_invalid';

    private const MAX_DETAIL_LIMIT = 1_000;

    /**
     * @return array{count:int, sample:list<array{
     *   warehouse_id:int,stock_item_id:int,reason:string
     * }>}
     */
    public static function inspect(
        PDO $database,
        int $supplierId,
        int $detailLimit = 100,
    ): array {
        if ($supplierId < 1
            || $detailLimit < 1
            || $detailLimit > self::MAX_DETAIL_LIMIT
        ) {
            throw new \InvalidArgumentException(
                'Kontext kontroly skladových stavů není platný.',
            );
        }

        // Skladové sestavy přehrávají řádky chronologicky kvůli historickému
        // ocenění. Tady se záměrně sčítají již uložené haléřové hodnoty: kontrola
        // porovnává aktuální materializaci se všemi třemi fyzickými nohami knihy
        // a musí současně zachytit i pohyb, pro který stock_levels řádek chybí.
        $statement = $database->prepare(<<<'SQL'
WITH movement_legs AS (
    SELECT d.warehouse_id AS warehouse_id,
           l.stock_item_id AS stock_item_id,
           CASE WHEN d.doc_type = 'receipt' THEN l.qty ELSE -l.qty END AS qty_delta,
           CASE WHEN d.doc_type = 'receipt' THEN l.value_total ELSE -l.value_total END AS value_delta
      FROM stock_document_lines l
      JOIN stock_documents d
        ON d.id = l.document_id
       AND d.supplier_id = l.supplier_id
     WHERE l.supplier_id = ?
       AND d.status IN ('posted', 'reversed')
       AND d.doc_type IN ('receipt', 'issue')
    UNION ALL
    SELECT d.warehouse_id AS warehouse_id,
           l.stock_item_id AS stock_item_id,
           -l.qty AS qty_delta,
           -l.value_total AS value_delta
      FROM stock_document_lines l
      JOIN stock_documents d
        ON d.id = l.document_id
       AND d.supplier_id = l.supplier_id
     WHERE l.supplier_id = ?
       AND d.status IN ('posted', 'reversed')
       AND d.doc_type = 'transfer'
    UNION ALL
    SELECT d.warehouse_to_id AS warehouse_id,
           l.stock_item_id AS stock_item_id,
           l.qty AS qty_delta,
           l.value_total AS value_delta
      FROM stock_document_lines l
      JOIN stock_documents d
        ON d.id = l.document_id
       AND d.supplier_id = l.supplier_id
     WHERE l.supplier_id = ?
       AND d.status IN ('posted', 'reversed')
       AND d.doc_type = 'transfer'
       AND d.warehouse_to_id IS NOT NULL
), movement_totals AS (
    SELECT warehouse_id,
           stock_item_id,
           SUM(qty_delta) AS movement_qty,
           SUM(value_delta) AS movement_value
      FROM movement_legs
     GROUP BY warehouse_id, stock_item_id
), comparison_keys AS (
    SELECT warehouse_id, stock_item_id
      FROM stock_levels
     WHERE supplier_id = ?
    UNION
    SELECT warehouse_id, stock_item_id
      FROM movement_totals
)
SELECT keys_to_check.warehouse_id,
       keys_to_check.stock_item_id,
       levels.qty AS level_qty,
       levels.value_total AS level_value,
       levels.avg_unit_cost AS level_average,
       movements.movement_qty,
       movements.movement_value
  FROM comparison_keys keys_to_check
  LEFT JOIN stock_levels levels
    ON levels.supplier_id = ?
   AND levels.warehouse_id = keys_to_check.warehouse_id
   AND levels.stock_item_id = keys_to_check.stock_item_id
  LEFT JOIN movement_totals movements
    ON movements.warehouse_id = keys_to_check.warehouse_id
   AND movements.stock_item_id = keys_to_check.stock_item_id
 ORDER BY keys_to_check.warehouse_id, keys_to_check.stock_item_id
SQL);
        $statement->execute([
            $supplierId,
            $supplierId,
            $supplierId,
            $supplierId,
            $supplierId,
        ]);

        $count = 0;
        $sample = [];
        while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
            $reason = self::reason($row);
            if ($reason === null) {
                continue;
            }
            $count++;
            if (count($sample) < $detailLimit) {
                $sample[] = [
                    'warehouse_id' => (int) $row['warehouse_id'],
                    'stock_item_id' => (int) $row['stock_item_id'],
                    'reason' => $reason,
                ];
            }
        }

        return ['count' => $count, 'sample' => $sample];
    }

    /** @param array<string,mixed> $row */
    private static function reason(array $row): ?string
    {
        $hasLevel = $row['level_qty'] !== null;
        $levelQtyT = $hasLevel
            ? StockValuation::qtyToT((string) $row['level_qty'])
            : 0;
        $levelValueC = $hasLevel
            ? StockValuation::valueToC((string) $row['level_value'])
            : 0;
        $movementQtyT = $row['movement_qty'] === null
            ? 0
            : StockValuation::qtyToT((string) $row['movement_qty']);
        $movementValueC = $row['movement_value'] === null
            ? 0
            : StockValuation::valueToC((string) $row['movement_value']);

        if ($levelQtyT !== $movementQtyT
            || $levelValueC !== $movementValueC
            || $levelQtyT < 0
            || $levelValueC < 0
            || ($levelQtyT === 0 && $levelValueC !== 0)
        ) {
            return self::REASON_LEDGER_MISMATCH;
        }
        if (!$hasLevel) {
            return null;
        }

        $storedAverage = StockValuation::unitCostToMicro(
            (string) $row['level_average'],
        );
        $expectedAverage = StockValuation::avgUnitCostMicro(
            $levelQtyT,
            $levelValueC,
        );
        return $storedAverage === $expectedAverage
            ? null
            : self::REASON_AVERAGE_INVALID;
    }
}
