<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Bank;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Bank\Match\CounterpartyMapService;
use MyInvoice\Service\Bank\Match\MatchCandidateProvider;
use MyInvoice\Service\Bank\Match\MatchScorer;
use MyInvoice\Service\Bank\Match\SubsetSumSolver;
use PHPUnit\Framework\TestCase;

final class MatchCandidateProviderTest extends TestCase
{
    private MatchCandidateProvider $provider;

    protected function setUp(): void
    {
        $this->provider = new MatchCandidateProvider(
            $this->createStub(Connection::class),
            $this->createStub(CounterpartyMapService::class),
            new SubsetSumSolver(),
            new MatchScorer(),
        );
    }

    public function testAmountMismatchCannotBecomeDeterministicSingle(): void
    {
        $candidate = $this->invoke('finalize', [[
            'type' => 'invoice',
            'signals' => [
                'vs_exact' => MatchScorer::W_VS_EXACT,
                'invoice_no_in_message' => MatchScorer::W_INVOICE_NO_IN_MSG,
                'known_account' => MatchScorer::W_KNOWN_ACCOUNT,
            ],
            'flags' => [],
        ]]);

        self::assertSame(0.85, $candidate['score']);
        self::assertFalse($candidate['deterministic_core']);
        self::assertSame('suggest', (new MatchScorer())->decide([$candidate]));
    }

    public function testMultipleExactSubsetsAreNeverDeterministic(): void
    {
        $base = [];
        foreach ([[1, 600.0], [2, 400.0], [3, 700.0], [4, 300.0]] as [$id, $amount]) {
            $base[] = [
                'type' => 'invoice', 'invoice_id' => $id, 'invoice_ids' => null,
                'purchase_invoice_id' => null, 'signals' => ['amount_remaining' => 0.3],
                'flags' => [], 'fee_amount' => null, 'overpayment_amount' => null,
                'display' => ['ref' => (string) $id, 'party' => 'Test', 'amount' => $amount,
                    'currency' => 'CZK', 'due_date' => '2099-01-01', 'paid' => false],
                '_client_id' => 7, '_converted' => $amount, '_remaining' => $amount,
                '_ref_digits' => (string) $id, '_date_distance' => 0, '_promoted' => false,
            ];
        }

        $candidates = $this->invoke('splitCandidates', [
            $base,
            ['amount' => 1000.0, 'variable_symbol' => null],
            1000.0,
            'CZK',
            null,
        ]);
        $exact = array_values(array_filter($candidates, static fn (array $candidate): bool => $candidate['fee_amount'] === null));

        self::assertGreaterThan(1, count($exact));
        foreach ($exact as $candidate) self::assertFalse($candidate['deterministic_core']);
    }

    /** @param list<mixed> $args */
    private function invoke(string $method, array $args): mixed
    {
        $reflection = new \ReflectionMethod($this->provider, $method);
        return $reflection->invokeArgs($this->provider, $args);
    }
}
