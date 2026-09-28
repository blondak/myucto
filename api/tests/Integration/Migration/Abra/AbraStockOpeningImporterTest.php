<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Migration\Abra;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AbraImportRepository;
use MyInvoice\Service\Migration\Abra\AbraException;
use MyInvoice\Service\Migration\Abra\AbraSource;
use MyInvoice\Service\Migration\Abra\AbraStockOpeningImporter;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class AbraStockOpeningImporterTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private int $supplierId;
    private int $userId;
    private int $warehouseId;
    private int $itemId;

    protected function setUp(): void
    {
        $container = Bootstrap::buildContainer();
        $this->db = $container->get(Connection::class);
        $pdo = $this->db->pdo();
        self::assertTrue(str_ends_with((string) $pdo->query('SELECT DATABASE()')->fetchColumn(), '_test'));
        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, (int) $pdo->query('SELECT MIN(id) FROM supplier')->fetchColumn());
        $this->userId = (int) $pdo->query('SELECT MIN(id) FROM users')->fetchColumn();
        $pdo->prepare('UPDATE supplier SET stock_enabled=1 WHERE id=?')->execute([$this->supplierId]);
        $pdo->prepare("INSERT INTO warehouses (supplier_id, code, name, is_active)
            VALUES (?, 'SYN', 'Synthetic warehouse', 1)")->execute([$this->supplierId]);
        $this->warehouseId = (int) $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO stock_items
            (supplier_id, sku, name, item_type, unit, is_stocked, tracking_mode, is_active)
            VALUES (?, 'SYN-ITEM', 'Synthetic item', 'goods', 'ks', 1, 'none', 1)")
            ->execute([$this->supplierId]);
        $this->itemId = (int) $pdo->lastInsertId();
        $container->get(AbraImportRepository::class)->remember($this->supplierId, 'cenik', '2',
            AbraSource::hash(['synthetic' => true]), 'stock_item', $this->itemId, 2026);
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            if ($this->db->pdo()->inTransaction()) $this->db->pdo()->rollBack();
            $this->db->close();
        }
    }

    public function testExistingQuantityCannotBeCountedTwiceAsOpeningStock(): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare('INSERT INTO stock_levels (supplier_id, warehouse_id, stock_item_id, qty, value_total)
            VALUES (?, ?, ?, 10, 100)')->execute([$this->supplierId, $this->warehouseId, $this->itemId]);
        $row = ['id' => '1', 'cenik@ref' => '/c/demo/cenik/2.json',
            'sklad@ref' => '/c/demo/sklad/3.json', 'stavMJ' => '10', 'stavTuz' => '100'];
        try {
            Bootstrap::buildContainer()->get(AbraStockOpeningImporter::class)
                ->importPage($this->supplierId, $this->userId, [$row], static fn (): bool => false);
            self::fail('Existing stock must block another opening receipt.');
        } catch (AbraException $e) {
            self::assertSame('stock_target_not_empty', $e->errorCode);
        }
        $levels = $pdo->prepare('SELECT qty, value_total FROM stock_levels
            WHERE supplier_id=? AND warehouse_id=? AND stock_item_id=?');
        $levels->execute([$this->supplierId, $this->warehouseId, $this->itemId]);
        self::assertSame(['qty' => '10.000', 'value_total' => '100.00'], $levels->fetch(\PDO::FETCH_ASSOC));
        $docs = $pdo->prepare('SELECT COUNT(*) FROM stock_documents WHERE supplier_id=?');
        $docs->execute([$this->supplierId]);
        self::assertSame(0, (int) $docs->fetchColumn());
    }

    public function testMultipleSourceCardsForOneProductAddOnlyTheirOwnQuantities(): void
    {
        $importer = Bootstrap::buildContainer()->get(AbraStockOpeningImporter::class);
        $first = ['id' => '1', 'cenik@ref' => '/c/demo/cenik/2.json',
            'sklad@ref' => '/c/demo/sklad/3.json', 'stavMJ' => '6', 'stavTuz' => '60'];
        $second = $first;
        $second['id'] = '2';
        $second['stavMJ'] = '4';
        $second['stavTuz'] = '40';

        $firstResult = $importer->importPage($this->supplierId, $this->userId, [$first], static fn (): bool => false);
        $secondResult = $importer->importPage($this->supplierId, $this->userId, [$second], static fn (): bool => false);

        self::assertSame(1, $firstResult['created']);
        self::assertSame(1, $secondResult['created']);
        $level = $this->db->pdo()->prepare('SELECT qty, value_total FROM stock_levels
            WHERE supplier_id=? AND warehouse_id=? AND stock_item_id=?');
        $level->execute([$this->supplierId, $this->warehouseId, $this->itemId]);
        self::assertSame(['qty' => '10.000', 'value_total' => '100.00'], $level->fetch(\PDO::FETCH_ASSOC));
    }
}
