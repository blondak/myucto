<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Support;

use PDO;
use RuntimeException;

trait IsolatedSupplierTrait
{
    protected function createIsolatedSupplier(PDO $pdo, int $sourceSupplierId): int
    {
        $usedIds = array_fill_keys(array_map(
            'intval',
            $pdo->query('SELECT id FROM supplier WHERE id BETWEEN 1 AND 255')->fetchAll(PDO::FETCH_COLUMN)
        ), true);

        $supplierId = 0;
        for ($candidate = 1; $candidate <= 255; ++$candidate) {
            if (!isset($usedIds[$candidate])) {
                $supplierId = $candidate;
                break;
            }
        }
        if ($supplierId === 0) {
            throw new RuntimeException('Pro izolovaný test není volné žádné TINYINT supplier ID.');
        }

        // Schéma se v rámci jednoho běhu nemění, ale tenhle dotaz stojí ~2,5 ms a volá se
        // při KAŽDÉM založení izolovaného dodavatele (336 testů) — zbytečně ~0,8 s na sadu.
        // Cache je statická, tedy per proces; migrace testovací DB proběhnou v bootstrapu
        // dřív, než se sem kdokoli dostane.
        static $columns = null;
        $columns ??= $pdo->query(
            "SELECT COLUMN_NAME FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'supplier'
                AND COLUMN_NAME <> 'id' AND EXTRA NOT LIKE '%auto_increment%'
                AND (GENERATION_EXPRESSION IS NULL OR GENERATION_EXPRESSION = '')
              ORDER BY ORDINAL_POSITION"
        )->fetchAll(PDO::FETCH_COLUMN);
        $columnList = implode(', ', array_map(static fn (string $column): string => "`{$column}`", $columns));

        $stmt = $pdo->prepare(
            "INSERT INTO supplier (`id`, {$columnList})
             SELECT ?, {$columnList} FROM supplier WHERE id = ?"
        );
        $stmt->execute([$supplierId, $sourceSupplierId]);
        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException('Zdrojový supplier pro izolovaný test neexistuje.');
        }

        return $supplierId;
    }
}
