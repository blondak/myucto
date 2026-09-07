<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup\Company;

use MyInvoice\Service\Backup\CanonicalJson;
use MyInvoice\Service\Backup\Company\CompanyBackupFileInventory;
use MyInvoice\Service\Backup\Company\CompanyBackupFileRestoreException;
use MyInvoice\Service\Backup\Company\CompanyBackupSqlFilePathMap;
use MyInvoice\Service\Backup\Company\CompanyBackupTableProjection;
use MyInvoice\Service\Backup\Registry\TenantDataDefinition;
use MyInvoice\Service\Backup\Registry\TenantDataObjectKind;
use MyInvoice\Service\Backup\Registry\TenantDataPolicy;
use MyInvoice\Service\Backup\Registry\TenantDataRegistry;
use MyInvoice\Service\Backup\Registry\TenantDataRegistrySnapshot;
use PDO;
use PHPUnit\Framework\TestCase;

final class CompanyBackupSqlFilePathMapTest extends TestCase
{
    public function testMapsScalarAndNestedOwnerExactlyOnce(): void
    {
        $database = $this->database();
        $snapshot = $this->snapshot();
        $inventory = $this->inventory($snapshot);
        self::assertTrue($database->beginTransaction());
        $map = new CompanyBackupSqlFilePathMap(
            $database,
            $inventory,
            $snapshot,
            $snapshot,
        );

        $supplierPath = 'storage/supplier-logos/sup-7.png';
        $supplier = $map->transform(
            $this->projection($snapshot, 'table:supplier'),
            ['id' => 7, 'logo_path' => $supplierPath],
            ['id' => 41, 'logo_path' => $supplierPath],
            true,
        );
        self::assertSame(
            'storage/supplier-logos/sup-41.png',
            $supplier['logo_path'],
        );

        $invoicePath = 'storage/supplier-logos/'
            . 'sup-7-brand-11-aaaaaaaaaaaa.png';
        $snapshotJson = CanonicalJson::encode([
            'logo_path' => $invoicePath,
            'name' => 'Syntetický dodavatel',
        ]);
        $invoice = $map->transform(
            $this->projection($snapshot, 'table:invoices'),
            ['id' => 31, 'supplier_snapshot' => $snapshotJson],
            ['id' => 91, 'supplier_snapshot' => $snapshotJson],
            true,
        );
        $decoded = json_decode(
            (string) $invoice['supplier_snapshot'],
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($decoded);
        self::assertSame(
            'storage/supplier-logos/'
                . 'sup-41-brand-11-aaaaaaaaaaaa.png',
            $decoded['logo_path'],
        );

        try {
            $map->publicationPlan();
            self::fail(
                'Publication plán nesmí před dokončením mapy opustit SQL vrstvu.',
            );
        } catch (CompanyBackupFileRestoreException $e) {
            self::assertSame('file_restore_map_not_finished', $e->errorCode);
        }

        $map->finish();
        self::assertSame(2, $map->fileEntryCount());
        self::assertSame(2, $map->ownerEntryCount());
        self::assertGreaterThan(0, $map->indexedBytes());
        $plan = $map->publicationPlan();
        self::assertSame(7, $plan->sourceSupplierId);
        self::assertSame(41, $plan->targetSupplierId);
        self::assertSame(0, $plan->presentEntryCount());
        self::assertSame(2, $plan->missingEntryCount());
        self::assertSame([
            'sup-41-brand-11-aaaaaaaaaaaa.png',
            'sup-41.png',
        ], array_map(
            static fn ($entry): string => $entry->targetPath,
            $plan->entries,
        ));
        $map->close();
        self::assertTrue($database->inTransaction());
        self::assertTrue($database->rollBack());
    }

    public function testFinishRejectsManifestOwnerMissingFromDatabaseStream(): void
    {
        $database = $this->database();
        $snapshot = $this->snapshot();
        self::assertTrue($database->beginTransaction());
        $map = new CompanyBackupSqlFilePathMap(
            $database,
            $this->inventory($snapshot),
            $snapshot,
            $snapshot,
        );
        $supplierPath = 'storage/supplier-logos/sup-7.png';
        $map->transform(
            $this->projection($snapshot, 'table:supplier'),
            ['id' => 7, 'logo_path' => $supplierPath],
            ['id' => 41, 'logo_path' => $supplierPath],
            true,
        );

        try {
            $map->finish();
            self::fail(
                'Nevyužitý manifestový vlastník musí obnovu zastavit.',
            );
        } catch (CompanyBackupFileRestoreException $e) {
            self::assertSame('file_restore_owner_unconsumed', $e->errorCode);
        }

        $map->close();
        self::assertTrue($database->rollBack());
    }

    public function testAcceptsCanonicalEquivalentRegistryDefinitions(): void
    {
        $database = $this->database();
        $target = $this->snapshot();
        $decoded = json_decode(
            CanonicalJson::encode($target->toArray()),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($decoded);
        $source = TenantDataRegistrySnapshot::fromArray($decoded);
        self::assertNotSame(
            $source->registry->definition('file-area:supplier-logos')?->toArray(),
            $target->registry->definition('file-area:supplier-logos')?->toArray(),
        );
        self::assertSame($source->fingerprint, $target->fingerprint);
        self::assertTrue($database->beginTransaction());

        $map = new CompanyBackupSqlFilePathMap(
            $database,
            $this->inventory($source),
            $source,
            $target,
        );

        $map->close();
        self::assertTrue($database->inTransaction());
        self::assertTrue($database->rollBack());
    }

    private function database(): PDO
    {
        $dsn = getenv('COMPANY_BACKUP_FILE_MAP_TEST_DSN');
        $user = getenv('COMPANY_BACKUP_FILE_MAP_TEST_USER');
        $password = getenv('COMPANY_BACKUP_FILE_MAP_TEST_PASSWORD');
        if (!is_string($dsn) || $dsn === '') {
            if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
                self::markTestSkipped(
                    'pdo_sqlite není dostupné pro izolovaný SQL test.',
                );
            }
            $dsn = 'sqlite::memory:';
            $user = null;
            $password = null;
        }
        return new PDO(
            $dsn,
            is_string($user) ? $user : null,
            is_string($password) ? $password : null,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_STRINGIFY_FETCHES => false,
            ],
        );
    }

    private function snapshot(): TenantDataRegistrySnapshot
    {
        $profile = TenantDataRegistry::COMPANY_BACKUP_PROFILE;
        $supplier = new TenantDataDefinition(
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
                'company_backup' => $this->tableProjection([
                    'id',
                    'logo_path',
                ]),
            ],
        );
        $invoices = new TenantDataDefinition(
            'table:invoices',
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
                'company_backup' => $this->tableProjection(
                    ['id', 'supplier_id', 'supplier_snapshot'],
                    [[
                        'columns' => ['supplier_id'],
                        'constraint' => 'required',
                        'fallbacks' => [],
                        'mapping' => 'tenant_id',
                        'nullable_columns' => [],
                        'target' => 'table:supplier',
                        'target_columns' => ['id'],
                    ]],
                ),
            ],
        );
        $area = new TenantDataDefinition(
            'file-area:supplier-logos',
            TenantDataObjectKind::FileArea,
            TenantDataPolicy::TenantOwned,
            [$profile],
            [
                'file_policy' => 'historical_optional',
                'path_policy' => 'supplier_logo',
                'file_owners' => [[
                    'registry_key' => 'table:invoices',
                    'column' => 'supplier_snapshot',
                    'path' => ['logo_path'],
                    'stored_prefix' => 'storage/supplier-logos/',
                ], [
                    'registry_key' => 'table:supplier',
                    'column' => 'logo_path',
                    'path' => [],
                    'stored_prefix' => 'storage/supplier-logos/',
                ]],
                'ownership' => ['strategy' => 'database_references'],
                'storage_subdirectory' => 'supplier-logos',
            ],
        );
        return TenantDataRegistrySnapshot::fromRegistry(
            new TenantDataRegistry(
                1,
                [$supplier, $invoices, $area],
                [$profile],
            ),
            $profile,
        );
    }

    private function inventory(
        TenantDataRegistrySnapshot $snapshot,
    ): CompanyBackupFileInventory {
        return CompanyBackupFileInventory::fromArray([
            'format' => CompanyBackupFileInventory::FORMAT,
            'version' => CompanyBackupFileInventory::VERSION,
            'areas' => [[
                'registry_key' => 'file-area:supplier-logos',
                'order' => 1,
                'entries' => [[
                    'source_path' =>
                        'sup-7-brand-11-aaaaaaaaaaaa.png',
                    'archive_path' => null,
                    'state' => 'missing',
                    'bytes' => null,
                    'sha256' => null,
                    'owners' => [[
                        'registry_key' => 'table:invoices',
                        'primary_key' => ['id' => 31],
                        'column' => 'supplier_snapshot',
                        'path' => ['logo_path'],
                    ]],
                ], [
                    'source_path' => 'sup-7.png',
                    'archive_path' => null,
                    'state' => 'missing',
                    'bytes' => null,
                    'sha256' => null,
                    'owners' => [[
                        'registry_key' => 'table:supplier',
                        'primary_key' => ['id' => 7],
                        'column' => 'logo_path',
                        'path' => [],
                    ]],
                ]],
            ]],
        ], $snapshot);
    }

    /**
     * @param list<string> $columns
     * @param list<array<string,mixed>> $references
     * @return array<string,mixed>
     */
    private function tableProjection(
        array $columns,
        array $references = [],
    ): array {
        return [
            'data_columns' => $columns,
            'embedded_references' => [],
            'generated_columns' => [],
            'omit_columns' => [],
            'references' => $references,
            'restore_overrides' => [],
        ];
    }

    private function projection(
        TenantDataRegistrySnapshot $snapshot,
        string $registryKey,
    ): CompanyBackupTableProjection {
        $definition = $snapshot->registry->definition($registryKey);
        self::assertInstanceOf(TenantDataDefinition::class, $definition);
        return CompanyBackupTableProjection::fromDefinition($definition);
    }
}
