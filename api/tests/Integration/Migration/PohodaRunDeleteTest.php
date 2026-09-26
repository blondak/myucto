<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Migration;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\PohodaImportRepository;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Smazat jde jen protokol doběhlé zkoušky nanečisto. Protokol ostrého převodu je záznam
 * převzatých dat a běžící zkouška ještě pracuje, ty zůstávají.
 */
#[Group('integration')]
final class PohodaRunDeleteTest extends TestCase
{
    private PDO $pdo;
    private PohodaImportRepository $runs;
    private int $supplierId;
    /** @var list<int> */
    private array $created = [];

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php missing');
        }
        try {
            $container = Bootstrap::buildContainer();
            $this->pdo = $container->get(Connection::class)->pdo();
            $this->runs = $container->get(PohodaImportRepository::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI unavailable: ' . $e->getMessage());
        }
        $this->supplierId = (int) ($this->pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($this->supplierId === 0) {
            $this->markTestSkipped('Chybí dodavatel.');
        }
    }

    protected function tearDown(): void
    {
        if ($this->created !== []) {
            $this->pdo->exec('DELETE FROM pohoda_imports WHERE id IN (' . implode(',', $this->created) . ')');
        }
    }

    public function testOnlyFinishedDryRunCanBeDeleted(): void
    {
        $dry = $this->createRun('dry_run', 'failed');
        $live = $this->createRun('import', 'completed');
        $running = $this->createRun('dry_run', 'running');

        self::assertTrue($this->runs->deleteDryRun($dry, $this->supplierId));
        self::assertNull($this->runs->findRun($dry, $this->supplierId));

        self::assertFalse($this->runs->deleteDryRun($live, $this->supplierId), 'Protokol ostrého převodu zůstává.');
        self::assertFalse($this->runs->deleteDryRun($running, $this->supplierId), 'Běžící zkouška zůstává.');
        self::assertFalse($this->runs->deleteDryRun($dry, $this->supplierId + 100000), 'Cizí firma nic nesmaže.');
        self::assertNotNull($this->runs->findRun($live, $this->supplierId));
        self::assertNotNull($this->runs->findRun($running, $this->supplierId));
    }

    private function createRun(string $mode, string $status): int
    {
        $id = $this->runs->startRun($this->supplierId, null, $mode, ['ico' => '12345678', 'year' => 2025], null);
        if ($status !== 'running') {
            $this->runs->finishRun($id, $this->supplierId, $status, ['status' => $status, 'steps' => []]);
        }
        $this->created[] = $id;
        return $id;
    }
}
