<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Číselník balení firmy (migrace 1832, issue #17) — kódy nadřazených jednotek
 * (KT = karton, PAL = paleta). Samotný převod na základní jednotku karty drží
 * `stock_item_units`; `usage_count` = počet karet, které kód balení používají
 * (porovnání kódů je case-insensitive díky collation `utf8mb4_unicode_ci`).
 */
final class StockPackagingUnitRepository
{
    private const SELECT =
        'SELECT p.id, p.supplier_id, p.code, p.name, p.is_active, p.display_order,
                (SELECT COUNT(*) FROM stock_item_units u
                  WHERE u.supplier_id = p.supplier_id AND u.is_sales_unit = 1 AND u.unit_code = p.code) AS usage_count
           FROM stock_packaging_units p';

    public function __construct(private readonly Connection $db) {}

    /** @return list<array<string,mixed>> */
    public function listForSupplier(int $supplierId, bool $activeOnly = false): array
    {
        $stmt = $this->db->pdo()->prepare(
            self::SELECT . ' WHERE p.supplier_id = ?' . ($activeOnly ? ' AND p.is_active = 1' : '')
            . ' ORDER BY p.display_order ASC, p.code ASC, p.id ASC'
        );
        $stmt->execute([$supplierId]);
        return array_map([self::class, 'cast'], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    public function find(int $supplierId, int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare(self::SELECT . ' WHERE p.supplier_id = ? AND p.id = ?');
        $stmt->execute([$supplierId, $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : self::cast($row);
    }

    public function findByCode(int $supplierId, string $code): ?array
    {
        $stmt = $this->db->pdo()->prepare(self::SELECT . ' WHERE p.supplier_id = ? AND p.code = ?');
        $stmt->execute([$supplierId, $code]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : self::cast($row);
    }

    /**
     * Kódy balení firmy podle kódu malými písmeny.
     *
     * @return array<string, array<string,mixed>>
     */
    public function byLowerCode(int $supplierId): array
    {
        $out = [];
        foreach ($this->listForSupplier($supplierId) as $row) {
            $out[mb_strtolower((string) $row['code'])] = $row;
        }
        return $out;
    }

    /** @param array{code:string, name:string, is_active:bool, display_order:int} $data */
    public function insert(int $supplierId, array $data): int
    {
        $this->db->pdo()->prepare(
            'INSERT INTO stock_packaging_units (supplier_id, code, name, is_active, display_order) VALUES (?, ?, ?, ?, ?)'
        )->execute([$supplierId, $data['code'], $data['name'], (int) $data['is_active'], (int) $data['display_order']]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    /** @param array{code:string, name:string, is_active:bool, display_order:int} $data */
    public function update(int $supplierId, int $id, array $data): void
    {
        $this->db->pdo()->prepare(
            'UPDATE stock_packaging_units SET code = ?, name = ?, is_active = ?, display_order = ?
              WHERE supplier_id = ? AND id = ?'
        )->execute([$data['code'], $data['name'], (int) $data['is_active'], (int) $data['display_order'], $supplierId, $id]);
    }

    public function delete(int $supplierId, int $id): void
    {
        $this->db->pdo()->prepare('DELETE FROM stock_packaging_units WHERE supplier_id = ? AND id = ?')
            ->execute([$supplierId, $id]);
    }

    /** @return array<string,mixed> */
    private static function cast(array $r): array
    {
        return [
            'id'            => (int) $r['id'],
            'code'          => (string) $r['code'],
            'name'          => (string) $r['name'],
            'is_active'     => (bool) $r['is_active'],
            'display_order' => (int) $r['display_order'],
            'usage_count'   => (int) $r['usage_count'],
        ];
    }
}
