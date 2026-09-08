<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup\Company;

use MyInvoice\Service\Backup\Company\CompanyBackupPostImportException;
use MyInvoice\Service\Backup\Company\CompanyBackupStockLevelBalancePostImportInvariant;
use MyInvoice\Service\Backup\Registry\TenantDataDefinition;
use MyInvoice\Service\Backup\Registry\TenantDataObjectKind;
use MyInvoice\Service\Backup\Registry\TenantDataPolicy;
use MyInvoice\Service\Backup\Registry\TenantDataRegistry;
use MyInvoice\Service\Backup\Registry\TenantDataRegistrySnapshot;
use PDO;
use PHPUnit\Framework\TestCase;

final class CompanyBackupStockLevelBalancePostImportInvariantTest extends
    TestCase
{
    private PDO $database;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite není dostupné pro invariantní test.');
        }
        $this->database = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ]);
        $this->database->exec(
            'CREATE TABLE stock_levels ('
                . 'supplier_id INTEGER NOT NULL, warehouse_id INTEGER NOT NULL,'
                . 'stock_item_id INTEGER NOT NULL, qty NUMERIC NOT NULL,'
                . 'value_total NUMERIC NOT NULL, avg_unit_cost NUMERIC NOT NULL,'
                . 'updated_at TEXT NOT NULL,'
                . 'PRIMARY KEY (supplier_id, warehouse_id, stock_item_id))',
        );
        $this->database->exec(
            'CREATE TABLE stock_documents ('
                . 'id INTEGER PRIMARY KEY, supplier_id INTEGER NOT NULL,'
                . 'doc_type TEXT NOT NULL, status TEXT NOT NULL,'
                . 'warehouse_id INTEGER NOT NULL, warehouse_to_id INTEGER NULL)',
        );
        $this->database->exec(
            'CREATE TABLE stock_document_lines ('
                . 'id INTEGER PRIMARY KEY, document_id INTEGER NOT NULL,'
                . 'supplier_id INTEGER NOT NULL, stock_item_id INTEGER NOT NULL,'
                . 'qty NUMERIC NOT NULL, value_total NUMERIC NOT NULL)',
        );
    }

    public function testAcceptsReceiptIssueTransferAndUnusedZeroLevel(): void
    {
        $this->insertDocument(1, 'receipt', 'posted', 11, null, 10, 1_000);
        $this->insertDocument(2, 'issue', 'posted', 11, null, 2, 200);
        $this->insertDocument(3, 'transfer', 'posted', 11, 12, 3, 300);
        $this->insertDocument(4, 'receipt', 'draft', 11, null, 50, 5_000);
        $this->insertLevel(7, 11, 21, 5, 500, 100);
        $this->insertLevel(7, 12, 21, 3, 300, 100);
        $this->insertLevel(7, 13, 21, 0, 0, 0);

        // Cizí tenant smí mít vlastní nekonzistenci bez vlivu na obnovovanou firmu.
        $this->insertLevel(8, 11, 21, 99, 1, 0.01);
        self::assertTrue($this->database->beginTransaction());

        $checks = (new CompanyBackupStockLevelBalancePostImportInvariant())
            ->validate($this->database, 7, $this->snapshot());

        self::assertSame(1, $checks);
        self::assertTrue($this->database->inTransaction());
        self::assertTrue($this->database->rollBack());
    }

    public function testRejectsLevelValueDifferentFromPostedMovements(): void
    {
        $this->insertDocument(1, 'receipt', 'posted', 11, null, 10, 1_000);
        $this->insertLevel(7, 11, 21, 10, 999.99, 99.999);
        self::assertTrue($this->database->beginTransaction());

        try {
            (new CompanyBackupStockLevelBalancePostImportInvariant())->validate(
                $this->database,
                7,
                $this->snapshot(),
            );
            self::fail('Zůstatek odlišný od knihy nesmí být commitnut.');
        } catch (CompanyBackupPostImportException $e) {
            self::assertSame(
                'post_import_stock_levels_mismatch',
                $e->errorCode,
            );
            self::assertSame('table:stock_levels', $e->registryKey);
        }

        self::assertTrue($this->database->inTransaction());
        self::assertTrue($this->database->rollBack());
    }

    public function testRejectsPostedMovementWithoutMaterializedLevel(): void
    {
        $this->insertDocument(1, 'receipt', 'posted', 11, null, 1, 100);
        self::assertTrue($this->database->beginTransaction());

        try {
            (new CompanyBackupStockLevelBalancePostImportInvariant())->validate(
                $this->database,
                7,
                $this->snapshot(),
            );
            self::fail('Nenulový pohyb bez zůstatku nesmí být commitnut.');
        } catch (CompanyBackupPostImportException $e) {
            self::assertSame(
                'post_import_stock_levels_mismatch',
                $e->errorCode,
            );
        }

        self::assertTrue($this->database->inTransaction());
        self::assertTrue($this->database->rollBack());
    }

    public function testRejectsNonZeroLevelWithoutPostedMovements(): void
    {
        $this->insertLevel(7, 11, 21, 1, 100, 100);
        self::assertTrue($this->database->beginTransaction());

        try {
            (new CompanyBackupStockLevelBalancePostImportInvariant())->validate(
                $this->database,
                7,
                $this->snapshot(),
            );
            self::fail('Nenulový zůstatek bez pohybu nesmí být commitnut.');
        } catch (CompanyBackupPostImportException $e) {
            self::assertSame(
                'post_import_stock_levels_mismatch',
                $e->errorCode,
            );
        }

        self::assertTrue($this->database->inTransaction());
        self::assertTrue($this->database->rollBack());
    }

    public function testRejectsAverageCostDifferentFromMaterializedBalance(): void
    {
        $this->insertDocument(1, 'receipt', 'posted', 11, null, 3, 10);
        $this->insertLevel(7, 11, 21, 3, 10, 3.333332);
        self::assertTrue($this->database->beginTransaction());

        try {
            (new CompanyBackupStockLevelBalancePostImportInvariant())->validate(
                $this->database,
                7,
                $this->snapshot(),
            );
            self::fail('Chybná odvozená průměrná cena nesmí být commitnuta.');
        } catch (CompanyBackupPostImportException $e) {
            self::assertSame(
                'post_import_stock_levels_average_invalid',
                $e->errorCode,
            );
            self::assertSame('table:stock_levels', $e->registryKey);
        }

        self::assertTrue($this->database->inTransaction());
        self::assertTrue($this->database->rollBack());
    }

    public function testRejectsPartialStockRegistryBeforeReadingData(): void
    {
        self::assertTrue($this->database->beginTransaction());

        try {
            (new CompanyBackupStockLevelBalancePostImportInvariant())->validate(
                $this->database,
                7,
                $this->snapshot(includeLines: false),
            );
            self::fail('Neúplný skladový kontrakt musí selhat uzavřeně.');
        } catch (CompanyBackupPostImportException $e) {
            self::assertSame(
                'post_import_stock_levels_registry_invalid',
                $e->errorCode,
            );
            self::assertSame('table:stock_levels', $e->registryKey);
        }

        self::assertTrue($this->database->inTransaction());
        self::assertTrue($this->database->rollBack());
    }

    private function insertDocument(
        int $id,
        string $type,
        string $status,
        int $warehouseId,
        ?int $warehouseToId,
        int|float $qty,
        int|float $value,
    ): void {
        $document = $this->database->prepare(
            'INSERT INTO stock_documents'
                . ' (id, supplier_id, doc_type, status, warehouse_id,'
                . ' warehouse_to_id) VALUES (?, 7, ?, ?, ?, ?)',
        );
        $document->execute([
            $id,
            $type,
            $status,
            $warehouseId,
            $warehouseToId,
        ]);
        $line = $this->database->prepare(
            'INSERT INTO stock_document_lines'
                . ' (id, document_id, supplier_id, stock_item_id, qty,'
                . ' value_total) VALUES (?, ?, 7, 21, ?, ?)',
        );
        $line->execute([$id, $id, $qty, $value]);
    }

    private function insertLevel(
        int $supplierId,
        int $warehouseId,
        int $stockItemId,
        int|float $qty,
        int|float $value,
        int|float $average,
    ): void {
        $statement = $this->database->prepare(
            'INSERT INTO stock_levels'
                . ' (supplier_id, warehouse_id, stock_item_id, qty,'
                . ' value_total, avg_unit_cost, updated_at)'
                . " VALUES (?, ?, ?, ?, ?, ?, '2026-01-01 00:00:00')",
        );
        $statement->execute([
            $supplierId,
            $warehouseId,
            $stockItemId,
            $qty,
            $value,
            $average,
        ]);
    }

    private function snapshot(bool $includeLines = true): TenantDataRegistrySnapshot
    {
        $profile = TenantDataRegistry::COMPANY_BACKUP_PROFILE;
        $definitions = [
            $this->definition('table:supplier', TenantDataPolicy::TenantRoot),
            $this->definition(
                'table:stock_levels',
                TenantDataPolicy::TenantOwned,
            ),
            $this->definition(
                'table:stock_documents',
                TenantDataPolicy::TenantOwned,
            ),
        ];
        if ($includeLines) {
            $definitions[] = $this->definition(
                'table:stock_document_lines',
                TenantDataPolicy::TenantOwned,
            );
        }
        return TenantDataRegistrySnapshot::fromRegistry(new TenantDataRegistry(
            1,
            $definitions,
            [$profile],
        ), $profile);
    }

    private function definition(
        string $key,
        TenantDataPolicy $policy,
    ): TenantDataDefinition {
        return new TenantDataDefinition(
            $key,
            TenantDataObjectKind::Table,
            $policy,
            [TenantDataRegistry::COMPANY_BACKUP_PROFILE],
            [
                'primary_key' => ['id'],
                'ownership' => [
                    'strategy' => $policy === TenantDataPolicy::TenantRoot
                        ? 'selected_supplier'
                        : 'supplier_id',
                    'column' => $policy === TenantDataPolicy::TenantRoot
                        ? 'id'
                        : 'supplier_id',
                ],
                'secrets' => [],
            ],
        );
    }
}
