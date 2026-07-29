<?php

declare(strict_types=1);

namespace MyInvoice\Service\Ai;

final class EmbeddingGatewayRouter implements EmbeddingGatewayInterface
{
    public function __construct(private readonly AiProviderHttpClient $client) {}

    public function embed(int $supplierId, array $texts): array
    {
        return $this->client->embeddings($supplierId, $texts);
    }

    public function isAvailable(int $supplierId): bool
    {
        return $this->client->isEmbeddingAvailable($supplierId);
    }
}
