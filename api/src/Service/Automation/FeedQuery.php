<?php

declare(strict_types=1);

namespace MyInvoice\Service\Automation;

final class FeedQuery
{
    /** @param list<int> $suppliers */
    public function __construct(
        public readonly string $tab,
        public readonly array $suppliers = [],
        public readonly ?string $source = null,
        public readonly ?string $operationType = null,
        public readonly ?string $from = null,
        public readonly ?string $to = null,
        public readonly int $page = 1,
        public readonly int $perPage = 50,
        public readonly ?float $minConfidence = null,
        public readonly ?float $maxConfidence = null,
        public readonly ?float $minAmount = null,
        public readonly ?float $maxAmount = null,
        public readonly string $sort = 'default',
        public readonly string $direction = 'asc',
    ) {}
}
