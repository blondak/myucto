<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Bank;

use MyInvoice\Service\Bank\Match\SubsetSumSolver;
use PHPUnit\Framework\TestCase;

final class SubsetSumSolverTest extends TestCase
{
    public function testFindsBoundedExactCombination(): void
    {
        $solver = new SubsetSumSolver();
        $items = [
            ['id' => 1, 'converted' => 700.0],
            ['id' => 2, 'converted' => 300.0],
            ['id' => 3, 'converted' => 250.0],
        ];

        $result = $solver->findSubsets($items, 1000.0, 1.0, 2, 3);

        self::assertCount(1, $result);
        self::assertSame([1, 2], array_column($result[0], 'id'));
    }

    public function testFiltersInvalidAmountsAndHonoursLimit(): void
    {
        $solver = new SubsetSumSolver();
        $items = [
            ['id' => 1, 'converted' => 50.0],
            ['id' => 2, 'converted' => 50.0],
            ['id' => 3, 'converted' => 50.0],
            ['id' => 4, 'converted' => 0.0],
        ];

        self::assertCount(1, $solver->findSubsets($items, 100.0, 0.01, 2, 2, 1));
        self::assertSame([], $solver->findSubsets($items, 100.0, 0.01, 3, 2));
    }
}
