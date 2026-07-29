<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\CostCenterRepository;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class CostCenterTest extends TestCase
{
    private Connection $db;
    private CostCenterRepository $costCenters;
    private int $supplierId;
    private bool $inTx = false;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $container = Bootstrap::buildApp()->getContainer();
            $this->db = $container->get(Connection::class);
            $this->costCenters = $container->get(CostCenterRepository::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }

        $this->supplierId = (int) ($this->db->pdo()->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($this->supplierId === 0) {
            $this->markTestSkipped('Chybí firma pro integrační test.');
        }
        $this->db->pdo()->beginTransaction();
        $this->inTx = true;
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->inTx) {
            if ($this->db->pdo()->inTransaction()) {
                $this->db->pdo()->rollBack();
            }
            $this->db->close();
        }
    }

    public function testCrudAndActiveFilteringAreSupplierScoped(): void
    {
        $code = 'TEST-' . bin2hex(random_bytes(4));
        $id = $this->costCenters->create($this->supplierId, $code, 'Testovací středisko');

        $created = $this->costCenters->find($this->supplierId, $id);
        self::assertNotNull($created);
        self::assertSame($code, $created['code']);
        self::assertTrue($created['is_active']);
        self::assertNull($this->costCenters->find($this->supplierId + 1000000, $id));

        self::assertTrue($this->costCenters->update($this->supplierId, $id, [
            'name' => 'Přejmenované středisko',
            'is_active' => false,
        ]));
        self::assertSame('Přejmenované středisko', $this->costCenters->find($this->supplierId, $id)['name']);
        self::assertNotContains($code, array_column($this->costCenters->listForSupplier($this->supplierId), 'code'));
        self::assertContains($code, array_column($this->costCenters->listForSupplier($this->supplierId, true), 'code'));
    }

    public function testUsageInTemplatePreventsHardRemovalDecision(): void
    {
        $code = 'USED-' . bin2hex(random_bytes(4));
        $id = $this->costCenters->create($this->supplierId, $code, 'Použité středisko');

        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO journal_entry_templates (supplier_id, name, is_seeded) VALUES (?, ?, 0)'
        )->execute([$this->supplierId, 'Test střediska']);
        $templateId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            "INSERT INTO journal_entry_template_lines
                (template_id, line_no, account_code, side, default_amount, cost_center)
             VALUES (?, 1, '518', 'debit', NULL, ?)"
        )->execute([$templateId, $code]);

        self::assertTrue($this->costCenters->hasUsage($this->supplierId, $code));
        self::assertTrue($this->costCenters->deactivate($this->supplierId, $id));
        self::assertFalse($this->costCenters->find($this->supplierId, $id)['is_active']);
    }
}
