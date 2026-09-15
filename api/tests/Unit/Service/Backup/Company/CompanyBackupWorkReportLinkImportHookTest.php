<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup\Company;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Backup\Company\CompanyBackupAutoIncrementColumn;
use MyInvoice\Service\Backup\Company\CompanyBackupDataInventory;
use MyInvoice\Service\Backup\Company\CompanyBackupDataObject;
use MyInvoice\Service\Backup\Company\CompanyBackupDataPreflightResult;
use MyInvoice\Service\Backup\Company\CompanyBackupDatabaseImporter;
use MyInvoice\Service\Backup\Company\CompanyBackupExternalReferenceInventory;
use MyInvoice\Service\Backup\Company\CompanyBackupFileInventory;
use MyInvoice\Service\Backup\Company\CompanyBackupImportSchemaSource;
use MyInvoice\Service\Backup\Company\CompanyBackupImportSource;
use MyInvoice\Service\Backup\Company\CompanyBackupImportTableMetadata;
use MyInvoice\Service\Backup\Company\CompanyBackupPreflightException;
use MyInvoice\Service\Backup\Company\CompanyBackupReferenceDecisionPlan;
use MyInvoice\Service\Backup\Company\CompanyBackupSecretPayload;
use MyInvoice\Service\Backup\Company\CompanyBackupSecretScope;
use MyInvoice\Service\Backup\Company\CompanyBackupSecretValue;
use MyInvoice\Service\Backup\Company\CompanyBackupTableProjection;
use MyInvoice\Service\Backup\Company\CompanyBackupTableSchema;
use MyInvoice\Service\Backup\Registry\CompanyBackupWorkReportLinksDefinition;
use MyInvoice\Service\Backup\Registry\TenantDataDefinition;
use MyInvoice\Service\Backup\Registry\TenantDataObjectKind;
use MyInvoice\Service\Backup\Registry\TenantDataPolicy;
use MyInvoice\Service\Backup\Registry\TenantDataRegistry;
use MyInvoice\Service\Backup\Registry\TenantDataRegistrySnapshot;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveData;
use PDO;
use PHPUnit\Framework\TestCase;

final class CompanyBackupWorkReportLinkImportHookTest extends TestCase
{
    public const TECHNICAL_BINDING = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const INSTANCE_ID = '123e4567-e89b-42d3-a456-426614174000';
    private const TOKEN = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    public function testDatabaseImporterChecksMappedRelationsBeforeInsertAndPreservesRevocation(): void
    {
        foreach ([false, true] as $mismatchedProjectClient) {
            $pdo = $this->database();
            $registry = $this->registry();
            $linkDefinition = $registry->registry->definition('table:work_report_links');
            self::assertNotNull($linkDefinition);
            self::assertFalse(CompanyBackupTableProjection::fromDefinition($linkDefinition)->allowsDeferredUpdates);
            $pdo->exec("INSERT INTO supplier (id, name) VALUES (7, 'Existing tenant')");
            $pdo->exec('INSERT INTO users (id) VALUES (91)');
            $pdo->beginTransaction();
            try {
                [$source, $preflight, $decisions] = $this->context($registry, $mismatchedProjectClient);
                $importer = new CompanyBackupDatabaseImporter($pdo, new LinkHookImportSchema());
                if ($mismatchedProjectClient) {
                    try {
                        $importer->restore($source, $preflight, $decisions, $this->sensitiveData());
                        self::fail('Import nesmí vložit odkaz na projekt jiného klienta.');
                    } catch (CompanyBackupPreflightException $e) {
                        self::assertSame('work_report_link_relation_mismatch', $e->errorCode);
                        self::assertStringNotContainsString(self::TOKEN, $e->getMessage());
                    }
                    $count = $pdo->query('SELECT COUNT(*) FROM work_report_links');
                    self::assertInstanceOf(\PDOStatement::class, $count);
                    self::assertSame(0, (int) $count->fetchColumn());
                } else {
                    $result = $importer->restore($source, $preflight, $decisions, $this->sensitiveData());
                    self::assertNotSame(7, $result->supplierId);
                    $query = $pdo->query('SELECT l.supplier_id, l.client_id, l.project_id, l.token, l.revoked_at,
                        c.supplier_id AS client_supplier_id, p.client_id AS project_client_id
                        FROM work_report_links l JOIN clients c ON c.id = l.client_id
                        JOIN projects p ON p.id = l.project_id');
                    self::assertInstanceOf(\PDOStatement::class, $query);
                    $restored = $query->fetch(PDO::FETCH_ASSOC);
                    self::assertIsArray($restored);
                    self::assertSame((int) $restored['supplier_id'], (int) $restored['client_supplier_id']);
                    self::assertSame((int) $restored['client_id'], (int) $restored['project_client_id']);
                    self::assertSame(self::TOKEN, $restored['token']);
                    self::assertSame('2026-01-03 04:05:06', $restored['revoked_at']);
                }
            } finally {
                $pdo->rollBack();
            }
        }
    }

    private function database(): PDO
    {
        $pdo = new PDO('sqlite::memory:', options: [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ]);
        $pdo->exec('CREATE TABLE supplier (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)');
        $pdo->exec('CREATE TABLE clients (id INTEGER PRIMARY KEY AUTOINCREMENT, supplier_id INTEGER NOT NULL)');
        $pdo->exec('CREATE TABLE projects (id INTEGER PRIMARY KEY AUTOINCREMENT, client_id INTEGER NOT NULL)');
        $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY)');
        $pdo->exec('CREATE TABLE work_report_links (
            id INTEGER PRIMARY KEY AUTOINCREMENT, supplier_id INTEGER NOT NULL,
            scope TEXT NOT NULL, client_id INTEGER NOT NULL, project_id INTEGER NULL,
            token TEXT NOT NULL UNIQUE, created_by_user_id INTEGER NULL,
            created_at TEXT NOT NULL DEFAULT \'2026-01-01 00:00:00\',
            last_sent_at TEXT NULL, last_viewed_at TEXT NULL, revoked_at TEXT NULL
        )');
        return $pdo;
    }

    private function registry(): TenantDataRegistrySnapshot
    {
        $profile = TenantDataRegistry::COMPANY_BACKUP_PROFILE;
        return TenantDataRegistrySnapshot::fromRegistry(new TenantDataRegistry(1, [
            $this->definition('supplier', TenantDataPolicy::TenantRoot,
                ['id', 'name'], ['strategy' => 'selected_supplier', 'column' => 'id']),
            $this->definition('clients', TenantDataPolicy::TenantOwned,
                ['id', 'supplier_id'], ['strategy' => 'supplier_id', 'column' => 'supplier_id'],
                [$this->reference('supplier_id', 'supplier')]),
            $this->definition('projects', TenantDataPolicy::TenantOwned,
                ['id', 'client_id'], ['strategy' => 'parent_join', 'parent' => 'table:clients',
                    'local_column' => 'client_id', 'parent_column' => 'id'],
                [$this->reference('client_id', 'clients')]),
            CompanyBackupWorkReportLinksDefinition::definition(),
            new TenantDataDefinition('table:users', TenantDataObjectKind::Table,
                TenantDataPolicy::InstanceOwned, [$profile],
                ['primary_key' => ['id'], 'ownership' => ['strategy' => 'instance']]),
        ], [$profile]), $profile);
    }

    /**
     * @param list<string> $columns
     * @param array<string,mixed> $ownership
     * @param list<array<string,mixed>> $references
     * @param array<string,mixed> $secrets
     * @param list<array<string,mixed>> $materializations
     */
    private function definition(string $name, TenantDataPolicy $policy, array $columns,
        array $ownership, array $references = [], array $secrets = [], array $materializations = []): TenantDataDefinition
    {
        return new TenantDataDefinition('table:' . $name, TenantDataObjectKind::Table,
            $policy, [TenantDataRegistry::COMPANY_BACKUP_PROFILE], [
                'primary_key' => ['id'], 'ownership' => $ownership, 'secrets' => $secrets,
                'company_backup' => [
                    'data_columns' => $columns, 'embedded_references' => [],
                    'generated_columns' => [], 'omit_columns' => [],
                    'references' => $references, 'restore_overrides' => [],
                    ...($materializations !== [] ? ['protected_secret_materializations' => $materializations] : []),
                ],
            ]);
    }

    /** @return array<string,mixed> */
    private function reference(string $column, string $target, bool $nullable = false): array
    {
        return [
            'columns' => [$column], 'target' => 'table:' . $target,
            'target_columns' => ['id'], 'mapping' => 'tenant_id',
            'constraint' => 'optional', 'nullable_columns' => $nullable ? [$column] : [],
            'fallbacks' => [],
        ];
    }

    /** @return array{LinkHookImportSource,CompanyBackupDataPreflightResult,CompanyBackupReferenceDecisionPlan} */
    private function context(TenantDataRegistrySnapshot $registry, bool $mismatch): array
    {
        $rows = [
            'table:supplier' => [['id' => 7, 'name' => 'Restored tenant']],
            'table:clients' => [
                ['id' => 11, 'supplier_id' => 7],
                ['id' => 12, 'supplier_id' => 7],
            ],
            'table:projects' => [['id' => 21, 'client_id' => $mismatch ? 12 : 11]],
            'table:work_report_links' => [[
                'id' => 31, 'supplier_id' => 7, 'scope' => 'project',
                'client_id' => 11, 'project_id' => 21,
                'created_by_user_id' => null, 'created_at' => '2026-01-01 01:02:03',
                'last_sent_at' => null, 'last_viewed_at' => null,
                'revoked_at' => '2026-01-03 04:05:06',
            ]],
        ];
        $objects = [];
        foreach (CompanyBackupDataInventory::payloadDefinitions($registry) as $index => $definition) {
            $objects[] = CompanyBackupDataObject::fromWrittenPayload(
                $definition, $index + 1, count($rows[$definition->key]), 0, hash('sha256', ''),
            );
        }
        $inventory = CompanyBackupDataInventory::fromObjects($objects, $registry);
        $files = CompanyBackupFileInventory::fromArray([
            'format' => CompanyBackupFileInventory::FORMAT,
            'version' => CompanyBackupFileInventory::VERSION,
            'areas' => [],
        ], $registry);
        $payload = CompanyBackupSecretPayload::fromValues([
            CompanyBackupSecretValue::fromPlaintext(
                'table:work_report_links', CompanyBackupSecretScope::Column,
                'token', ['id' => 31], self::TOKEN,
            ),
        ], $registry);
        $preflight = new CompanyBackupDataPreflightResult(
            new CompanyBackupExternalReferenceInventory([]), 5, 5, 8, 1_024, 0,
            $registry->fingerprint, self::TECHNICAL_BINDING,
        );
        $decisions = CompanyBackupReferenceDecisionPlan::fromArray([
            'format' => CompanyBackupReferenceDecisionPlan::FORMAT,
            'version' => CompanyBackupReferenceDecisionPlan::VERSION,
            'data_preflight_binding_sha256' => $preflight->bindingSha256,
            'decisions' => [],
        ], $preflight, $registry, self::INSTANCE_ID, 91);
        return [new LinkHookImportSource($registry, $inventory, $files, $rows, $payload),
            $preflight, $decisions];
    }

    private function sensitiveData(): PayrollSensitiveData
    {
        $config = new Config(['app' => [
            'secret_encryption_key' => base64_encode(str_repeat('s', 32)),
            'payroll_hash_key' => base64_encode(str_repeat('h', 32)),
        ]]);
        return new PayrollSensitiveData(new SecretEncryption($config), $config);
    }
}

final readonly class LinkHookImportSchema implements CompanyBackupImportSchemaSource
{
    public static function schema(CompanyBackupTableProjection $projection): CompanyBackupTableSchema
    {
        return new CompanyBackupTableSchema(
            [...$projection->dataColumns, ...array_keys($projection->secretPolicies)],
            [], $projection->primaryKey, [],
        );
    }

    public function read(PDO $database, CompanyBackupTableProjection $projection): CompanyBackupTableSchema
    {
        return self::schema($projection);
    }

    public function readImportMetadata(PDO $database, CompanyBackupTableProjection $projection): CompanyBackupImportTableMetadata
    {
        return new CompanyBackupImportTableMetadata(
            new CompanyBackupAutoIncrementColumn('id', PHP_INT_MAX),
        );
    }
}

final readonly class LinkHookImportSource implements CompanyBackupImportSource
{
    /** @param array<string,list<array<string,mixed>>> $rows */
    public function __construct(
        private TenantDataRegistrySnapshot $registry,
        private CompanyBackupDataInventory $inventory,
        private CompanyBackupFileInventory $files,
        private array $rows,
        private CompanyBackupSecretPayload $payload,
    ) {}

    public function sourceRegistry(): TenantDataRegistrySnapshot { return $this->registry; }
    public function targetRegistry(): TenantDataRegistrySnapshot { return $this->registry; }
    public function dataInventory(): CompanyBackupDataInventory { return $this->inventory; }
    public function fileInventory(): CompanyBackupFileInventory { return $this->files; }
    public function technicalValidationBindingSha256(): string { return CompanyBackupWorkReportLinkImportHookTest::TECHNICAL_BINDING; }
    public function consumeFile(string $archivePath, callable $chunkVisitor): int { throw new \RuntimeException('No files'); }

    public function consumeRows(string $registryKey, callable $rowVisitor, ?callable $referenceVisitor = null): int
    {
        $rows = $this->rows[$registryKey] ?? [];
        foreach ($rows as $row) {
            $rowVisitor($row);
        }
        return count($rows);
    }

    public function secretPayload(): CompanyBackupSecretPayload { return $this->payload; }
    public function close(): void {}
}
