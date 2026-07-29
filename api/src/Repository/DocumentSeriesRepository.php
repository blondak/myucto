<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Repository pro accounting_document_series — číselné řady deníku (Epic F4,
 * R13). Řádek řady vzniká lazy při prvním výdeji čísla (ensure); výdej drží
 * zámek SELECT ... FOR UPDATE v transakci volajícího (DocumentSeriesService).
 * UNIQUE (supplier_id, series_code, fiscal_year).
 */
final class DocumentSeriesRepository
{
    public function __construct(private readonly Connection $db) {}

    /**
     * Lazy založení řádku řady s výchozím prefixem — existující řádek se
     * NEMĚNÍ (INSERT ... ON DUPLICATE KEY UPDATE id=id).
     */
    public function ensure(int $supplierId, string $seriesCode, int $fiscalYear, string $defaultPrefix): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO accounting_document_series (supplier_id, series_code, fiscal_year, prefix, next_number)
             VALUES (?, ?, ?, ?, 1)
             ON DUPLICATE KEY UPDATE id = id'
        )->execute([$supplierId, $seriesCode, $fiscalYear, $defaultPrefix]);
    }

    /**
     * Zamkne řádek řady pro výdej čísla — MUSÍ běžet uvnitř transakce
     * (FOR UPDATE mimo transakci zámek okamžitě pouští).
     *
     * @return array{id:int, prefix:string, next_number:int}|null
     */
    public function lockRow(int $supplierId, string $seriesCode, int $fiscalYear): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, prefix, next_number
               FROM accounting_document_series
              WHERE supplier_id = ? AND series_code = ? AND fiscal_year = ?
              FOR UPDATE'
        );
        $stmt->execute([$supplierId, $seriesCode, $fiscalYear]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        return [
            'id'          => (int) $row['id'],
            'prefix'      => (string) $row['prefix'],
            'next_number' => (int) $row['next_number'],
        ];
    }

    /**
     * Posune čítač po výdeji čísla (volá se nad zamčeným řádkem).
     */
    public function bumpNextNumber(int $id): void
    {
        $this->db->pdo()->prepare(
            'UPDATE accounting_document_series SET next_number = next_number + 1 WHERE id = ?'
        )->execute([$id]);
    }

    /**
     * Řady firmy (všechny roky) pro správu prefixů.
     *
     * @return list<array<string,mixed>>
     */
    public function list(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, supplier_id, series_code, fiscal_year, prefix, next_number, created_at, updated_at
               FROM accounting_document_series
              WHERE supplier_id = ?
              ORDER BY fiscal_year DESC, series_code'
        );
        $stmt->execute([$supplierId]);
        return array_map(static function (array $r): array {
            $r['id'] = (int) $r['id'];
            $r['supplier_id'] = (int) $r['supplier_id'];
            $r['fiscal_year'] = (int) $r['fiscal_year'];
            $r['next_number'] = (int) $r['next_number'];
            return $r;
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Nastaví prefix řady; řádek případně lazy založí (edit prefixu smí
     * předcházet prvnímu výdeji čísla). Čítač se NEMĚNÍ.
     */
    public function upsertPrefix(int $supplierId, string $seriesCode, int $fiscalYear, string $prefix): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO accounting_document_series (supplier_id, series_code, fiscal_year, prefix, next_number)
             VALUES (?, ?, ?, ?, 1)
             ON DUPLICATE KEY UPDATE prefix = VALUES(prefix)'
        );
        $stmt->execute([$supplierId, $seriesCode, $fiscalYear, $prefix]);
        return $stmt->rowCount() > 0;
    }
}
