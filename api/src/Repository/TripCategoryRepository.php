<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Repository pro trip_categories — číselník kategorií cest (služební/soukromá).
 *
 * Per tenant (supplier_id). UNIQUE (supplier_id, code). Soft delete (is_archived)
 * pokud je kategorie použita na jízdě, jinak hard delete.
 */
final class TripCategoryRepository
{
    public function __construct(private readonly Connection $db) {}

    /**
     * @return list<array<string,mixed>>
     */
    public function listForTenant(int $supplierId, bool $includeArchived = false): array
    {
        $sql = 'SELECT tc.*,
                       (SELECT COUNT(*) FROM trips t WHERE t.category_id = tc.id) AS trips_count
                  FROM trip_categories tc
                 WHERE tc.supplier_id = ?';
        if (!$includeArchived) $sql .= ' AND tc.is_archived = 0';
        $sql .= ' ORDER BY tc.display_order ASC, tc.label ASC';
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([$supplierId]);
        return array_map(fn ($r) => $this->cast($r), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function find(int $id, int $supplierId): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM trip_categories WHERE id = ? AND supplier_id = ?');
        $stmt->execute([$id, $supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->cast($row);
    }

    /** Najde kategorii podle labelu nebo kódu (case-insensitive) — pro CSV/XLSX import. */
    public function findByLabelOrCode(int $supplierId, string $needle): ?array
    {
        $needle = trim($needle);
        if ($needle === '') return null;
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM trip_categories
              WHERE supplier_id = ? AND (LOWER(label) = LOWER(?) OR LOWER(code) = LOWER(?))
              ORDER BY is_archived ASC LIMIT 1'
        );
        $stmt->execute([$supplierId, $needle, $needle]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->cast($row);
    }

    /**
     * Najde kategorii podle labelu/kódu, jinak ji založí (pro auto-create z importu).
     * Code odvozen z labelu (slug); při kolizi kódu vrátí existující.
     */
    public function findOrCreate(int $supplierId, string $label): int
    {
        $label = trim($label);
        $existing = $this->findByLabelOrCode($supplierId, $label);
        if ($existing !== null) return (int) $existing['id'];

        $code = $this->slug($label);
        try {
            return $this->create($supplierId, ['code' => $code, 'label' => mb_substr($label, 0, 100), 'is_private' => false, 'display_order' => 100]);
        } catch (\PDOException $e) {
            if (str_contains($e->getMessage(), 'Duplicate')) {
                $byCode = $this->findByLabelOrCode($supplierId, $code);
                if ($byCode !== null) return (int) $byCode['id'];
            }
            throw $e;
        }
    }

    private function slug(string $label): string
    {
        return \MyInvoice\Support\Slugifier::slug($label, '_', 'lower', 30, 'kat');
    }

    public function create(int $supplierId, array $data): int
    {
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            // Per tenant smí být max jedna výchozí kategorie (jako cars.is_default).
            if (!empty($data['is_default'])) {
                $this->clearDefault($supplierId);
            }
            $pdo->prepare(
                'INSERT INTO trip_categories (supplier_id, code, label, is_private, is_default, display_order)
                 VALUES (?, ?, ?, ?, ?, ?)'
            )->execute([
                $supplierId,
                (string) $data['code'],
                (string) $data['label'],
                !empty($data['is_private']) ? 1 : 0,
                !empty($data['is_default']) ? 1 : 0,
                (int) ($data['display_order'] ?? 0),
            ]);
            $id = (int) $pdo->lastInsertId();
            $pdo->commit();
            return $id;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    public function update(int $id, int $supplierId, array $data): bool
    {
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            if (!empty($data['is_default'])) {
                $this->clearDefault($supplierId, $id);
            }
            $stmt = $pdo->prepare(
                'UPDATE trip_categories
                    SET code = ?, label = ?, is_private = ?, is_default = ?, display_order = ?, is_archived = ?
                  WHERE id = ? AND supplier_id = ?'
            );
            $stmt->execute([
                (string) $data['code'],
                (string) $data['label'],
                !empty($data['is_private']) ? 1 : 0,
                !empty($data['is_default']) ? 1 : 0,
                (int) ($data['display_order'] ?? 0),
                !empty($data['is_archived']) ? 1 : 0,
                $id,
                $supplierId,
            ]);
            $ok = $stmt->rowCount() >= 0;
            $pdo->commit();
            return $ok;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    /** Zruší příznak výchozí kategorie u všech (kromě volitelně $exceptId) — drží unikátnost is_default per tenant. */
    private function clearDefault(int $supplierId, ?int $exceptId = null): void
    {
        $sql = 'UPDATE trip_categories SET is_default = 0 WHERE supplier_id = ? AND is_default = 1';
        $params = [$supplierId];
        if ($exceptId !== null) {
            $sql .= ' AND id <> ?';
            $params[] = $exceptId;
        }
        $this->db->pdo()->prepare($sql)->execute($params);
    }

    public function delete(int $id, int $supplierId): array
    {
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM trips WHERE category_id = ?');
        $stmt->execute([$id]);
        $usage = (int) $stmt->fetchColumn();

        // Kategorie s navázanými jízdami NELZE smazat (lze ji archivovat při úpravě).
        if ($usage > 0) {
            return ['deleted' => false, 'blocked' => true, 'usage_count' => $usage];
        }
        $pdo->prepare('DELETE FROM trip_categories WHERE id = ? AND supplier_id = ?')->execute([$id, $supplierId]);
        return ['deleted' => true, 'blocked' => false];
    }

    private function cast(array $r): array
    {
        return [
            'id'            => (int) $r['id'],
            'supplier_id'   => (int) $r['supplier_id'],
            'code'          => (string) $r['code'],
            'label'         => (string) $r['label'],
            'is_private'    => (bool) $r['is_private'],
            'is_default'    => (bool) ($r['is_default'] ?? false),
            'display_order' => (int) $r['display_order'],
            'is_archived'   => (bool) $r['is_archived'],
            'trips_count'   => isset($r['trips_count']) ? (int) $r['trips_count'] : null,
        ];
    }
}
