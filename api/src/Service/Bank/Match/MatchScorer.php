<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank\Match;

final class MatchScorer
{
    public const AUTO_SCORE = 0.85;
    public const AUTO_MARGIN = 0.15;
    public const SUGGEST_SCORE = 0.35;

    public const W_VS_EXACT = 0.40;
    public const W_AMOUNT_REMAINING = 0.30;
    public const W_SUBSET_SUM = 0.25;
    public const W_INVOICE_NO_IN_MSG = 0.25;
    public const W_KNOWN_ACCOUNT = 0.20;
    public const W_VS_LEVENSHTEIN = 0.15;
    public const W_NAME_FUZZY = 0.10;
    public const W_DUE_PROXIMITY = 0.05;

    public const SIGNALS = [
        'vs_exact', 'amount_remaining', 'subset_sum', 'invoice_no_in_message',
        'known_account', 'vs_typo', 'name_fuzzy', 'due_proximity',
    ];

    public const BLOCKING_FLAGS = [
        'vs_typo', 'overpayment', 'fee_gap', 'currency_mismatch', 'proforma',
    ];

    /** @param array<string,float|int> $signals */
    public function score(array $signals): float
    {
        return round(min(1.0, max(0.0, array_sum($signals))), 3);
    }

    /** @param array<string,float|int> $signals @param list<string> $flags */
    public function hasDeterministicCore(array $signals, array $flags): bool
    {
        if (array_intersect(self::BLOCKING_FLAGS, $flags) !== []) {
            return false;
        }
        return isset($signals['vs_exact']) || isset($signals['known_account']) || isset($signals['subset_sum']);
    }

    /** @param list<array<string,mixed>> $ranked */
    public function decide(array $ranked): string
    {
        if ($ranked === []) {
            return 'none';
        }
        $top = $ranked[0];
        $topScore = (float) ($top['score'] ?? 0.0);
        $margin = count($ranked) === 1 ? null : $topScore - (float) ($ranked[1]['score'] ?? 0.0);
        $core = array_key_exists('deterministic_core', $top)
            ? (bool) $top['deterministic_core']
            : $this->hasDeterministicCore((array) ($top['signals'] ?? []), (array) ($top['flags'] ?? []));
        if ($topScore >= self::AUTO_SCORE && $core && ($margin === null || $margin >= self::AUTO_MARGIN)) {
            return 'auto';
        }
        return $topScore >= self::SUGGEST_SCORE ? 'suggest' : 'none';
    }
}
