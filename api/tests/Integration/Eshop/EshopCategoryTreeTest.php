<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Eshop;

use MyInvoice\Repository\StockCategoryRepository;
use MyInvoice\Service\Eshop\CategoryTreeService;
use MyInvoice\Service\Eshop\EshopException;
use MyInvoice\Tests\Integration\Stock\StockTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Epic ESHOP F1 — strom kategorií (materialized path): insert, move s repath
 * podstromu + korektní depth, subtree bez rekurze, cycle guard.
 */
#[Group('integration')]
final class EshopCategoryTreeTest extends StockTestCase
{
    private CategoryTreeService $tree;
    private StockCategoryRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tree = $this->container->get(CategoryTreeService::class);
        $this->repo = $this->container->get(StockCategoryRepository::class);
    }

    private function mk(int $sid, string $code, ?int $parent): int
    {
        return (int) $this->tree->create($sid, ['code' => $code, 'name' => $code, 'parent_id' => $parent])['id'];
    }

    public function testInsertBuildsPathAndDepth(): void
    {
        $sid = $this->createSupplier();
        $a = $this->mk($sid, 'A', null);
        $b = $this->mk($sid, 'B', $a);
        $c = $this->mk($sid, 'C', $b);

        $ra = $this->repo->find($sid, $a);
        $rb = $this->repo->find($sid, $b);
        $rc = $this->repo->find($sid, $c);

        self::assertSame("/{$a}/", $ra['path']);
        self::assertSame(0, $ra['depth']);
        self::assertSame("/{$a}/{$b}/", $rb['path']);
        self::assertSame(1, $rb['depth']);
        self::assertSame("/{$a}/{$b}/{$c}/", $rc['path']);
        self::assertSame(2, $rc['depth']);
    }

    public function testMoveSubtreeRepathsAndFixesDepth(): void
    {
        $sid = $this->createSupplier();
        $a = $this->mk($sid, 'A', null);
        $b = $this->mk($sid, 'B', $a);
        $c = $this->mk($sid, 'C', $b);  // A>B>C
        $d = $this->mk($sid, 'D', null); // nový kořen

        // Přesuň B (s C) pod D.
        $this->tree->move($sid, $b, $d);

        $rb = $this->repo->find($sid, $b);
        $rc = $this->repo->find($sid, $c);
        self::assertSame("/{$d}/{$b}/", $rb['path']);
        self::assertSame(1, $rb['depth'], 'B pod kořenem D → depth 1 (ne 2 — off-by-one fix)');
        self::assertSame("/{$d}/{$b}/{$c}/", $rc['path']);
        self::assertSame(2, $rc['depth']);

        // Přesuň B na kořen.
        $this->tree->move($sid, $b, null);
        $rb = $this->repo->find($sid, $b);
        $rc = $this->repo->find($sid, $c);
        self::assertSame("/{$b}/", $rb['path']);
        self::assertSame(0, $rb['depth'], 'B na kořen → depth 0');
        self::assertSame("/{$b}/{$c}/", $rc['path']);
        self::assertSame(1, $rc['depth']);
    }

    public function testSubtreeQuery(): void
    {
        $sid = $this->createSupplier();
        $a = $this->mk($sid, 'A', null);
        $b = $this->mk($sid, 'B', $a);
        $this->mk($sid, 'C', $b);
        $this->mk($sid, 'X', null); // mimo podstrom

        $ra = $this->repo->find($sid, $a);
        $subtree = $this->repo->subtree($sid, $ra['path']);
        self::assertCount(3, $subtree, 'A + B + C');
    }

    public function testCycleRejected(): void
    {
        $sid = $this->createSupplier();
        $a = $this->mk($sid, 'A', null);
        $b = $this->mk($sid, 'B', $a);

        $this->expectException(EshopException::class);
        $this->expectExceptionMessageMatches('/podkategorii|sebe/');
        $this->tree->move($sid, $a, $b); // A pod svého potomka B → cyklus
    }
}
