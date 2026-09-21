<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Accounting\Dimension;

use MyInvoice\Service\Accounting\Dimension\DimensionFilter;
use MyInvoice\Service\Accounting\Dimension\DimensionStamper;
use PHPUnit\Framework\TestCase;

/**
 * Jádro rozpadu dimenzí dokladu na řádky deníku ({@see DimensionStamper::assign()}).
 * Účty: 1 = náklad (518), 2 = DPH (343), 3 = závazek (321).
 */
final class DimensionStamperTest extends TestCase
{
    private const TYPES = [1 => 'expense', 2 => 'liability', 3 => 'liability'];
    private const PROJECT = 10;
    private const CENTER = 20;

    public function testHeaderGoesToEveryLine(): void
    {
        $result = DimensionStamper::assign($this->purchaseLines(1000.00), [self::PROJECT => 100], [], self::TYPES, true);
        self::assertFalse($result['needs_split']);
        foreach ($result['lines'] as $line) {
            self::assertSame([self::PROJECT => 100], $line['dimensions']);
        }
    }

    public function testUniformItemOverridesHeaderOnExpenseLineOnly(): void
    {
        $items = [
            ['dims' => [self::PROJECT => 200], 'weight' => 600.0, 'account_id' => null],
            ['dims' => [self::PROJECT => 200], 'weight' => 400.0, 'account_id' => null],
        ];
        $result = DimensionStamper::assign($this->purchaseLines(1000.00), [self::PROJECT => 100, self::CENTER => 5], $items, self::TYPES, true);
        $byAccount = array_column($result['lines'], 'dimensions', 'account_id');
        self::assertSame([self::PROJECT => 200, self::CENTER => 5], $byAccount[1], 'Položka přebíjí hlavičku typ po typu.');
        self::assertSame([self::PROJECT => 100, self::CENTER => 5], $byAccount[3], 'Závazek nese hlavičku.');
    }

    public function testMixedItemsSplitExpenseLineToTheCent(): void
    {
        $items = [
            ['dims' => [self::PROJECT => 1], 'weight' => 1.0, 'account_id' => null],
            ['dims' => [self::PROJECT => 2], 'weight' => 1.0, 'account_id' => null],
            ['dims' => [self::PROJECT => 3], 'weight' => 1.0, 'account_id' => null],
        ];
        $result = DimensionStamper::assign($this->purchaseLines(100.00), [], $items, self::TYPES, true);
        $expense = array_values(array_filter($result['lines'], static fn (array $l): bool => $l['account_id'] === 1));
        self::assertCount(3, $expense);
        self::assertSame(10000, (int) round(array_sum(array_column($expense, 'amount')) * 100), 'Součet dílů = původní řádek.');
        self::assertSame([33.34, 33.33, 33.33], array_column($expense, 'amount'));
        self::assertSame([1, 2, 3], array_map(static fn (array $l): int => $l['dimensions'][self::PROJECT], $expense));
    }

    public function testItemsAreSplitOnlyOnTheirOwnAccount(): void
    {
        $lines = [
            ['account_id' => 1, 'side' => 'debit', 'amount' => 500.00],
            ['account_id' => 4, 'side' => 'debit', 'amount' => 300.00],
            ['account_id' => 3, 'side' => 'credit', 'amount' => 800.00],
        ];
        $items = [
            ['dims' => [self::PROJECT => 1], 'weight' => 500.0, 'account_id' => 1],
            ['dims' => [self::PROJECT => 2], 'weight' => 300.0, 'account_id' => 4],
        ];
        $result = DimensionStamper::assign($lines, [], $items, self::TYPES + [4 => 'expense'], true);
        self::assertCount(3, $result['lines'], 'Každý nákladový účet má jen svou položku — žádné dělení.');
        self::assertSame([self::PROJECT => 1], $result['lines'][0]['dimensions']);
        self::assertSame([self::PROJECT => 2], $result['lines'][1]['dimensions']);
        self::assertArrayNotHasKey('dimensions', $result['lines'][2]);
    }

    public function testRestampDoesNotSplitButKeepsAlreadySplitLines(): void
    {
        $items = [
            ['dims' => [self::PROJECT => 1], 'weight' => 60.0, 'account_id' => null],
            ['dims' => [self::PROJECT => 2], 'weight' => 40.0, 'account_id' => null],
        ];
        $lines = [
            ['id' => 1, 'account_id' => 1, 'side' => 'debit', 'amount' => 60.00, 'current_dimensions' => [self::PROJECT => 1]],
            ['id' => 2, 'account_id' => 1, 'side' => 'debit', 'amount' => 40.00, 'current_dimensions' => [self::PROJECT => 2]],
            ['id' => 3, 'account_id' => 3, 'side' => 'credit', 'amount' => 100.00, 'current_dimensions' => []],
        ];
        $kept = DimensionStamper::assign($lines, [], $items, self::TYPES, false);
        self::assertFalse($kept['needs_split']);
        self::assertSame([self::PROJECT => 2], $kept['lines'][1]['dimensions']);

        $single = [['id' => 1, 'account_id' => 1, 'side' => 'debit', 'amount' => 100.00, 'current_dimensions' => []]];
        $needs = DimensionStamper::assign($single, [self::CENTER => 7], $items, self::TYPES, false);
        self::assertTrue($needs['needs_split'], 'Nerozdělený řádek s různými položkami chce přeúčtování.');
        self::assertCount(1, $needs['lines']);
        self::assertSame([self::CENTER => 7], $needs['lines'][0]['dimensions'], 'Do přeúčtování nese jen hlavičku.');
    }

    public function testExplicitLineDimensionWins(): void
    {
        $lines = [['account_id' => 1, 'side' => 'debit', 'amount' => 10.00, 'dimensions' => [self::PROJECT => 9]]];
        $result = DimensionStamper::assign($lines, [self::PROJECT => 100, self::CENTER => 5], [], self::TYPES, true);
        self::assertSame([self::PROJECT => 9, self::CENTER => 5], $result['lines'][0]['dimensions']);
    }

    public function testNegativeItemWeightsFallBackToHeader(): void
    {
        $items = [
            ['dims' => [self::PROJECT => 1], 'weight' => 120.0, 'account_id' => null],
            ['dims' => [self::PROJECT => 2], 'weight' => -20.0, 'account_id' => null],
        ];
        $result = DimensionStamper::assign($this->purchaseLines(100.00), [self::CENTER => 3], $items, self::TYPES, true);
        self::assertTrue($result['needs_split']);
        self::assertSame([self::CENTER => 3], $result['lines'][0]['dimensions']);
    }

    public function testDistributeCentsKeepsTotal(): void
    {
        self::assertSame([6667, 3333], DimensionStamper::distributeCents(10000, [2.0, 1.0]));
        self::assertSame([-50, -50], DimensionStamper::distributeCents(-100, [1.0, 1.0]));
    }

    public function testFilterSqlCountsCostCentreTextOnlyWithoutDimension(): void
    {
        $filter = new DimensionFilter(5, 11, [11, 12], ['REZ']);
        [$sql, $params] = $filter->sql('l');
        self::assertStringContainsString('dim_f.dimension_value_id IN (?,?)', $sql);
        self::assertStringContainsString('l.cost_center IN (?)', $sql);
        self::assertStringContainsString('NOT EXISTS', $sql, 'Řádek s dimenzí téhož typu rozhoduje dimenzí, ne textem.');
        self::assertSame([5, 11, 12, 'REZ', 5], $params);
    }

    /** @return list<array<string,mixed>> 518 MD / 343 MD / 321 D */
    private function purchaseLines(float $base): array
    {
        return [
            ['account_id' => 1, 'side' => 'debit', 'amount' => $base],
            ['account_id' => 2, 'side' => 'debit', 'amount' => round($base * 0.21, 2)],
            ['account_id' => 3, 'side' => 'credit', 'amount' => round($base * 1.21, 2)],
        ];
    }
}
