<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Repository pro stock_categories — strom kategorií s materialized path
 * (Epic ESHOP). Per tenant (supplier_id). Path/depth udržuje CategoryTreeService
 * (tento repo poskytuje jen primitivy: CRUD + subtree dotaz bez rekurze).
 */
final class StockCategoryRepository
{
    private const COLUMNS =
        'id, supplier_id, parent_id, code, name, path, depth, display_order,
         export_eshop, archived, created_at, updated_at';

    public function __construct(private readonly Connection $db) {}

    public function find(int $supplierId, int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM stock_categories WHERE supplier_id = ? AND id = ?'
        );
        $stmt->execute([$supplierId, $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : self::cast($row);
    }

    public function findByCode(int $supplierId, string $code): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM stock_categories WHERE supplier_id = ? AND code = ?'
        );
        $stmt->execute([$supplierId, $code]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : self::cast($row);
    }

    /** Celý strom firmy seřazený pro renderování (parent před dětmi přes path). */
    /** @return list<array<string,mixed>> */
    public function listForSupplier(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM stock_categories
              WHERE supplier_id = ?
              ORDER BY path ASC, display_order ASC, name ASC'
        );
        $stmt->execute([$supplierId]);
        return array_map([self::class, 'cast'], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /**
     * Podstrom uzlu (vč. něj) přes materialized path — jeden indexovaný dotaz
     * bez rekurze. $pathPrefix = path uzlu (končí '/'), např. '/12/'.
     * @return list<array<string,mixed>>
     */
    public function subtree(int $supplierId, string $pathPrefix): array
    {
        // Escapování LIKE metaznaků v path (path obsahuje jen číslice a '/').
        $like = addcslashes($pathPrefix, '%_\\') . '%';
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM stock_categories
              WHERE supplier_id = ? AND path LIKE ?
              ORDER BY path ASC'
        );
        $stmt->execute([$supplierId, $like]);
        return array_map([self::class, 'cast'], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    public function hasChildren(int $supplierId, int $id): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT EXISTS (SELECT 1 FROM stock_categories WHERE supplier_id = ? AND parent_id = ?)'
        );
        $stmt->execute([$supplierId, $id]);
        return (bool) $stmt->fetchColumn();
    }

    /** Přiřazená ke zboží? (blokuje hard delete) */
    public function isReferenced(int $supplierId, int $id): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT EXISTS (SELECT 1 FROM stock_item_categories WHERE supplier_id = ? AND category_id = ?)'
        );
        $stmt->execute([$supplierId, $id]);
        return (bool) $stmt->fetchColumn();
    }

    /**
     * @param array{parent_id:?int, code:string, name:string, path:string, depth:int,
     *              display_order?:int, export_eshop?:bool, archived?:bool} $data
     */
    public function insert(int $supplierId, array $data): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO stock_categories
                (supplier_id, parent_id, code, name, path, depth, display_order, export_eshop, archived)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $supplierId,
            $data['parent_id'] !== null ? (int) $data['parent_id'] : null,
            (string) $data['code'],
            (string) $data['name'],
            (string) $data['path'],
            (int) $data['depth'],
            (int) ($data['display_order'] ?? 0),
            (int) ($data['export_eshop'] ?? true),
            (int) ($data['archived'] ?? false),
        ]);
        return (int) $pdo->lastInsertId();
    }

    /** Úprava obsahových polí (ne parent/path/depth — to řeší move()). */
    /**
     * @param array{code:string, name:string, display_order?:int, export_eshop?:bool, archived?:bool} $data
     */
    public function updateFields(int $supplierId, int $id, array $data): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE stock_categories SET code = ?, name = ?, display_order = ?, export_eshop = ?, archived = ?
              WHERE id = ? AND supplier_id = ?'
        );
        $stmt->execute([
            (string) $data['code'],
            (string) $data['name'],
            (int) ($data['display_order'] ?? 0),
            (int) ($data['export_eshop'] ?? true),
            (int) ($data['archived'] ?? false),
            $id,
            $supplierId,
        ]);
        return $stmt->rowCount() > 0;
    }

    /** Nastaví jen parent_id uzlu (path/depth řeší repathSubtree). */
    public function updateParent(int $supplierId, int $id, ?int $parentId): void
    {
        $this->db->pdo()->prepare(
            'UPDATE stock_categories SET parent_id = ? WHERE id = ? AND supplier_id = ?'
        )->execute([$parentId !== null ? $parentId : null, $id, $supplierId]);
    }

    /** Nastaví finální path+depth uzlu (po insertu, kdy už známe id). */
    public function setPathDepth(int $supplierId, int $id, string $path, int $depth): void
    {
        $this->db->pdo()->prepare(
            'UPDATE stock_categories SET path = ?, depth = ? WHERE id = ? AND supplier_id = ?'
        )->execute([$path, $depth, $id, $supplierId]);
    }

    /**
     * Repath celého podstromu (VČETNĚ přesouvaného uzlu) při move: nahradí
     * prefix staré path novou a posune depth o $depthDelta. Jeden indexovaný
     * dotaz, tenant-scoped. path obsahuje jen číslice a '/', LIKE prefix je bezpečný.
     */
    public function repathSubtree(int $supplierId, string $oldPrefix, string $newPrefix, int $depthDelta): void
    {
        $like = addcslashes($oldPrefix, '%_\\') . '%';
        $this->db->pdo()->prepare(
            'UPDATE stock_categories
                SET path = CONCAT(?, SUBSTRING(path, CHAR_LENGTH(?) + 1)),
                    depth = depth + ?
              WHERE supplier_id = ? AND path LIKE ?'
        )->execute([
            $newPrefix,
            $oldPrefix,
            $depthDelta,
            $supplierId,
            $like,
        ]);
    }

    public function delete(int $supplierId, int $id): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'DELETE FROM stock_categories WHERE id = ? AND supplier_id = ?'
        );
        $stmt->execute([$id, $supplierId]);
        return $stmt->rowCount() > 0;
    }

    /** @return array<string,mixed> */
    private static function cast(array $r): array
    {
        $r['id'] = (int) $r['id'];
        $r['supplier_id'] = (int) $r['supplier_id'];
        $r['parent_id'] = $r['parent_id'] !== null ? (int) $r['parent_id'] : null;
        $r['depth'] = (int) $r['depth'];
        $r['display_order'] = (int) $r['display_order'];
        $r['export_eshop'] = (bool) $r['export_eshop'];
        $r['archived'] = (bool) $r['archived'];
        return $r;
    }
}
