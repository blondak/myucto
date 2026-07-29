<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Repository pro entity_category_history (D5, audit 2026-07 Fáze D) — zmražená
 * kritéria kategorizace ÚJ (§1d ZoÚ) per uzavřené období. Zápis proběhne v kroku
 * uzávěrky (ClosingService::closeBooks); EntityCategoryService z něj čte raw
 * kategorii uzavřených období místo drahého přepočtu (fallback = přepočet pro
 * období bez záznamu). Tenant izolace přes supplier_id, jeden řádek per období.
 */
final class EntityCategoryHistoryRepository
{
    public function __construct(private readonly Connection $db) {}

    /**
     * Zmražená raw kategorie období, nebo null (období bez záznamu → fallback přepočet).
     */
    public function findRaw(int $supplierId, int $periodId): ?string
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT raw_category FROM entity_category_history
              WHERE supplier_id = ? AND period_id = ?'
        );
        $stmt->execute([$supplierId, $periodId]);
        $v = $stmt->fetchColumn();
        return ($v === false || $v === null) ? null : (string) $v;
    }

    /**
     * Upsert zmraženého řádku pro období (idempotentní vůči re-run uzávěrky).
     */
    public function upsert(
        int $supplierId,
        int $periodId,
        float $assetsNet,
        float $netTurnover,
        int $avgEmployees,
        string $rawCategory,
    ): void {
        $this->db->pdo()->prepare(
            'INSERT INTO entity_category_history
                (supplier_id, period_id, assets_net, net_turnover, avg_employees, raw_category, frozen_at)
             VALUES (?, ?, ?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE
                 assets_net    = VALUES(assets_net),
                 net_turnover  = VALUES(net_turnover),
                 avg_employees = VALUES(avg_employees),
                 raw_category  = VALUES(raw_category),
                 frozen_at     = VALUES(frozen_at)'
        )->execute([$supplierId, $periodId, $assetsNet, $netTurnover, $avgEmployees, $rawCategory]);
    }
}
