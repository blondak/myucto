<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Accounting;

use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Service\Accounting\UnbalancedEntryException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Jednotkové testy podvojnosti (Epic F1) — pure, bez DB. Ověřují, že se rovnováha
 * Σ MD = Σ D počítá v HALÉŘÍCH (int), nikoli přes float ==, a že nevyváženost
 * vyhodí UnbalancedEntryException.
 */
#[Group('unit')]
final class PostingServiceBalanceTest extends TestCase
{
    public function testBalancedSimpleEntryPasses(): void
    {
        $lines = [
            ['account_code' => '311', 'side' => 'debit',  'amount' => 1210.00],
            ['account_code' => '602', 'side' => 'credit', 'amount' => 1000.00],
            ['account_code' => '343', 'side' => 'credit', 'amount' => 210.00],
        ];
        PostingService::assertBalanced($lines);

        $b = PostingService::balanceCents($lines);
        self::assertSame(121000, $b['debit']);
        self::assertSame(121000, $b['credit']);
    }

    public function testUnbalancedEntryThrows(): void
    {
        $lines = [
            ['account_code' => '311', 'side' => 'debit',  'amount' => 1210.00],
            ['account_code' => '602', 'side' => 'credit', 'amount' => 1000.00],
            ['account_code' => '343', 'side' => 'credit', 'amount' => 209.99], // o haléř míň
        ];
        try {
            PostingService::assertBalanced($lines);
            self::fail('Nevyvážený zápis měl vyhodit UnbalancedEntryException.');
        } catch (UnbalancedEntryException $e) {
            self::assertSame(121000, $e->debitCents);
            self::assertSame(120999, $e->creditCents);
        }
    }

    /**
     * 3-cestný rozpad DPH, kde součet řádkových daní po zaokrouhlení "neplave":
     * porovnání v haléřích musí sednout, i když by float součet mohl driftovat.
     */
    public function testThreeWayRoundingSplitBalancesInCents(): void
    {
        // Základy: 33.33 + 33.33 + 33.34 = 100.00; daň 21 % po řádcích zaokr. na 2 des.
        $lines = [
            ['account_code' => '518', 'side' => 'debit',  'amount' => 33.33],
            ['account_code' => '518', 'side' => 'debit',  'amount' => 33.33],
            ['account_code' => '518', 'side' => 'debit',  'amount' => 33.34],
            ['account_code' => '343', 'side' => 'debit',  'amount' => 7.00],  // 21 % ze 100 / 3 zaokrouhleno
            ['account_code' => '343', 'side' => 'debit',  'amount' => 7.00],
            ['account_code' => '343', 'side' => 'debit',  'amount' => 7.00],
            ['account_code' => '321', 'side' => 'credit', 'amount' => 121.00],
        ];
        PostingService::assertBalanced($lines);
        $b = PostingService::balanceCents($lines);
        self::assertSame($b['debit'], $b['credit']);
        self::assertSame(12100, $b['debit']);
    }

    /**
     * Klíčový důkaz, že se neporovnává přes float ==: 0.1 + 0.2 (= 0.30000000000004
     * ve float) proti 0.30 by přes == selhalo, v haléřích sedí.
     */
    public function testFloatEqualityPitfallHandledByCents(): void
    {
        self::assertNotSame(0.1 + 0.2, 0.3, 'Sanity: 0.1+0.2 != 0.3 ve float.');

        $lines = [
            ['account_code' => '211', 'side' => 'debit',  'amount' => 0.1],
            ['account_code' => '211', 'side' => 'debit',  'amount' => 0.2],
            ['account_code' => '668', 'side' => 'credit', 'amount' => 0.3],
        ];
        PostingService::assertBalanced($lines); // nesmí vyhodit
        $b = PostingService::balanceCents($lines);
        self::assertSame(30, $b['debit']);
        self::assertSame(30, $b['credit']);
    }
}
