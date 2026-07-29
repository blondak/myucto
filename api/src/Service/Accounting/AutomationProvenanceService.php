<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Čtecí model původu automatických účetních zápisů. Současnou bankovní automatiku
 * čte z bank_posting_suggestions a automatické zaúčtování dokladů z activity_log.
 */
final class AutomationProvenanceService
{
    public function __construct(private readonly Connection $db) {}

    /**
     * @param list<int> $entryIds
     * @return array<int,array<string,mixed>> mapa journal_entry_id => provenance
     */
    public function forJournalEntries(int $supplierId, array $entryIds): array
    {
        $entryIds = array_values(array_unique(array_filter(array_map('intval', $entryIds), static fn (int $id): bool => $id > 0)));
        if ($entryIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($entryIds), '?'));
        $sql = "SELECT s.journal_entry_id AS entry_id,
                       CASE s.source
                           WHEN 'payment_match' THEN 'matched'
                           WHEN 'learned' THEN 'learned'
                           WHEN 'transfer' THEN 'detector'
                           WHEN 'detector' THEN 'detector'
                           WHEN 'schedule' THEN 'schedule'
                           WHEN 'knn' THEN 'ai'
                           WHEN 'llm' THEN 'ai'
                           ELSE 'rule'
                       END AS automation_source,
                       COALESCE(s.detector, CASE WHEN s.source = 'transfer' THEN 'own_transfer' ELSE NULL END) AS detector,
                       CASE s.status WHEN 'auto_posted' THEN 'auto' ELSE 'approved' END AS automation_mode,
                       s.rule_id, r.name AS rule_name, s.id AS suggestion_id,
                       s.confidence,
                       COALESCE(s.reviewed_at, s.created_at) AS decided_at,
                       CASE WHEN s.status = 'approved' THEN u.name ELSE NULL END AS decided_by
                  FROM bank_posting_suggestions s
             LEFT JOIN bank_posting_rules r ON r.id = s.rule_id AND r.supplier_id = s.supplier_id
             LEFT JOIN users u ON u.id = s.reviewed_by
                 WHERE s.supplier_id = ?
                   AND s.status IN ('auto_posted', 'approved')
                   AND s.journal_entry_id IN ({$placeholders})
                UNION ALL
                SELECT CAST(JSON_UNQUOTE(JSON_EXTRACT(al.payload, '$.journal_entry_id')) AS UNSIGNED) AS entry_id,
                       CASE al.action WHEN 'bank_match.auto_posted' THEN 'matched' ELSE 'rule' END AS automation_source,
                       NULL AS detector,
                       'auto' AS automation_mode,
                       NULL AS rule_id, NULL AS rule_name, NULL AS suggestion_id,
                       NULL AS confidence,
                       al.created_at AS decided_at, NULL AS decided_by
                 FROM activity_log al
                 WHERE al.supplier_id = ? AND al.action IN ('accounting.auto_posted', 'bank_match.auto_posted')
                   AND CAST(JSON_UNQUOTE(JSON_EXTRACT(al.payload, '$.journal_entry_id')) AS UNSIGNED) IN ({$placeholders})
                ORDER BY decided_at DESC";

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([$supplierId, ...$entryIds, $supplierId, ...$entryIds]);

        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $entryId = (int) $row['entry_id'];
            if ($entryId <= 0 || isset($result[$entryId])) {
                continue;
            }
            $result[$entryId] = [
                'source'        => (string) $row['automation_source'],
                'mode'          => (string) $row['automation_mode'],
                'confidence'    => $row['confidence'] === null ? null : (float) $row['confidence'],
                'detector'      => $row['detector'] === null ? null : (string) $row['detector'],
                'rule_id'       => $row['rule_id'] === null ? null : (int) $row['rule_id'],
                'rule_name'     => $row['rule_name'] === null ? null : (string) $row['rule_name'],
                'suggestion_id' => $row['suggestion_id'] === null ? null : (int) $row['suggestion_id'],
                'decided_at'    => (string) $row['decided_at'],
                'decided_by'    => $row['decided_by'] === null ? null : (string) $row['decided_by'],
            ];
        }
        return $result;
    }
}
