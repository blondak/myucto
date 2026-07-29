<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Repository pro manufacturers — číselník výrobců (Epic ESHOP).
 * Per tenant (supplier_id); UNIQUE (supplier_id, code). logo_media_id je
 * odložený FK na stock_media (ON DELETE SET NULL).
 */
final class ManufacturerRepository
{
    private const COLUMNS =
        'id, supplier_id, code, name, website, logo_media_id, display_order,
         export_eshop, archived, created_at, updated_at';

    public function __construct(private readonly Connection $db) {}

    public function find(int $supplierId, int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM manufacturers WHERE supplier_id = ? AND id = ?'
        );
        $stmt->execute([$supplierId, $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : self::cast($row);
    }

    public function findByCode(int $supplierId, string $code): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM manufacturers WHERE supplier_id = ? AND code = ?'
        );
        $stmt->execute([$supplierId, $code]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : self::cast($row);
    }

    /** @return list<array<string,mixed>> */
    public function listForSupplier(int $supplierId, bool $activeOnly = false): array
    {
        $sql = 'SELECT ' . self::COLUMNS . ' FROM manufacturers WHERE supplier_id = ?';
        if ($activeOnly) {
            $sql .= ' AND archived = 0';
        }
        $sql .= ' ORDER BY display_order ASC, name ASC';
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([$supplierId]);
        return array_map([self::class, 'cast'], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /**
     * @param array{code:string, name:string, website?:?string, display_order?:int,
     *              export_eshop?:bool, archived?:bool} $data
     */
    public function insert(int $supplierId, array $data): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO manufacturers
                (supplier_id, code, name, website, display_order, export_eshop, archived)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $supplierId,
            (string) $data['code'],
            (string) $data['name'],
            $data['website'] ?? null,
            (int) ($data['display_order'] ?? 0),
            (int) ($data['export_eshop'] ?? true),
            (int) ($data['archived'] ?? false),
        ]);
        return (int) $pdo->lastInsertId();
    }

    /**
     * @param array{code:string, name:string, website?:?string, display_order?:int,
     *              export_eshop?:bool, archived?:bool} $data
     */
    public function update(int $supplierId, int $id, array $data): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE manufacturers SET
                code = ?, name = ?, website = ?, display_order = ?, export_eshop = ?, archived = ?
              WHERE id = ? AND supplier_id = ?'
        );
        $stmt->execute([
            (string) $data['code'],
            (string) $data['name'],
            $data['website'] ?? null,
            (int) ($data['display_order'] ?? 0),
            (int) ($data['export_eshop'] ?? true),
            (int) ($data['archived'] ?? false),
            $id,
            $supplierId,
        ]);
        return $stmt->rowCount() > 0;
    }

    /** Používá aspoň jedna karta tohoto výrobce? (blokuje hard delete) */
    public function isReferenced(int $supplierId, int $id): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT EXISTS (
                SELECT 1 FROM stock_items WHERE supplier_id = ? AND manufacturer_id = ?
             )'
        );
        $stmt->execute([$supplierId, $id]);
        return (bool) $stmt->fetchColumn();
    }

    public function delete(int $supplierId, int $id): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'DELETE FROM manufacturers WHERE id = ? AND supplier_id = ?'
        );
        $stmt->execute([$id, $supplierId]);
        return $stmt->rowCount() > 0;
    }

    /** @return array<string,mixed> */
    private static function cast(array $r): array
    {
        $r['id'] = (int) $r['id'];
        $r['supplier_id'] = (int) $r['supplier_id'];
        $r['logo_media_id'] = $r['logo_media_id'] !== null ? (int) $r['logo_media_id'] : null;
        $r['display_order'] = (int) $r['display_order'];
        $r['export_eshop'] = (bool) $r['export_eshop'];
        $r['archived'] = (bool) $r['archived'];
        return $r;
    }
}
