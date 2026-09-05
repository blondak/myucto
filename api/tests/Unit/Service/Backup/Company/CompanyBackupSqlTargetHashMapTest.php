<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup\Company;

use MyInvoice\Service\Backup\CanonicalJson;
use MyInvoice\Service\Backup\Company\CompanyBackupArchiveLimits;
use MyInvoice\Service\Backup\Company\CompanyBackupEmbeddedHashReference;
use MyInvoice\Service\Backup\Company\CompanyBackupImportWriteException;
use MyInvoice\Service\Backup\Company\CompanyBackupSqlTargetHashMap;
use MyInvoice\Service\Backup\Company\CompanyBackupTableProjection;
use MyInvoice\Service\Backup\Registry\TenantDataDefinition;
use MyInvoice\Service\Backup\Registry\TenantDataObjectKind;
use MyInvoice\Service\Backup\Registry\TenantDataPolicy;
use MyInvoice\Service\Backup\Registry\TenantDataRegistry;
use PDO;
use PHPUnit\Framework\TestCase;

final class CompanyBackupSqlTargetHashMapTest extends TestCase
{
    private PDO $database;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite není dostupné pro izolovaný SQL test.');
        }
        $this->database = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ]);
    }

    public function testMapsRecomputedDerivedHashWithoutKeepingRowsInMemory(): void
    {
        $projection = CompanyBackupTableProjection::fromDefinition(
            $this->definition(),
        );
        $sourcePayload = CanonicalJson::encode(['id' => 7, 'value' => 'source']);
        $targetPayload = CanonicalJson::encode(['id' => 41, 'value' => 'source']);
        $source = $this->row(7, $sourcePayload);
        $target = $this->row(41, $targetPayload);
        $map = new CompanyBackupSqlTargetHashMap($this->database);

        $map->addRow($projection, $source, $target);
        $map->addRow($projection, $source, $target);

        self::assertSame(
            $target['row_hash'],
            $map->resolve($this->reference(), $source['row_hash']),
        );
        self::assertSame(1, $map->mappingCount());
        self::assertGreaterThan(0, $map->indexedBytes());

        $map->seal();
        self::assertTrue($map->isSealed());
        $this->assertWriteError(
            'import_hash_map_sealed',
            fn () => $map->addRow($projection, $source, $target),
        );
        $map->close();
        self::assertSame(0, $this->temporaryTableCount());
    }

    public function testRejectsAmbiguousAndMissingHashWithoutLeakingIt(): void
    {
        $projection = CompanyBackupTableProjection::fromDefinition(
            $this->definition(),
        );
        $sourcePayload = CanonicalJson::encode(['id' => 7, 'value' => 'source']);
        $source = $this->row(7, $sourcePayload);
        $first = $this->row(
            41,
            CanonicalJson::encode(['id' => 41, 'value' => 'source']),
        );
        $second = $this->row(
            42,
            CanonicalJson::encode(['id' => 42, 'value' => 'source']),
        );
        $map = new CompanyBackupSqlTargetHashMap($this->database);
        $map->addRow($projection, $source, $first);

        $this->assertWriteError(
            'import_hash_mapping_ambiguous',
            fn () => $map->addRow($projection, $source, $second),
            $source['row_hash'],
        );
        $this->assertWriteError(
            'import_hash_reference_unresolved',
            fn () => $map->resolve($this->reference(), str_repeat('a', 64)),
            str_repeat('a', 64),
        );

        $map->close();
    }

    public function testEnforcesEntryAndLogicalByteLimitsAtomically(): void
    {
        $projection = CompanyBackupTableProjection::fromDefinition(
            $this->definition(),
        );
        $first = $this->row(7, CanonicalJson::encode(['id' => 7]));
        $second = $this->row(8, CanonicalJson::encode(['id' => 8]));
        $entryLimited = new CompanyBackupSqlTargetHashMap(
            $this->database,
            new CompanyBackupArchiveLimits(maxSourceIndexEntries: 1),
        );
        $entryLimited->addRow($projection, $first, $first);
        $this->assertWriteError(
            'import_hash_mapping_limit_exceeded',
            fn () => $entryLimited->addRow($projection, $second, $second),
        );
        self::assertSame(1, $entryLimited->mappingCount());
        $entryLimited->close();

        $byteLimited = new CompanyBackupSqlTargetHashMap(
            $this->database,
            new CompanyBackupArchiveLimits(maxSourceIndexBytes: 1),
        );
        $this->assertWriteError(
            'import_hash_mapping_size_exceeded',
            fn () => $byteLimited->addRow($projection, $first, $first),
        );
        self::assertSame(0, $byteLimited->mappingCount());
        $byteLimited->close();
    }

    private function definition(): TenantDataDefinition
    {
        return new TenantDataDefinition(
            'table:synthetic_hash_targets',
            TenantDataObjectKind::Table,
            TenantDataPolicy::TenantRoot,
            [TenantDataRegistry::COMPANY_BACKUP_PROFILE],
            [
                'primary_key' => ['id'],
                'ownership' => [
                    'strategy' => 'selected_supplier',
                    'column' => 'id',
                ],
                'secrets' => [],
                'company_backup' => [
                    'data_columns' => [
                        'id',
                        'payload_json',
                        'row_hash',
                    ],
                    'derived_hashes' => [[
                        'algorithm' => 'sha256_canonical_json',
                        'hash_column' => 'row_hash',
                        'nullable' => false,
                        'source_column' => 'payload_json',
                    ]],
                    'embedded_references' => [],
                    'generated_columns' => [],
                    'omit_columns' => [],
                    'references' => [],
                    'restore_overrides' => [],
                ],
            ],
        );
    }

    /** @return array{id:int,payload_json:string,row_hash:string} */
    private function row(int $id, string $payload): array
    {
        return [
            'id' => $id,
            'payload_json' => $payload,
            'row_hash' => hash('sha256', $payload),
        ];
    }

    private function reference(): CompanyBackupEmbeddedHashReference
    {
        return CompanyBackupEmbeddedHashReference::fromArray([
            'column' => 'payload_json',
            'nullable' => true,
            'path' => ['target_hash'],
            'target' => 'table:synthetic_hash_targets',
            'target_hash_column' => 'row_hash',
        ], 'table:synthetic_hash_sources');
    }

    /** @param callable():mixed $operation */
    private function assertWriteError(
        string $errorCode,
        callable $operation,
        ?string $hidden = null,
    ): void {
        try {
            $operation();
            self::fail('Neplatná hashová mapa musí být odmítnuta.');
        } catch (CompanyBackupImportWriteException $e) {
            self::assertSame($errorCode, $e->errorCode);
            if ($hidden !== null) {
                self::assertStringNotContainsString($hidden, $e->getMessage());
            }
        }
    }

    private function temporaryTableCount(): int
    {
        $statement = $this->database->query(
            "SELECT COUNT(*) FROM sqlite_temp_master"
                . " WHERE type = 'table'"
                . " AND name LIKE 'company_backup_hash_%'",
        );
        if ($statement === false) {
            throw new \RuntimeException('Nelze ověřit dočasné tabulky.');
        }
        return (int) $statement->fetchColumn();
    }
}
