<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Accounting\Dimension;

use MyInvoice\Service\Accounting\Dimension\DimensionAccountMask;
use MyInvoice\Service\Accounting\Dimension\DimensionAccountRouter;
use PHPUnit\Framework\TestCase;

/**
 * Čisté jádro účtotvorné dimenze: přepis syntetiky na analytiku, dělení rozpadu na
 * haléř, zachování ostatních dimenzí a přerazítkování bez změny účtu.
 */
final class DimensionAccountRouterTest extends TestCase
{
    private const TYPE = 7;
    private const PROJECT = 8;
    private const FVE = 71;
    private const OFFICE = 72;
    private const OTHER = 73;

    /** @var array<int,array<string,mixed>> */
    private array $accounts = [
        518 => ['code' => '518', 'parent_id' => null, 'account_type' => 'expense'],
        5181 => ['code' => '518.100', 'parent_id' => 518, 'account_type' => 'expense'],
        5182 => ['code' => '518.200', 'parent_id' => 518, 'account_type' => 'expense'],
        321 => ['code' => '321', 'parent_id' => null, 'account_type' => 'liability'],
        602 => ['code' => '602', 'parent_id' => null, 'account_type' => 'revenue'],
    ];

    /** @var array<int,array<int,int>> */
    private array $map = [518 => [self::FVE => 5181, self::OFFICE => 5182], 321 => [self::FVE => 5181]];

    public function testSingleValueRoutesToAnalytic(): void
    {
        $out = $this->route([['account_id' => 518, 'side' => 'debit', 'amount' => 100.0, 'dimensions' => [self::TYPE => self::FVE]]]);
        self::assertSame(5181, $out[0]['account_id']);
    }

    public function testUnmappedValueAndMissingValueStayOnSynthetic(): void
    {
        $out = $this->route([
            ['account_id' => 518, 'side' => 'debit', 'amount' => 1.0, 'dimensions' => [self::TYPE => self::OTHER]],
            ['account_id' => 518, 'side' => 'debit', 'amount' => 1.0],
        ]);
        self::assertSame([518, 518], array_column($out, 'account_id'));
    }

    public function testBalanceSheetAccountIsNeverRouted(): void
    {
        $out = $this->route([['account_id' => 321, 'side' => 'credit', 'amount' => 5.0, 'dimensions' => [self::TYPE => self::FVE]]]);
        self::assertSame(321, $out[0]['account_id'], 'Rozvahový účet mimo masku 5, 6 zůstává.');
    }

    public function testSplitIsDividedToTheHalerAndKeepsOtherDimensions(): void
    {
        $out = $this->route([[
            'account_id' => 518, 'side' => 'debit', 'amount' => 0.03,
            'dimensions' => [self::PROJECT => 90],
            'dimension_splits' => [self::TYPE => [self::FVE => 0.5, self::OFFICE => 0.5], 99 => [1 => 0.3, 2 => 0.7]],
        ]]);
        self::assertCount(2, $out);
        self::assertSame(3, (int) round(($out[0]['amount'] + $out[1]['amount']) * 100));
        foreach ($out as $part) {
            self::assertSame($part['account_id'] === 5181 ? self::FVE : self::OFFICE, $part['dimensions'][self::TYPE]);
            self::assertSame(90, $part['dimensions'][self::PROJECT]);
            self::assertSame([99 => [1 => 0.3, 2 => 0.7]], $part['dimension_splits'], 'Rozpad jiného typu zůstává.');
        }
    }

    public function testSplitValuesSharingTargetStayAsRenormalizedSplit(): void
    {
        $out = $this->route([[
            'account_id' => 518, 'side' => 'debit', 'amount' => 100.0,
            'dimension_splits' => [self::TYPE => [self::FVE => 0.5, self::OTHER => 0.25, 72 => 0.25]],
        ]]);
        $bySynthetic = array_values(array_filter($out, static fn (array $l): bool => $l['account_id'] === 518));
        self::assertCount(1, $bySynthetic);
        self::assertEqualsWithDelta(25.0, $bySynthetic[0]['amount'], 0.001);
        self::assertSame([self::TYPE => self::OTHER], $bySynthetic[0]['dimensions']);
    }

    public function testForeignCurrencyLineIsNotSplit(): void
    {
        $line = ['account_id' => 518, 'side' => 'debit', 'amount' => 10.0, 'currency_code' => 'EUR',
            'dimension_splits' => [self::TYPE => [self::FVE => 0.5, self::OFFICE => 0.5]]];
        self::assertSame([$line], $this->route([$line]));
    }

    public function testRedirectedSingleAnalyticIsTreatedAsSynthetic(): void
    {
        $accounts = $this->accounts + [5189 => ['code' => '518.900', 'parent_id' => 518, 'account_type' => 'expense']];
        $out = DimensionAccountRouter::route(
            [['account_id' => 5189, 'side' => 'debit', 'amount' => 1.0, 'dimensions' => [self::TYPE => self::FVE]]],
            self::TYPE,
            DimensionAccountMask::parse('5, 6'),
            $accounts,
            $this->map,
            [5189 => 518],
        );
        self::assertSame(5181, $out[0]['account_id'], 'Explicitní mapa má přednost před přesměrem na jedinou analytiku.');
    }

    public function testProjectDetectsAccountChangeAndAcceptsMappedParts(): void
    {
        $mask = DimensionAccountMask::parse('5, 6');
        $same = DimensionAccountRouter::project([
            ['id' => 1, 'account_id' => 5181, 'side' => 'debit', 'amount' => 60.0, 'dimension_splits' => [self::TYPE => [self::FVE => 0.6, self::OFFICE => 0.4]]],
            ['id' => 2, 'account_id' => 5182, 'side' => 'debit', 'amount' => 40.0, 'dimension_splits' => [self::TYPE => [self::FVE => 0.6, self::OFFICE => 0.4]]],
        ], self::TYPE, $mask, $this->accounts, $this->map);
        self::assertSame([], $same['conflicts']);
        self::assertSame([self::TYPE => self::FVE], $same['lines'][0]['dimensions']);
        self::assertArrayNotHasKey('dimension_splits', $same['lines'][0]);

        $moved = DimensionAccountRouter::project(
            [['id' => 3, 'account_id' => 5181, 'side' => 'debit', 'amount' => 10.0, 'dimensions' => [self::TYPE => self::OFFICE]]],
            self::TYPE,
            $mask,
            $this->accounts,
            $this->map,
        );
        self::assertSame([3], $moved['conflicts']);

        $shares = DimensionAccountRouter::project([
            ['id' => 4, 'account_id' => 5181, 'side' => 'debit', 'amount' => 60.0, 'dimension_splits' => [self::TYPE => [self::FVE => 0.5, self::OFFICE => 0.5]]],
            ['id' => 5, 'account_id' => 5182, 'side' => 'debit', 'amount' => 40.0, 'dimension_splits' => [self::TYPE => [self::FVE => 0.5, self::OFFICE => 0.5]]],
        ], self::TYPE, $mask, $this->accounts, $this->map);
        self::assertSame([4, 5], $shares['conflicts'], 'Změna poměru rozpadu mění částky analytik.');
    }

    public function testTargetsReturnSharesPerAccount(): void
    {
        $targets = DimensionAccountRouter::targets(
            ['account_id' => 518, 'dimension_splits' => [self::TYPE => [self::FVE => 0.6, self::OFFICE => 0.4]]],
            self::TYPE,
            DimensionAccountMask::parse('5, 6'),
            $this->accounts,
            $this->map,
        );
        self::assertEqualsWithDelta([5181 => 0.6, 5182 => 0.4], $targets, 1e-9);
    }

    /**
     * @param list<array<string,mixed>> $lines
     * @return list<array<string,mixed>>
     */
    private function route(array $lines): array
    {
        return DimensionAccountRouter::route($lines, self::TYPE, DimensionAccountMask::parse('5, 6'), $this->accounts, $this->map);
    }
}
