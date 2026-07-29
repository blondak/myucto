<?php

declare(strict_types=1);

namespace MyInvoice\Service\Ai;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;
use PDOException;

final class AiSuggestionRepository
{
    public function __construct(private readonly Connection $db) {}

    /** @param array<string,mixed> $data @return array{id:int,created:bool} */
    public function create(array $data): array
    {
        try {
            $this->db->pdo()->prepare(
                'INSERT INTO ai_suggestions
                    (supplier_id,entity_type,entity_id,source,payload_json,input_hash,confidence,model,provider,prompt_version,reasoning)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?)'
            )->execute([
                $data['supplier_id'], $data['entity_type'], $data['entity_id'], $data['source'],
                json_encode($data['payload'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                $data['input_hash'] ?? null, min(0.40, max(0.0, (float) $data['confidence'])), $data['model'] ?? null,
                $data['provider'] ?? null, $data['prompt_version'] ?? null, $data['reasoning'] ?? null,
            ]);
            return ['id' => (int) $this->db->pdo()->lastInsertId(), 'created' => true];
        } catch (PDOException $e) {
            if (($e->errorInfo[0] ?? null) !== '23000') {
                throw $e;
            }
            $row = $this->pendingForEntity((int) $data['supplier_id'], (string) $data['entity_type'], (int) $data['entity_id']);
            if ($row === null) {
                throw $e;
            }
            return ['id' => (int) $row['id'], 'created' => false];
        }
    }

    public function find(int $supplierId, int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM ai_suggestions WHERE supplier_id=? AND id=?');
        $stmt->execute([$supplierId, $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->cast($row);
    }

    public function pendingForEntity(int $supplierId, string $entityType, int $entityId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT * FROM ai_suggestions WHERE supplier_id=? AND entity_type=? AND entity_id=? AND status='pending' ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([$supplierId, $entityType, $entityId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->cast($row);
    }

    /** @param array<string,mixed> $payload */
    public function accept(int $supplierId, int $id, int $userId, array $payload): bool
    {
        $stmt = $this->db->pdo()->prepare(
            "UPDATE ai_suggestions SET status='accepted',payload_json=?,decided_by=?,decided_at=NOW()
              WHERE supplier_id=? AND id=? AND status='pending'"
        );
        $stmt->execute([
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            $userId, $supplierId, $id,
        ]);
        return $stmt->rowCount() === 1;
    }

    public function reject(int $supplierId, int $id, int $userId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            "UPDATE ai_suggestions SET status='rejected',decided_by=?,decided_at=NOW()
              WHERE supplier_id=? AND id=? AND status='pending'"
        );
        $stmt->execute([$userId, $supplierId, $id]);
        return $stmt->rowCount() === 1;
    }

    /** @return array{debit:string,input_hash:?string}|null */
    public function acceptedForPurchase(int $supplierId, int $purchaseId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT JSON_UNQUOTE(JSON_EXTRACT(payload_json,'$.debit_account_code')) debit,input_hash
               FROM ai_suggestions
              WHERE supplier_id=? AND entity_type='purchase_invoice' AND entity_id=? AND status='accepted'
              ORDER BY decided_at DESC,id DESC LIMIT 1"
        );
        $stmt->execute([$supplierId, $purchaseId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || !is_string($row['debit']) || $row['debit'] === '') {
            return null;
        }
        return ['debit' => $row['debit'], 'input_hash' => is_string($row['input_hash']) ? $row['input_hash'] : null];
    }

    /**
     * Odsune ČEKAJÍCÍ návrh jako překonaný — pro případ, kdy si účetní vyžádá nový
     * s vlastním dotazem.
     *
     * Bez tohohle kroku narazí nový návrh na unikátní index „jeden pending na doklad",
     * {@see create()} chybu spolkne a vrátí TEN STARÝ návrh. Uživatel by se zeptal,
     * dostal zpátky odpověď na jinou otázku a neměl jak poznat, že jeho dotaz nikam
     * nedošel. `expireForEntity` se sem nehodí — ruší i už přijaté návrhy, které se
     * novým dotazem nemění.
     */
    public function supersedePending(int $supplierId, string $entityType, int $entityId): void
    {
        $this->db->pdo()->prepare(
            "UPDATE ai_suggestions SET status='superseded'
              WHERE supplier_id=? AND entity_type=? AND entity_id=? AND status='pending'"
        )->execute([$supplierId, $entityType, $entityId]);
    }

    public function expireForEntity(int $supplierId, string $entityType, int $entityId): void
    {
        $this->db->pdo()->prepare(
            "UPDATE ai_suggestions SET status='expired'
              WHERE supplier_id=? AND entity_type=? AND entity_id=? AND status IN ('pending','accepted')"
        )->execute([$supplierId, $entityType, $entityId]);
    }

    private function cast(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['supplier_id'] = (int) $row['supplier_id'];
        $row['entity_id'] = (int) $row['entity_id'];
        $row['confidence'] = (float) $row['confidence'];
        $row['payload'] = json_decode((string) $row['payload_json'], true) ?: [];
        unset($row['payload_json'], $row['pending_entity']);
        return $row;
    }
}
