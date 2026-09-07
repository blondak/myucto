<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting;

use PDO;

/** Read-only SSOT kontroly vyváženosti hlaviček a řádků deníku. */
final class JournalEntryBalanceInspector
{
    /** Haléřová tolerance porovnání stran MD a D (0,5 haléře). */
    public const CENT_TOLERANCE = 0.005;

    private const MAX_DETAIL_LIMIT = 1_000;

    /**
     * @return array{count:int, sample:list<array<string,mixed>>}
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
                'Kontext kontroly vyváženosti deníku není platný.',
            );
        }

        // LEFT JOIN zachytí i prázdnou hlavičku, která by s INNER JOINem
        // zmizela z GROUP BY a neprávem byla považována za vyváženou.
        $base =
            "SELECT je.id AS entry_id, je.source_type, je.source_id, je.document_no,
                    COUNT(l.id) AS line_count,
                    ROUND(COALESCE(SUM(CASE WHEN l.side = 'debit'  THEN l.amount ELSE 0 END), 0), 2) AS debit,
                    ROUND(COALESCE(SUM(CASE WHEN l.side = 'credit' THEN l.amount ELSE 0 END), 0), 2) AS credit
               FROM journal_entries je
               LEFT JOIN journal_entry_lines l ON l.entry_id = je.id
              WHERE je.supplier_id = :sid
              GROUP BY je.id
             HAVING line_count = 0 OR ABS(debit - credit) > " . self::CENT_TOLERANCE;

        $statement = $database->prepare(
            $base . ' ORDER BY entry_id LIMIT ' . $detailLimit,
        );
        $statement->execute(['sid' => $supplierId]);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $countStatement = $database->prepare(
            'SELECT COUNT(*) FROM (' . $base . ') unbalanced_entries',
        );
        $countStatement->execute(['sid' => $supplierId]);

        return [
            'count' => (int) $countStatement->fetchColumn(),
            'sample' => self::normalizeRows(array_values($rows)),
        ];
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    private static function normalizeRows(array $rows): array
    {
        return array_map(static function (array $row): array {
            foreach (['entry_id', 'source_id'] as $column) {
                if ($row[$column] !== null) {
                    $row[$column] = (int) $row[$column];
                }
            }
            $row['debit'] = (float) $row['debit'];
            $row['credit'] = (float) $row['credit'];
            return $row;
        }, $rows);
    }
}
