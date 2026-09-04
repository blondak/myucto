<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Backup;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Backup\Company\CompanyBackupDataPreflightResult;
use MyInvoice\Service\Backup\Company\CompanyBackupExternalReferenceCollector;
use MyInvoice\Service\Backup\Company\CompanyBackupReferenceConstraint;
use MyInvoice\Service\Backup\Company\CompanyBackupReferenceDecisionAction;
use MyInvoice\Service\Backup\Company\CompanyBackupReferenceDecisionPlan;
use MyInvoice\Service\Backup\Company\CompanyBackupReferenceMapping;
use MyInvoice\Service\Backup\Company\CompanyBackupReferenceOccurrence;
use MyInvoice\Service\Backup\Company\CompanyBackupReferenceSet;
use MyInvoice\Service\Backup\Company\CompanyBackupReferenceTargetResolver;
use MyInvoice\Service\Backup\Registry\TenantDataDefinition;
use MyInvoice\Service\Backup\Registry\TenantDataObjectKind;
use MyInvoice\Service\Backup\Registry\TenantDataPolicy;
use MyInvoice\Service\Backup\Registry\TenantDataRegistry;
use MyInvoice\Service\Backup\Registry\TenantDataRegistrySnapshot;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/** Živá MariaDB kontrola read-only cílového lookupu. */
#[Group('integration')]
final class CompanyBackupReferenceTargetResolverTest extends TestCase
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
        $pdo->exec('DROP TEMPORARY TABLE IF EXISTS countries');
        $pdo->exec('DROP TEMPORARY TABLE IF EXISTS users');
        $this->db->close();
    }

    public function testResolvesNaturalKeyWithoutCommittingCallerTransaction(): void
    {
        $pdo = $this->db->pdo();
        self::assertSame('mysql', $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
        $pdo->exec('DROP TEMPORARY TABLE IF EXISTS countries');
        $pdo->exec('DROP TEMPORARY TABLE IF EXISTS users');
        $pdo->exec(
            'CREATE TEMPORARY TABLE countries ('
            . 'id BIGINT UNSIGNED NOT NULL PRIMARY KEY,'
            . 'iso2 CHAR(2) NOT NULL'
            . ') ENGINE=InnoDB',
        );
        $pdo->exec(
            'CREATE TEMPORARY TABLE users ('
            . 'id BIGINT UNSIGNED NOT NULL PRIMARY KEY'
            . ') ENGINE=InnoDB',
        );
        $pdo->exec("INSERT INTO countries (id, iso2) VALUES (10, 'CZ')");
        $pdo->exec('INSERT INTO users (id) VALUES (91)');

        $registry = $this->registry();
        $collector = new CompanyBackupExternalReferenceCollector();
        $collector->accept($this->globalOccurrence());
        $preflight = new CompanyBackupDataPreflightResult(
            $collector->finish(),
            1,
            1,
            1,
            128,
            1,
            $registry->fingerprint,
            str_repeat('a', 64),
        );
        $requirement = $preflight->externalReferences->requirements[0];
        $plan = CompanyBackupReferenceDecisionPlan::fromArray([
            'format' => CompanyBackupReferenceDecisionPlan::FORMAT,
            'version' => CompanyBackupReferenceDecisionPlan::VERSION,
            'data_preflight_binding_sha256' => $preflight->bindingSha256,
            'decisions' => [[
                'requirement_id' => $requirement->id,
                'mapping' => CompanyBackupReferenceMapping::GlobalNaturalKey->value,
                'target_registry_key' => 'table:countries',
                'action' => CompanyBackupReferenceDecisionAction::MapExisting->value,
                'target_primary_key' => ['id' => 10],
            ]],
        ], $preflight, $registry, '123e4567-e89b-42d3-a456-426614174000', 91);
        $pdo->beginTransaction();

        $resolved = (new CompanyBackupReferenceTargetResolver($pdo))->resolve(
            $plan,
            $preflight,
            $registry,
        );

        self::assertSame(
            ['id' => 10],
            $resolved->resolution($requirement->id)?->targetPrimaryKey,
        );
        self::assertTrue($pdo->inTransaction());
    }

    private function globalOccurrence(): CompanyBackupReferenceOccurrence
    {
        $reference = CompanyBackupReferenceSet::fromArray([[
            'columns' => ['country_iso2'],
            'target' => 'table:countries',
            'target_columns' => ['iso2'],
            'mapping' => CompanyBackupReferenceMapping::GlobalNaturalKey->value,
            'constraint' => CompanyBackupReferenceConstraint::Required->value,
            'nullable_columns' => [],
            'fallbacks' => [],
        ]], 'table:synthetic_records')->references[0];
        return CompanyBackupReferenceOccurrence::column(
            'table:synthetic_records',
            $reference,
            ['CZ'],
        );
    }

    private function registry(): TenantDataRegistrySnapshot
    {
        $profile = TenantDataRegistry::COMPANY_BACKUP_PROFILE;
        return TenantDataRegistrySnapshot::fromRegistry(new TenantDataRegistry(
            1,
            [
                new TenantDataDefinition(
                    'table:countries',
                    TenantDataObjectKind::Table,
                    TenantDataPolicy::GlobalReference,
                    [$profile],
                    [
                        'primary_key' => ['id'],
                        'natural_key' => ['iso2'],
                        'ownership' => ['strategy' => 'global'],
                    ],
                ),
                new TenantDataDefinition(
                    'table:users',
                    TenantDataObjectKind::Table,
                    TenantDataPolicy::InstanceOwned,
                    [$profile],
                    [
                        'primary_key' => ['id'],
                        'ownership' => ['strategy' => 'instance'],
                    ],
                ),
            ],
            [$profile],
        ), $profile);
    }
}
