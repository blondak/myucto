<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Submission;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Číselník datových schránek institucí (migrace 1381).
 *
 * Systémové záznamy mají `supplier_id IS NULL` a vidí je všechny firmy;
 * firma si smí přidat vlastní. `source_url` je u vyplněného ID povinné —
 * hlídá to CHECK v DB, ne jen validace tady.
 */
final class SubmissionRecipientRepository
{
    private const TABLE = 'submission_recipients';

    private const COLUMNS = 'id, supplier_id, code, name, kind, isds_box_id, source_url,
        source_note, is_active, created_at, updated_at';

    public function __construct(private readonly Connection $db) {}

    public function isAvailable(): bool
    {
        return $this->db->hasTable(self::TABLE);
    }

    /** @return list<array<string,mixed>> */
    public function listVisible(int $supplierId, ?string $kind = null): array
    {
        $this->assertAvailable();
        $sql = 'SELECT ' . self::COLUMNS . ' FROM ' . self::TABLE . '
                 WHERE (supplier_id IS NULL OR supplier_id = ?)';
        $params = [$supplierId];
        if ($kind !== null) {
            $sql .= ' AND kind = ?';
            $params[] = $kind;
        }
        $sql .= ' ORDER BY kind ASC, name ASC';

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        return array_map(self::normalize(...), $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /** @return array<string,mixed>|null */
    public function findVisible(int $supplierId, int $id): ?array
    {
        $this->assertAvailable();
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM ' . self::TABLE . '
              WHERE id = ? AND (supplier_id IS NULL OR supplier_id = ?)'
        );
        $stmt->execute([$id, $supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? self::normalize($row) : null;
    }

    /**
     * @param array{code:string,name:string,kind:string,isds_box_id:?string,
     *   source_url:?string,source_note:?string,is_active:bool} $data
     */
    public function upsertForSupplier(int $supplierId, array $data, ?int $userId): int
    {
        $this->assertAvailable();
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO ' . self::TABLE . '
                (supplier_id, code, name, kind, isds_box_id, source_url, source_note, is_active, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                name = VALUES(name), kind = VALUES(kind), isds_box_id = VALUES(isds_box_id),
                source_url = VALUES(source_url), source_note = VALUES(source_note),
                is_active = VALUES(is_active)'
        );
        $stmt->execute([
            $supplierId,
            $data['code'],
            $data['name'],
            $data['kind'],
            $data['isds_box_id'],
            $data['source_url'],
            $data['source_note'],
            $data['is_active'] ? 1 : 0,
            $userId,
        ]);
        $id = (int) $this->db->pdo()->lastInsertId();
        if ($id > 0) {
            return $id;
        }
        $existing = $this->findByCode($supplierId, $data['code']);
        return $existing !== null ? (int) $existing['id'] : 0;
    }

    /** @return array<string,mixed>|null */
    public function findByCode(int $supplierId, string $code): ?array
    {
        $this->assertAvailable();
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM ' . self::TABLE . ' WHERE supplier_id = ? AND code = ?'
        );
        $stmt->execute([$supplierId, $code]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? self::normalize($row) : null;
    }

    /** Systémové záznamy smazat nejde — patří všem, ne jedné firmě. */
    public function deleteOwn(int $supplierId, int $id): bool
    {
        $this->assertAvailable();
        $stmt = $this->db->pdo()->prepare(
            'DELETE FROM ' . self::TABLE . ' WHERE id = ? AND supplier_id = ?'
        );
        $stmt->execute([$id, $supplierId]);
        return $stmt->rowCount() > 0;
    }

    private function assertAvailable(): void
    {
        if (!$this->isAvailable()) {
            throw new \DomainException('Číselník příjemců není v databázi k dispozici (chybí migrace 1381).');
        }
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function normalize(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['supplier_id'] = $row['supplier_id'] !== null ? (int) $row['supplier_id'] : null;
        $row['is_active'] = (bool) $row['is_active'];
        $row['is_system'] = $row['supplier_id'] === null;
        // Číselník bez ID schránky je legitimní stav (u finančních úřadů
        // nemáme doklad), ale UI to musí umět rozeznat na první pohled.
        $row['has_box_id'] = $row['isds_box_id'] !== null;
        return $row;
    }
}
