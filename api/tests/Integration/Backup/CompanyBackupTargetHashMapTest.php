<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Backup;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Backup\CanonicalJson;
use MyInvoice\Service\Backup\Company\CompanyBackupEmbeddedHashReference;
use MyInvoice\Service\Backup\Company\CompanyBackupSqlTargetHashMap;
use MyInvoice\Service\Backup\Company\CompanyBackupTableProjection;
use MyInvoice\Service\Backup\Registry\TenantDataDefinition;
use MyInvoice\Service\Backup\Registry\TenantDataObjectKind;
use MyInvoice\Service\Backup\Registry\TenantDataPolicy;
use MyInvoice\Service\Backup\Registry\TenantDataRegistry;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/** Živá MariaDB kontrola dočasné InnoDB mapy odvozených hashů. */
#[Group('integration')]
final class CompanyBackupTargetHashMapTest extends TestCase
{
    private Connection $db;

    private bool $connected = false;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $container = Bootstrap::buildApp()->getContainer();
            if ($container === null) {
                throw new \RuntimeException('Aplikace nemá DI kontejner.');
            }
            $connection = $container->get(Connection::class);
            if (!$connection instanceof Connection) {
                throw new \RuntimeException('DI nevrátilo databázové spojení.');
            }
            $connection->pdo();
            $this->db = $connection;
            $this->connected = true;
        } catch (\Throwable $e) {
            $this->markTestSkipped('Testovací DB není dostupná: ' . $e->getMessage());
        }
    }

    protected function tearDown(): void
    {
        if (!$this->connected) {
            return;
        }
        $pdo = $this->db->pdo();
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $this->db->close();
    }

    public function testMapsHashWithoutCommittingCallerTransaction(): void
    {
        $pdo = $this->db->pdo();
        self::assertSame('mysql', $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
        self::assertTrue($pdo->beginTransaction());
        $projection = CompanyBackupTableProjection::fromDefinition(
            $this->definition(),
        );
        $sourcePayload = CanonicalJson::encode(['supplier_id' => 7]);
        $targetPayload = CanonicalJson::encode(['supplier_id' => 41]);
        $source = [
            'id' => 11,
            'payload_json' => $sourcePayload,
            'row_hash' => hash('sha256', $sourcePayload),
        ];
        $target = [
            'id' => 101,
            'payload_json' => $targetPayload,
            'row_hash' => hash('sha256', $targetPayload),
        ];
        $map = new CompanyBackupSqlTargetHashMap($pdo);
        try {
            $map->addRow($projection, $source, $target);
            self::assertSame(
                $target['row_hash'],
                $map->resolve($this->reference(), $source['row_hash']),
            );
            $map->seal();
            self::assertTrue($pdo->inTransaction());
        } finally {
            $map->close();
        }

        self::assertTrue($pdo->inTransaction());
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
                    'data_columns' => ['id', 'payload_json', 'row_hash'],
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

    private function reference(): CompanyBackupEmbeddedHashReference
    {
        return CompanyBackupEmbeddedHashReference::fromArray([
            'column' => 'payload_json',
            'nullable' => true,
            'path' => ['row_hash'],
            'target' => 'table:synthetic_hash_targets',
            'target_hash_column' => 'row_hash',
        ], 'table:synthetic_hash_sources');
    }
}
