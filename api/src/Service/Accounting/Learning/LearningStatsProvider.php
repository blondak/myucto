<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Learning;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

final class LearningStatsProvider
{
    public function __construct(private readonly Connection $db) {}

    /** @return array<string,mixed> */
    public function stats(int $supplierId, string $from, string $to): array
    {
        $pdo = $this->db->pdo();
        $sourceStmt = $pdo->prepare(
            "SELECT s.source, COUNT(*) suggested,
                    SUM(s.status IN ('approved','auto_posted')) approved,
                    SUM(s.status = 'rejected') rejected,
                    SUM(EXISTS(SELECT 1 FROM accounting_corrections c
                         WHERE c.supplier_id=s.supplier_id AND c.suggestion_id=s.id
                           AND c.event_type='approve_override')) approved_with_override
               FROM bank_posting_suggestions s
              WHERE s.supplier_id=? AND DATE(s.created_at) BETWEEN ? AND ?
              GROUP BY s.source ORDER BY s.source"
        );
        $sourceStmt->execute([$supplierId, $from, $to]);
        $sources = array_map(static function (array $row): array {
            $approved = (int) $row['approved'];
            $rejected = (int) $row['rejected'];
            $overridden = (int) $row['approved_with_override'];
            return [
                'source' => (string) $row['source'],
                'suggested' => (int) $row['suggested'],
                'approved' => $approved,
                'approved_with_override' => $overridden,
                'rejected' => $rejected,
                'acceptance_rate' => ($approved + $rejected) === 0 ? 0.0 : round($approved / ($approved + $rejected), 4),
                'override_rate' => $approved === 0 ? 0.0 : round($overridden / $approved, 4),
            ];
        }, $sourceStmt->fetchAll(PDO::FETCH_ASSOC) ?: []);

        $autoStmt = $pdo->prepare(
            "SELECT COUNT(*) posted, SUM(je.reversed_by IS NOT NULL) reversed
               FROM bank_posting_suggestions s
               LEFT JOIN journal_entries je ON je.id=s.journal_entry_id AND je.supplier_id=s.supplier_id
              WHERE s.supplier_id=?
                AND (s.status='auto_posted'
                     OR (s.status='superseded' AND s.reviewed_by IS NULL AND s.journal_entry_id IS NOT NULL))
                AND s.reviewed_at IS NOT NULL AND DATE(s.reviewed_at) BETWEEN ? AND ?"
        );
        $autoStmt->execute([$supplierId, $from, $to]);
        $auto = $autoStmt->fetch(PDO::FETCH_ASSOC) ?: ['posted' => 0, 'reversed' => 0];
        $posted = (int) $auto['posted'];
        $reversed = (int) $auto['reversed'];

        $ruleStmt = $pdo->prepare(
            "SELECT COUNT(*) total, SUM(is_active=1) active, SUM(is_active=1 AND mode='auto') auto,
                    SUM(is_active=1 AND mode='suggest' AND hit_count>=5 AND rejected_streak=0
                        AND approved_streak>=5 AND amount_min IS NOT NULL AND amount_max IS NOT NULL) promotion_candidates
               FROM bank_posting_rules WHERE supplier_id=?"
        );
        $ruleStmt->execute([$supplierId]);
        $rules = $ruleStmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $eventsStmt = $pdo->prepare(
            'SELECT event_type, COUNT(*) n FROM accounting_corrections
              WHERE supplier_id=? AND DATE(created_at) BETWEEN ? AND ? GROUP BY event_type ORDER BY event_type'
        );
        $eventsStmt->execute([$supplierId, $from, $to]);
        $byEvent = [];
        foreach ($eventsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) $byEvent[(string) $row['event_type']] = (int) $row['n'];

        return [
            'range' => ['from' => $from, 'to' => $to],
            'sources' => $sources,
            'auto' => ['posted' => $posted, 'reversed' => $reversed, 'accuracy' => $posted === 0 ? 0.0 : round(1 - ($reversed / $posted), 4)],
            'rules' => [
                'total' => (int) ($rules['total'] ?? 0),
                'active' => (int) ($rules['active'] ?? 0),
                'auto' => (int) ($rules['auto'] ?? 0),
                'promotion_candidates' => (int) ($rules['promotion_candidates'] ?? 0),
                'promoted' => $byEvent['rule_promoted'] ?? 0,
                'demoted' => $byEvent['rule_demoted'] ?? 0,
                'mined' => $byEvent['rule_mined'] ?? 0,
            ],
            'corrections' => ['total' => array_sum($byEvent), 'by_event' => $byEvent],
        ];
    }
}
