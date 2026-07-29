<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

final class AccountingCorrectionRepository
{
    public function __construct(private readonly Connection $db) {}

    /** @param array<string,mixed> $data */
    public function insert(int $supplierId, array $data): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO accounting_corrections
                (supplier_id, event_type, entity_type, entity_id, suggestion_id, suggestion_source,
                 rule_id, suggested_debit, suggested_credit, final_debit, final_credit, amount,
                 model, prompt_version, reason, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $supplierId,
            $data['event_type'],
            $data['entity_type'],
            $data['entity_id'],
            $data['suggestion_id'] ?? null,
            $data['suggestion_source'] ?? null,
            $data['rule_id'] ?? null,
            $data['suggested_debit'] ?? null,
            $data['suggested_credit'] ?? null,
            $data['final_debit'] ?? null,
            $data['final_credit'] ?? null,
            $data['amount'] ?? null,
            $data['model'] ?? null,
            $data['prompt_version'] ?? null,
            $data['reason'] ?? null,
            $data['created_by'] ?? null,
        ]);
        return (int) $pdo->lastInsertId();
    }

    /** @return list<array<string,mixed>> */
    public function forEntity(int $supplierId, string $entityType, int $entityId, int $limit = 20): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT c.*, u.name AS created_by_name
               FROM accounting_corrections c
               LEFT JOIN users u ON u.id = c.created_by
              WHERE c.supplier_id = ? AND c.entity_type = ? AND c.entity_id = ?
              ORDER BY c.created_at DESC, c.id DESC LIMIT ' . max(1, min(200, $limit))
        );
        $stmt->execute([$supplierId, $entityType, $entityId]);
        return array_map([$this, 'cast'], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /** @return list<array<string,mixed>> */
    public function forRule(int $supplierId, int $ruleId, int $limit = 50): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT c.*, u.name AS created_by_name
               FROM accounting_corrections c
               LEFT JOIN users u ON u.id = c.created_by
              WHERE c.supplier_id = ? AND c.rule_id = ?
              ORDER BY c.created_at DESC, c.id DESC LIMIT ' . max(1, min(200, $limit))
        );
        $stmt->execute([$supplierId, $ruleId]);
        return array_map([$this, 'cast'], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /** @return array{items:list<array<string,mixed>>,total:int} */
    public function paginateForRule(int $supplierId, int $ruleId, int $limit, int $offset): array
    {
        $pdo = $this->db->pdo();
        $count = $pdo->prepare('SELECT COUNT(*) FROM accounting_corrections WHERE supplier_id = ? AND rule_id = ?');
        $count->execute([$supplierId, $ruleId]);
        $stmt = $pdo->prepare(
            'SELECT c.*, u.name AS created_by_name
               FROM accounting_corrections c
               LEFT JOIN users u ON u.id = c.created_by
              WHERE c.supplier_id = ? AND c.rule_id = ?
              ORDER BY c.created_at DESC, c.id DESC LIMIT ? OFFSET ?'
        );
        $stmt->bindValue(1, $supplierId, PDO::PARAM_INT);
        $stmt->bindValue(2, $ruleId, PDO::PARAM_INT);
        $stmt->bindValue(3, max(1, $limit), PDO::PARAM_INT);
        $stmt->bindValue(4, max(0, $offset), PDO::PARAM_INT);
        $stmt->execute();
        return [
            'items' => array_map([$this, 'cast'], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []),
            'total' => (int) $count->fetchColumn(),
        ];
    }

    /** @return array{override_count:int,reject_count:int} */
    public function statsForRule(int $supplierId, int $ruleId): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT SUM(event_type = 'approve_override') AS override_count,
                    SUM(event_type = 'reject') AS reject_count
               FROM accounting_corrections
              WHERE supplier_id = ? AND rule_id = ?"
        );
        $stmt->execute([$supplierId, $ruleId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        return [
            'override_count' => (int) ($row['override_count'] ?? 0),
            'reject_count' => (int) ($row['reject_count'] ?? 0),
        ];
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function cast(array $row): array
    {
        foreach (['id', 'supplier_id', 'entity_id'] as $key) $row[$key] = (int) $row[$key];
        foreach (['suggestion_id', 'rule_id', 'created_by'] as $key) {
            $row[$key] = $row[$key] === null ? null : (int) $row[$key];
        }
        $row['amount'] = $row['amount'] === null ? null : (float) $row['amount'];
        return $row;
    }
}
