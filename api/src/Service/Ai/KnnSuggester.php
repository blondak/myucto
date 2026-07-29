<?php

declare(strict_types=1);

namespace MyInvoice\Service\Ai;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

final class KnnSuggester
{
    public const COLD_START_MIN_LABELS = 20;

    public function __construct(private readonly Connection $db) {}

    /** @return array<string,mixed>|null */
    public function suggestForBankTx(int $supplierId, int $txId): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT VEC_ToText(embedding) FROM ai_embeddings WHERE supplier_id=? AND entity_type="bank_transaction" AND entity_id=?');
        $stmt->execute([$supplierId, $txId]);
        $vector = $stmt->fetchColumn();
        return is_string($vector) ? $this->suggestForVector($supplierId, 'bank_transaction', $vector) : null;
    }

    public function isWarm(int $supplierId, string $entityType): bool
    {
        $creditCondition = $entityType === 'purchase_invoice' ? '' : ' AND label_credit IS NOT NULL';
        $stmt = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM ai_embeddings WHERE supplier_id=? AND entity_type=? AND label_debit IS NOT NULL' . $creditCondition
        );
        $stmt->execute([$supplierId, $entityType]);
        return (int) $stmt->fetchColumn() >= self::COLD_START_MIN_LABELS;
    }

    public function labelCount(int $supplierId, string $entityType): int
    {
        $stmt = $this->db->pdo()->prepare('SELECT COUNT(*) FROM ai_embeddings WHERE supplier_id=? AND entity_type=? AND label_debit IS NOT NULL');
        $stmt->execute([$supplierId, $entityType]);
        return (int) $stmt->fetchColumn();
    }

    /** @return array<string,mixed>|null */
    public function suggestForVector(int $supplierId, string $entityType, string $vectorJson): ?array
    {
        if (!$this->isWarm($supplierId, $entityType)) {
            return null;
        }
        $creditCondition = $entityType === 'purchase_invoice' ? '' : ' AND label_credit IS NOT NULL';
        $stmt = $this->db->pdo()->prepare(
            'SELECT supplier_id,entity_id,label_debit,label_credit,
                    VEC_DISTANCE_COSINE(embedding,VEC_FromText(?)) dist
               FROM ai_embeddings
              WHERE supplier_id=? AND entity_type=? AND label_debit IS NOT NULL' . $creditCondition . '
              ORDER BY dist ASC LIMIT 40'
        );
        $stmt->execute([$vectorJson, $supplierId, $entityType]);
        $neighbors = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if ((int) $row['supplier_id'] !== $supplierId) {
                continue;
            }
            $neighbors[] = $row;
            if (count($neighbors) === 8) {
                break;
            }
        }
        if (count($neighbors) < 5) {
            return null;
        }
        $votes = [];
        foreach ($neighbors as $row) {
            $key = (string) $row['label_debit'] . '/' . (string) ($row['label_credit'] ?? '');
            $votes[$key] = ($votes[$key] ?? 0) + 1;
        }
        arsort($votes);
        $pair = (string) array_key_first($votes);
        $voteCount = (int) reset($votes);
        $similarity = 1.0 - (float) $neighbors[0]['dist'];
        if ($voteCount < 5 || $similarity < 0.80) {
            return null;
        }
        [$debit, $credit] = explode('/', $pair, 2);
        return [
            'debit' => $debit, 'credit' => $credit,
            'confidence' => round(0.40 * ($voteCount / 8) * min(1.0, $similarity), 2),
            'neighbor_tx_id' => (int) $neighbors[0]['entity_id'],
        ];
    }
}
