<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup\Company;

use MyInvoice\Service\Backup\Company\CompanyBackupPostImportException;
use MyInvoice\Service\Backup\Company\CompanyBackupPostImportMaterializerRegistry;
use MyInvoice\Service\Backup\Company\CompanyBackupStockCategoriesProjection;
use MyInvoice\Service\Backup\Registry\TenantDataDefinition;
use MyInvoice\Service\Backup\Registry\TenantDataObjectKind;
use MyInvoice\Service\Backup\Registry\TenantDataPolicy;
use MyInvoice\Service\Backup\Registry\TenantDataRegistry;
use MyInvoice\Service\Backup\Registry\TenantDataRegistrySnapshot;
use PDO;
use PHPUnit\Framework\TestCase;

final class CompanyBackupStockCategoryTreeMaterializerTest extends TestCase
{
    private PDO $database;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite není dostupné pro materializační test.');
        }
        $this->database = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ]);
        $this->database->exec(
            'CREATE TABLE stock_categories ('
                . 'id INTEGER PRIMARY KEY, supplier_id INTEGER NOT NULL,'
                . 'parent_id INTEGER NULL, path TEXT NOT NULL DEFAULT \'/\','
                . 'depth INTEGER NOT NULL DEFAULT 0, updated_at TEXT NOT NULL)',
        );
    }

    public function testProductionRegistryRebuildsRemappedTreeForOneTenant(): void
    {
        $this->insert(30, 7, null, '/', 0);
        $this->insert(50, 7, 30, '/', 0);
        $this->insert(70, 7, 50, '/', 0);
        $this->insert(90, 8, null, '/90/', 0);
        self::assertTrue($this->database->beginTransaction());

        $updated = CompanyBackupPostImportMaterializerRegistry::production()
            ->materialize($this->database, 7, $this->snapshot());

        self::assertSame(3, $updated);
        self::assertSame(
            [
                ['id' => 30, 'path' => '/30/', 'depth' => 0],
                ['id' => 50, 'path' => '/30/50/', 'depth' => 1],
                ['id' => 70, 'path' => '/30/50/70/', 'depth' => 2],
            ],
            $this->rows(7),
        );
        self::assertSame(
            [['id' => 90, 'path' => '/90/', 'depth' => 0]],
            $this->rows(8),
        );
        self::assertTrue($this->database->inTransaction());
        self::assertTrue($this->database->rollBack());
    }

    public function testRejectsCycleBeforeChangingAnyPath(): void
    {
        $this->insert(30, 7, 50, '/', 0);
        $this->insert(50, 7, 30, '/', 0);
        self::assertTrue($this->database->beginTransaction());

        try {
            CompanyBackupPostImportMaterializerRegistry::production()
                ->materialize($this->database, 7, $this->snapshot());
            self::fail('Cyklický strom kategorií nesmí být materializován.');
        } catch (CompanyBackupPostImportException $e) {
            self::assertSame('post_import_stock_category_cycle', $e->errorCode);
            self::assertSame('table:stock_categories', $e->registryKey);
        }

        self::assertSame(
            [
                ['id' => 30, 'path' => '/', 'depth' => 0],
                ['id' => 50, 'path' => '/', 'depth' => 0],
            ],
            $this->rows(7),
        );
        self::assertTrue($this->database->inTransaction());
        self::assertTrue($this->database->rollBack());
    }

    public function testRejectsParentOutsideRestoredTenant(): void
    {
        $this->insert(30, 7, 90, '/', 0);
        $this->insert(90, 8, null, '/90/', 0);
        self::assertTrue($this->database->beginTransaction());

        try {
            CompanyBackupPostImportMaterializerRegistry::production()
                ->materialize($this->database, 7, $this->snapshot());
            self::fail('Cizí rodič nesmí projít materializací stromu.');
        } catch (CompanyBackupPostImportException $e) {
            self::assertSame(
                'post_import_stock_category_parent_invalid',
                $e->errorCode,
            );
            self::assertSame('table:stock_categories', $e->registryKey);
        }

        self::assertSame(
            [['id' => 30, 'path' => '/', 'depth' => 0]],
            $this->rows(7),
        );
        self::assertTrue($this->database->inTransaction());
        self::assertTrue($this->database->rollBack());
    }

    public function testRejectsPathLongerThanDatabaseColumnBeforeWriting(): void
    {
        $parentId = null;
        for ($index = 1; $index <= 13; $index++) {
            $id = 9_000_000_000_000_000_000 + $index;
            $this->insert($id, 7, $parentId, '/', 0);
            $parentId = $id;
        }
        self::assertTrue($this->database->beginTransaction());

        try {
            CompanyBackupPostImportMaterializerRegistry::production()
                ->materialize($this->database, 7, $this->snapshot());
            self::fail('Příliš dlouhá materializovaná cesta nesmí projít.');
        } catch (CompanyBackupPostImportException $e) {
            self::assertSame(
                'post_import_stock_category_path_limit_exceeded',
                $e->errorCode,
            );
        }

        self::assertSame(
            array_fill(0, 13, '/'),
            array_column($this->rows(7), 'path'),
        );
        self::assertTrue($this->database->inTransaction());
        self::assertTrue($this->database->rollBack());
    }

    public function testSkipsCategoryTableMissingFromRegistry(): void
    {
        self::assertTrue($this->database->beginTransaction());

        $updated = CompanyBackupPostImportMaterializerRegistry::production()
            ->materialize($this->database, 7, $this->snapshot(false));

        self::assertSame(0, $updated);
        self::assertTrue($this->database->inTransaction());
        self::assertTrue($this->database->rollBack());
    }

    private function insert(
        int $id,
        int $supplierId,
        ?int $parentId,
        string $path,
        int $depth,
    ): void {
        $statement = $this->database->prepare(
            'INSERT INTO stock_categories'
                . ' (id, supplier_id, parent_id, path, depth, updated_at)'
                . " VALUES (?, ?, ?, ?, ?, '2026-01-02 03:04:05')",
        );
        self::assertNotFalse($statement);
        self::assertTrue($statement->execute([
            $id,
            $supplierId,
            $parentId,
            $path,
            $depth,
        ]));
    }

    /** @return list<array{id:int,path:string,depth:int}> */
    private function rows(int $supplierId): array
    {
        $statement = $this->database->prepare(
            'SELECT id, path, depth FROM stock_categories'
                . ' WHERE supplier_id = ? ORDER BY id',
        );
        self::assertNotFalse($statement);
        self::assertTrue($statement->execute([$supplierId]));
        $rows = $statement->fetchAll();
        self::assertIsArray($rows);
        return array_map(
            static fn (array $row): array => [
                'id' => (int) $row['id'],
                'path' => (string) $row['path'],
                'depth' => (int) $row['depth'],
            ],
            $rows,
        );
    }

    private function snapshot(
        bool $includeCategories = true,
    ): TenantDataRegistrySnapshot {
        $profile = TenantDataRegistry::COMPANY_BACKUP_PROFILE;
        $definitions = [
            new TenantDataDefinition(
                'table:supplier',
                TenantDataObjectKind::Table,
                TenantDataPolicy::TenantRoot,
                [$profile],
                [
                    'primary_key' => ['id'],
                    'ownership' => [
                        'strategy' => 'selected_supplier',
                        'column' => 'id',
                    ],
                    'secrets' => [],
                ],
            ),
        ];
        if ($includeCategories) {
            $definitions[] = new TenantDataDefinition(
                'table:stock_categories',
                TenantDataObjectKind::Table,
                TenantDataPolicy::TenantOwned,
                [$profile],
                [
                    'primary_key' => ['id'],
                    'ownership' => [
                        'strategy' => 'supplier_id',
                        'column' => 'supplier_id',
                    ],
                    'secrets' => [],
                    'company_backup' => [
                        'data_columns' =>
                            CompanyBackupStockCategoriesProjection::dataColumns(),
                        'embedded_references' => [],
                        'generated_columns' => [],
                        'omit_columns' =>
                            CompanyBackupStockCategoriesProjection::omitColumns(),
                        'references' =>
                            CompanyBackupStockCategoriesProjection::references(),
                        'restore_overrides' => [],
                    ],
                ],
            );
        }
        return TenantDataRegistrySnapshot::fromRegistry(
            new TenantDataRegistry(1, $definitions, [$profile]),
            $profile,
        );
    }
}
