<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank\Match;

final class SubsetSumSolver
{
    /**
     * @param list<array<string,mixed>> $items
     * @return list<list<array<string,mixed>>>
     */
    public function findSubsets(array $items, float $target, float $tol, int $minSize, int $maxSize, int $limit = 30): array
    {
        if ($target <= -$tol || $maxSize < $minSize || $minSize < 1 || $limit < 1) {
            return [];
        }
        $items = array_values(array_filter($items, static fn (array $item): bool => (float) ($item['converted'] ?? 0.0) > 0.0));
        usort($items, static fn (array $a, array $b): int => (float) $b['converted'] <=> (float) $a['converted']);
        $n = count($items);
        $results = [];
        $dfs = function (int $start, array $picked, float $sum) use (&$dfs, &$results, $items, $n, $target, $tol, $minSize, $maxSize, $limit): void {
            if (count($results) >= $limit || $sum > $target + $tol) return;
            if (count($picked) >= $minSize && abs($sum - $target) <= $tol) {
                $results[] = $picked;
                return;
            }
            if (count($picked) >= $maxSize) return;
            for ($i = $start; $i < $n; $i++) {
                $next = $picked;
                $next[] = $items[$i];
                $dfs($i + 1, $next, $sum + (float) $items[$i]['converted']);
                if (count($results) >= $limit) return;
            }
        };
        $dfs(0, [], 0.0);
        return $results;
    }
}
