<?php

declare(strict_types=1);

namespace MyInvoice\Service\Ai;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

final class AiDpaGate
{
    public function __construct(private readonly Connection $db) {}

    public function assertConfirmed(int $supplierId, string $provider): void
    {
        if (!$this->isConfirmed($supplierId, $provider)) {
            throw new AiDpaException();
        }
    }

    public function isConfirmed(int $supplierId, string $provider): bool
    {
        $stmt = $this->db->pdo()->prepare('SELECT ai_dpa_confirmations FROM supplier WHERE id = ?');
        $stmt->execute([$supplierId]);
        $raw = $stmt->fetchColumn();
        if (!is_string($raw) || $raw === '') {
            return false;
        }
        $items = json_decode($raw, true);
        return is_array($items)
            && is_array($items[$provider] ?? null)
            && is_string($items[$provider]['confirmed_at'] ?? null)
            && $items[$provider]['confirmed_at'] !== '';
    }

    /** @return array<string,string|null> */
    public function confirmations(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT ai_dpa_confirmations FROM supplier WHERE id = ?');
        $stmt->execute([$supplierId]);
        $items = json_decode((string) ($stmt->fetchColumn() ?: '{}'), true);
        $out = [];
        foreach (['anthropic', 'azure_openai', 'openai', 'gemini'] as $provider) {
            $out[$provider] = is_array($items) && is_string($items[$provider]['confirmed_at'] ?? null)
                ? (string) $items[$provider]['confirmed_at'] : null;
        }
        return $out;
    }
}
