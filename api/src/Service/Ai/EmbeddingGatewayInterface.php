<?php

declare(strict_types=1);

namespace MyInvoice\Service\Ai;

interface EmbeddingGatewayInterface
{
    /** @param list<string> $texts @return array<string,mixed> */
    public function embed(int $supplierId, array $texts): array;

    public function isAvailable(int $supplierId): bool;
}
