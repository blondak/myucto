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

        $pdo->prepare(
            "INSERT IGNORE INTO supplier_vat_status_history (supplier_id, effective_from, is_vat_payer)
             SELECT ?, '1900-01-01', is_vat_payer FROM supplier WHERE id = ?"
        )->execute([$supplierId, $supplierId]);

        return $supplierId;
    }

    /**
     * Nastaví plátcovství DPH izolovaného supplieru k datu — zapíše řádek historie
     * a synchronizuje živou cache supplier.is_vat_payer, pokud je účinnost <= dnes.
     * Testy NESMÍ přepínat plátcovství holým UPDATE supplier: reporty čtou historii.
     */
    protected function setVatPayerAt(PDO $pdo, int $supplierId, string $effectiveFrom, bool $isVatPayer): void
    {
        $pdo->prepare(
            'INSERT INTO supplier_vat_status_history (supplier_id, effective_from, is_vat_payer)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE is_vat_payer = VALUES(is_vat_payer)'
        )->execute([$supplierId, $effectiveFrom, $isVatPayer ? 1 : 0]);

        $pdo->prepare(
            'UPDATE supplier s
                SET s.is_vat_payer = (
                    SELECT h.is_vat_payer FROM supplier_vat_status_history h
                     WHERE h.supplier_id = s.id AND h.effective_from <= CURRENT_DATE
                     ORDER BY h.effective_from DESC, h.id DESC LIMIT 1
                )
              WHERE s.id = ?
                AND EXISTS (
                    SELECT 1 FROM supplier_vat_status_history h2
                     WHERE h2.supplier_id = s.id AND h2.effective_from <= CURRENT_DATE
                )'
        )->execute([$supplierId]);
    }
}
