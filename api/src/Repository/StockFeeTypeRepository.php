<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Repository pro stock_fee_types — číselník poplatků (autorský/recyklační/WEEE)
 * (Epic ESHOP). Per tenant (supplier_id); UNIQUE (supplier_id, code).
 * vat_rate_id určuje DPH režim poplatku (FK vat_rates).
 */
final class StockFeeTypeRepository
{
    private const COLUMNS = 'id, supplier_id, code, name, vat_rate_id, archived';

    public function __construct(private readonly Connection $db) {}

    public function find(int $supplierId, int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM stock_fee_types WHERE supplier_id = ? AND id = ?'
        );
        $stmt->execute([$supplierId, $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : self::cast($row);
    }

    public function findByCode(int $supplierId, string $code): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM stock_fee_types WHERE supplier_id = ? AND code = ?'
        );
        $stmt->execute([$supplierId, $code]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : self::cast($row);
    }

    /** @return list<array<string,mixed>> */
    public function listForSupplier(int $supplierId, bool $activeOnly = false): array
    {
        $sql = 'SELECT ' . self::COLUMNS . ' FROM stock_fee_types WHERE supplier_id = ?';
        if ($activeOnly) {
            $sql .= ' AND archived = 0';
        }
        $sql .= ' ORDER BY name ASC';
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([$supplierId]);
        return array_map([self::class, 'cast'], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /**
     * @param array{code:string, name:string, vat_rate_id?:?int, archived?:bool} $data
     */
    public function insert(int $supplierId, array $data): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO stock_fee_types (supplier_id, code, name, vat_rate_id, archived)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([
            $supplierId,
            (string) $data['code'],
            (string) $data['name'],
            isset($data['vat_rate_id']) ? (int) $data['vat_rate_id'] : null,
            (int) ($data['archived'] ?? false),
        ]);
        return (int) $pdo->lastInsertId();
    }

    /**
     * @param array{code:string, name:string, vat_rate_id?:?int, archived?:bool} $data
     */
    public function update(int $supplierId, int $id, array $data): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE stock_fee_types SET code = ?, name = ?, vat_rate_id = ?, archived = ?
              WHERE id = ? AND supplier_id = ?'
        );
        $stmt->execute([
            (string) $data['code'],
            (string) $data['name'],
            isset($data['vat_rate_id']) ? (int) $data['vat_rate_id'] : null,
            (int) ($data['archived'] ?? false),
            $id,
            $supplierId,
        ]);
        return $stmt->rowCount() > 0;
    }

    /** Používá poplatek aspoň jedna karta? (blokuje hard delete) */
    public function isReferenced(int $supplierId, int $id): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT EXISTS (
                SELECT 1 FROM stock_item_fees WHERE supplier_id = ? AND fee_type_id = ?
             )'
        );
        $stmt->execute([$supplierId, $id]);
        return (bool) $stmt->fetchColumn();
    }

    public function delete(int $supplierId, int $id): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'DELETE FROM stock_fee_types WHERE id = ? AND supplier_id = ?'
        );
        $stmt->execute([$id, $supplierId]);
        return $stmt->rowCount() > 0;
    }

    /** @return array<string,mixed> */
    private static function cast(array $r): array
    {
        $r['id'] = (int) $r['id'];
        $r['supplier_id'] = (int) $r['supplier_id'];
        $r['vat_rate_id'] = $r['vat_rate_id'] !== null ? (int) $r['vat_rate_id'] : null;
        $r['archived'] = (bool) $r['archived'];
        return $r;
    }
}
