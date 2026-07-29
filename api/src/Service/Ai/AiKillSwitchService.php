<?php

declare(strict_types=1);

namespace MyInvoice\Service\Ai;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\ActivityLogger;
use PDO;

final class AiKillSwitchService
{
    public function __construct(
        private readonly Connection $db,
        private readonly ActivityLogger $activity,
    ) {}

    public function evaluate(int $supplierId, string $source): void
    {
        if (!in_array($source, ['knn', 'llm'], true) || $this->isMuted($supplierId, $source)) {
            return;
        }
        $rows30 = $this->decisions($supplierId, $source, true);
        $rows = count($rows30) >= 10 ? $rows30 : $this->decisions($supplierId, $source, false);
        if (count($rows) < 10) {
            return;
        }
        $accepted = count(array_filter($rows, static fn (array $row): bool => in_array($row['status'], ['approved', 'accepted'], true)));
        $rate = $accepted / count($rows);
        if ($rate >= 0.50) {
            return;
        }
        $reason = ['window' => count($rows30) >= 10 ? '30d' : 20, 'decided' => count($rows), 'accepted' => $accepted, 'rate' => round($rate, 4)];
        try {
            $this->db->pdo()->prepare(
                'INSERT INTO ai_source_mutes (supplier_id,source,muted_at,reason_json) VALUES (?,?,NOW(),?)'
            )->execute([$supplierId, $source, json_encode($reason, JSON_THROW_ON_ERROR)]);
        } catch (\PDOException $e) {
            if (($e->errorInfo[0] ?? null) === '23000') {
                return;
            }
            throw $e;
        }
        $this->activity->log('ai.source_muted', null, 'supplier', $supplierId, ['source' => $source, 'reason' => $reason], supplierId: $supplierId);
    }

    public function isMuted(int $supplierId, string $source): bool
    {
        $stmt = $this->db->pdo()->prepare('SELECT 1 FROM ai_source_mutes WHERE supplier_id=? AND source=? AND unmuted_at IS NULL LIMIT 1');
        $stmt->execute([$supplierId, $source]);
        return $stmt->fetchColumn() !== false;
    }

    /** @return list<array<string,mixed>> */
    public function activeMutes(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT source,muted_at,reason_json FROM ai_source_mutes WHERE supplier_id=? AND unmuted_at IS NULL ORDER BY muted_at');
        $stmt->execute([$supplierId]);
        return array_map(static fn (array $row): array => [
            'source' => (string) $row['source'], 'muted_at' => (string) $row['muted_at'],
            'reason' => json_decode((string) $row['reason_json'], true) ?: [],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function unmute(int $supplierId, string $source, int $userId): void
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE ai_source_mutes SET unmuted_at=NOW(),unmuted_by=? WHERE supplier_id=? AND source=? AND unmuted_at IS NULL'
        );
        $stmt->execute([$userId, $supplierId, $source]);
        if ($stmt->rowCount() > 0) {
            $this->activity->log('ai.source_unmuted', $userId, 'supplier', $supplierId, ['source' => $source], supplierId: $supplierId);
        }
    }

    /** @return list<array{status:string,decided_at:string}> */
    private function decisions(int $supplierId, string $source, bool $last30Days): array
    {
        $bankWhere = $last30Days ? 'AND reviewed_at>=DATE_SUB(NOW(),INTERVAL 30 DAY)' : '';
        $aiWhere = $last30Days ? 'AND decided_at>=DATE_SUB(NOW(),INTERVAL 30 DAY)' : '';
        $limit = $last30Days ? '' : 'LIMIT 20';
        $stmt = $this->db->pdo()->prepare(
            "SELECT status,decided_at FROM (
                SELECT CASE WHEN EXISTS (
                           SELECT 1 FROM accounting_corrections c
                            WHERE c.supplier_id=s.supplier_id AND c.suggestion_id=s.id
                              AND c.event_type='approve_override'
                       ) THEN 'overridden' ELSE s.status END status,
                       s.reviewed_at decided_at
                  FROM bank_posting_suggestions s
                 WHERE s.supplier_id=? AND s.source=? AND s.status IN ('approved','rejected') AND s.reviewed_at IS NOT NULL {$bankWhere}
                UNION ALL
                SELECT status,decided_at FROM ai_suggestions
                 WHERE supplier_id=? AND source=? AND status IN ('accepted','rejected') AND decided_at IS NOT NULL {$aiWhere}
             ) d ORDER BY decided_at DESC {$limit}"
        );
        $stmt->execute([$supplierId, $source, $supplierId, $source]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
