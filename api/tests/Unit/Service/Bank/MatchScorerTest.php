<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Bank;

use MyInvoice\Service\Bank\Match\MatchScorer;
use PHPUnit\Framework\TestCase;

final class MatchScorerTest extends TestCase
{
    public function testAutoRequiresScoreCoreAndMargin(): void
    {
        $scorer = new MatchScorer();
        $top = ['score' => 0.90, 'deterministic_core' => true, 'signals' => ['vs_exact' => 0.4], 'flags' => []];

        self::assertSame('auto', $scorer->decide([$top]));
        self::assertSame('suggest', $scorer->decide([$top, ['score' => 0.80]]));
        self::assertSame('suggest', $scorer->decide([array_replace($top, ['deterministic_core' => false])]));
        self::assertSame('none', $scorer->decide([['score' => 0.34, 'deterministic_core' => true]]));
    }

    public function testReviewFlagsBlockDeterministicCore(): void
    {
        $scorer = new MatchScorer();
        foreach (MatchScorer::BLOCKING_FLAGS as $flag) {
            self::assertFalse($scorer->hasDeterministicCore(['vs_exact' => 0.4], [$flag]), $flag);
        }
        self::assertTrue($scorer->hasDeterministicCore(['known_account' => 0.2], []));
        self::assertSame(1.0, $scorer->score(['vs_exact' => 0.4, 'amount_remaining' => 0.3, 'invoice_no_in_message' => 0.25, 'due_proximity' => 0.05]));
    }
}
