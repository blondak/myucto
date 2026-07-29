<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank\Match;

final class MatchingStatsProvider
{
    public function __construct(private readonly MatchMetricsService $metrics) {}

    /** @return array{matching:array<string,mixed>} */
    public function stats(int $supplierId, string $from, string $to): array
    {
        return ['matching' => $this->metrics->metrics($supplierId, $from, $to)];
    }
}
