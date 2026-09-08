<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup\Company;

use MyInvoice\Service\Backup\Company\CompanyBackupDataInventory;
use MyInvoice\Service\Backup\Company\CompanyBackupDataObject;
use MyInvoice\Service\Backup\Company\CompanyBackupDeferredColumnSet;
use MyInvoice\Service\Backup\Company\CompanyBackupImportDependencyPlan;
use MyInvoice\Service\Backup\Company\CompanyBackupPreparedDeferredUpdate;
use MyInvoice\Service\Backup\Company\CompanyBackupPreparedImportRow;
use MyInvoice\Service\Backup\Company\CompanyBackupSourceIdentity;
use MyInvoice\Service\Backup\Company\CompanyBackupSourceKey;
use MyInvoice\Service\Backup\Company\CompanyBackupSqlDeferredUpdateWriter;
use MyInvoice\Service\Backup\Company\CompanyBackupSqlInsertWriter;
use MyInvoice\Service\Backup\Company\CompanyBackupTableProjection;
use MyInvoice\Service\Backup\Company\CompanyBackupTableSchema;
use MyInvoice\Service\Backup\Registry\TenantDataDefinition;
use MyInvoice\Service\Backup\Registry\TenantDataObjectKind;
use MyInvoice\Service\Backup\Registry\TenantDataPolicy;
use MyInvoice\Service\Backup\Registry\TenantDataRegistry;
use MyInvoice\Service\Backup\Registry\TenantDataRegistryFactory;
use MyInvoice\Service\Backup\Registry\TenantDataRegistrySnapshot;
use PDO;
use PHPUnit\Framework\TestCase;

/** SQL integrace produkčních projekcí; izolovaná SQLite navíc vynucuje cyklické FK. */
final class CompanyBackupStockCycleSqlTest extends TestCase
{
    public function testTwoPassWritersRestoreInventoryAndReversalCycle(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('Test vyžaduje pdo_sqlite.');
        }
        $db = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $db->exec('PRAGMA foreign_keys = ON');
        $profile = TenantDataRegistry::COMPANY_BACKUP_PROFILE;
        $production = TenantDataRegistryFactory::draftV1();
        $definitions = [];
        foreach (['supplier', 'users', 'warehouses', 'invoices', 'purchase_invoices',
            'purchase_orders', 'journal_entries'] as $table) {
            $policy = match ($table) {
                'supplier' => TenantDataPolicy::TenantRoot,
                'users' => TenantDataPolicy::InstanceOwned,
                default => TenantDataPolicy::TenantOwned,
            };
            $definitions[] = new TenantDataDefinition(
                'table:' . $table, TenantDataObjectKind::Table, $policy, [$profile],
                [
                    'primary_key' => ['id'],
                    'ownership' => ['strategy' => $table === 'supplier' ? 'selected_supplier' : 'supplier_id',
                        'column' => $table === 'supplier' ? 'id' : 'supplier_id'],
                    'secrets' => [],
                    'company_backup' => [
                        'data_columns' => $table === 'supplier' ? ['id'] : ['id', 'supplier_id'],
                        'references' => $table === 'supplier' ? [] : [[
                            'columns' => ['supplier_id'], 'target' => 'table:supplier',
                            'target_columns' => ['id'], 'mapping' => 'tenant_id',
                            'constraint' => 'required', 'nullable_columns' => [], 'fallbacks' => [],
                        ]],
                        'embedded_references' => [],
                        'generated_columns' => [], 'omit_columns' => [], 'restore_overrides' => [],
                    ],
                ],
            );
        }
        $projections = [];
        foreach (['stock_documents', 'stock_takes'] as $table) {
            $definition = $production->definition('table:' . $table);
            self::assertNotNull($definition);
            $definitions[] = $definition;
            $projection = CompanyBackupTableProjection::fromDefinition($definition);
            $projections[$table] = $projection;
            $columns = array_map(
                static fn (string $column): string => '"' . $column . '" '
                    . ($column === 'id' ? 'INTEGER PRIMARY KEY'
                        : (str_ends_with($column, '_id') ? 'INTEGER' : 'TEXT')),
                $projection->dataColumns,
            );
            $constraints = $table === 'stock_documents'
                ? ', FOREIGN KEY(stock_take_id) REFERENCES stock_takes(id),'
                    . ' FOREIGN KEY(reversal_document_id) REFERENCES stock_documents(id)'
                : ', FOREIGN KEY(receipt_document_id) REFERENCES stock_documents(id),'
                    . ' FOREIGN KEY(issue_document_id) REFERENCES stock_documents(id)';
            $db->exec('CREATE TABLE ' . $table . ' (' . implode(', ', $columns) . $constraints . ')');
        }
        $snapshot = TenantDataRegistrySnapshot::fromRegistry(
            new TenantDataRegistry(1, $definitions, [$profile]), $profile,
        );
        $objects = [];
        foreach (CompanyBackupDataInventory::payloadDefinitions($snapshot) as $index => $definition) {
            $objects[] = CompanyBackupDataObject::fromWrittenPayload(
                $definition, $index + 1, $definition->key === 'table:supplier' ? 1 : 0,
                0, hash('sha256', ''),
            );
        }
        $plan = CompanyBackupImportDependencyPlan::fromRegistry(
            $snapshot, CompanyBackupDataInventory::fromObjects($objects, $snapshot),
        );
        $rows = [
            'stock_documents' => [
                ['id' => 101, 'supplier_id' => 71, 'warehouse_id' => 81,
                    'stock_take_id' => 103, 'reversal_document_id' => 102,
                    'doc_type' => 'receipt', 'origin' => 'inventory', 'status' => 'reversed'],
                ['id' => 102, 'supplier_id' => 71, 'warehouse_id' => 81,
                    'stock_take_id' => 103, 'reversal_document_id' => null,
                    'doc_type' => 'issue', 'origin' => 'inventory', 'status' => 'posted'],
            ],
            'stock_takes' => [
                ['id' => 103, 'supplier_id' => 71, 'warehouse_id' => 81,
                    'receipt_document_id' => 101, 'issue_document_id' => null,
                    'take_date' => '2022-01-01', 'status' => 'closed'],
            ],
        ];
        $db->beginTransaction();
        $updates = [];
        foreach ($rows as $table => $values) {
            $projection = $projections[$table];
            $definition = $production->definition('table:' . $table);
            self::assertNotNull($definition);
            $schema = new CompanyBackupTableSchema($projection->dataColumns, [], ['id'], []);
            $deferred = CompanyBackupDeferredColumnSet::fromProjection($projection, $plan)->columns;
            $writer = new CompanyBackupSqlInsertWriter($db, $definition, $schema, count($values));
            foreach ($values as $index => $value) {
                $row = array_replace(array_fill_keys($projection->dataColumns, null), $value,
                    ['updated_at' => '2022-01-02 12:00:00']);
                $rows[$table][$index] = $row;
                $after = array_intersect_key($row, array_fill_keys($deferred, true));
                $before = array_fill_keys(array_keys($after), null);
                $key = CompanyBackupSourceKey::fromValues('table:' . $table, ['id' => $row['id']]);
                $identity = new CompanyBackupSourceIdentity(TenantDataPolicy::TenantOwned, $key,
                    CompanyBackupSourceKey::fromValues('table:' . $table,
                        ['supplier_id' => 71, 'id' => $row['id']]), null, []);
                $writer->insert(new CompanyBackupPreparedImportRow(
                    array_replace($row, $before), $identity, $identity,
                ));
                $updates[$table][] = new CompanyBackupPreparedDeferredUpdate($key, $before, $after);
            }
            $writer->finish();
        }
        foreach ($updates as $table => $prepared) {
            $definition = $production->definition('table:' . $table);
            self::assertNotNull($definition);
            $schema = new CompanyBackupTableSchema($projections[$table]->dataColumns, [], ['id'], []);
            $writer = new CompanyBackupSqlDeferredUpdateWriter($db, $definition, $schema, $plan, count($prepared));
            foreach ($prepared as $update) {
                $writer->update($update);
            }
            $writer->finish();
            self::assertSame(count($prepared), $writer->updatedRows());
            self::assertSame($rows[$table], $db->query('SELECT * FROM ' . $table . ' ORDER BY id')->fetchAll());
        }
        self::assertTrue($db->inTransaction());
        $db->rollBack();
        self::assertSame(0, (int) $db->query('SELECT COUNT(*) FROM stock_documents')->fetchColumn());
        self::assertSame(0, (int) $db->query('SELECT COUNT(*) FROM stock_takes')->fetchColumn());
    }
}
