<?php

declare(strict_types=1);

namespace MyInvoice\Service\Ai;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Import\LlmProviderRegistry;
use MyInvoice\Service\Import\OllamaClient;
use PDO;

final class AiDpaGate
{
    public function __construct(
        private readonly Connection $db,
        // Povinný: PHP-DI autowiring volitelný parametr (`= null`) nevyplní a výjimka
        // pro lokální Ollamu by v aplikaci tiše nefungovala (AiDpaGateWiringTest).
        private readonly OllamaClient $ollama,
    ) {}

    public function assertConfirmed(int $supplierId, string $provider): void
    {
        if (!$this->isConfirmed($supplierId, $provider)) {
            throw new AiDpaException();
        }
    }

    public function isConfirmed(int $supplierId, string $provider): bool
    {
        if ($this->isExempt($supplierId, $provider)) {
            return true;
        }
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

    /**
     * Ollama na loopback/privátní adrese nepředává data žádnému zpracovateli, takže
     * DPA potvrzení nedává smysl. Region počítá guard z resolvované IP (fail-closed
     * 'us'), ne deklarace firmy.
     */
    public function isExempt(int $supplierId, string $provider): bool
    {
        return $provider === 'ollama'
            && $this->ollama->capabilities($supplierId)->dataRegion === 'eu';
    }

    /** @return array<string,string|null> */
    public function confirmations(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT ai_dpa_confirmations FROM supplier WHERE id = ?');
        $stmt->execute([$supplierId]);
        $items = json_decode((string) ($stmt->fetchColumn() ?: '{}'), true);
        $out = [];
        foreach (LlmProviderRegistry::PROVIDERS as $provider) {
            $out[$provider] = is_array($items) && is_string($items[$provider]['confirmed_at'] ?? null)
                ? (string) $items[$provider]['confirmed_at'] : null;
        }
        return $out;
    }
}
