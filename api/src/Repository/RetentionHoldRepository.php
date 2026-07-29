<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;

/**
 * § 32 ZoÚ — zadržení záznamů po dobu daňového řízení („legal hold").
 *
 * Lhůty podle § 31 jdou spočítat, tahle skutečnost ne: daňová kontrola ani soudní spor
 * se v účetních datech nikde neobjeví. Bez ručně zadaného holdu by brána proti smazání
 * uvolnila právě ty dokumenty, které správce daně prověřuje.
 *
 * Hold bez `period_year` platí na celé účetnictví firmy — rozsáhlá kontrola se nemusí
 * vázat na jediné období.
 */
final class RetentionHoldRepository
{
    public function __construct(private readonly Connection $db) {}

    /** Trvá na daný rok (nebo na celé účetnictví) aktivní hold? */
    public function hasActiveHold(int $supplierId, ?int $periodYear = null): bool
    {
        $sql = 'SELECT 1 FROM retention_holds
                 WHERE supplier_id = ? AND released_on IS NULL';
        $params = [$supplierId];
        if ($periodYear !== null) {
            $sql .= ' AND (period_year IS NULL OR period_year = ?)';
            $params[] = $periodYear;
        }
        $sql .= ' LIMIT 1';

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Aktivní holdy dotýkající se roku — do chybové hlášky, ať uživatel ví, co blokuje.
     *
     * @return list<array<string,mixed>>
     */
    public function activeHolds(int $supplierId, ?int $periodYear = null): array
    {
        $sql = 'SELECT * FROM retention_holds
                 WHERE supplier_id = ? AND released_on IS NULL';
        $params = [$supplierId];
        if ($periodYear !== null) {
            $sql .= ' AND (period_year IS NULL OR period_year = ?)';
            $params[] = $periodYear;
        }
        $sql .= ' ORDER BY placed_on, id';

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /** @return list<array<string,mixed>> */
    public function all(int $supplierId, bool $includeReleased = false): array
    {
        $sql = 'SELECT * FROM retention_holds WHERE supplier_id = ?';
        if (!$includeReleased) {
            $sql .= ' AND released_on IS NULL';
        }
        $sql .= ' ORDER BY released_on IS NOT NULL, placed_on DESC, id DESC';

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([$supplierId]);

        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function place(
        int $supplierId,
        ?int $periodYear,
        string $reason,
        string $description,
        string $placedOn,
        ?int $userId,
    ): int {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO retention_holds
                (supplier_id, period_year, reason, description, placed_on, created_by)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$supplierId, $periodYear, $reason, $description, $placedOn, $userId]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    /** Uvolnění holdu je vědomý úkon — záznam zůstává, jen dostane `released_on`. */
    public function release(int $supplierId, int $id, string $releasedOn, ?int $userId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE retention_holds
                SET released_on = ?, released_by = ?
              WHERE id = ? AND supplier_id = ? AND released_on IS NULL'
        );
        $stmt->execute([$releasedOn, $userId, $id, $supplierId]);

        return $stmt->rowCount() > 0;
    }
}
