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
use MyInvoice\Service\Backup\Company\CompanyBackupImportWriteException;
use MyInvoice\Service\Backup\Company\CompanyBackupPreflightException;
use MyInvoice\Service\Backup\Company\CompanyBackupReferenceDecisionPlan;
use MyInvoice\Service\Backup\Company\CompanyBackupSecretPayload;
use MyInvoice\Service\Backup\Company\CompanyBackupSecretScope;
use MyInvoice\Service\Backup\Company\CompanyBackupSecretValue;
use MyInvoice\Service\Backup\Company\CompanyBackupTableProjection;
use MyInvoice\Service\Backup\Company\CompanyBackupWorkReportLinkDecisionPlan;
use MyInvoice\Service\Backup\Company\CompanyBackupWorkReportLinkImportTokenGuard;
use MyInvoice\Service\Backup\Company\CompanyBackupWorkReportLinkPreflightInventoryCollector;
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
                [$source, $preflight, $decisions, $linkDecisions] = $this->context($pdo, $registry, $mismatchedProjectClient);
                $importer = new CompanyBackupDatabaseImporter($pdo, new LinkHookImportSchema());
                if ($mismatchedProjectClient) {
                    try {
                        $importer->restore($source, $preflight, $decisions, $this->sensitiveData(), $linkDecisions);
                        self::fail('Import nesmí vložit odkaz na projekt jiného klienta.');
                    } catch (CompanyBackupPreflightException $e) {
                        self::assertSame('work_report_link_relation_mismatch', $e->errorCode);
                        self::assertStringNotContainsString(self::TOKEN, $e->getMessage());
                    }
                    $count = $pdo->query('SELECT COUNT(*) FROM work_report_links');
                    self::assertInstanceOf(\PDOStatement::class, $count);
                    self::assertSame(0, (int) $count->fetchColumn());
                } else {
                    $result = $importer->restore($source, $preflight, $decisions, $this->sensitiveData(), $linkDecisions);
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

    public function testRestoreRegeneratesTargetCollisionAndArchiveDuplicateTokens(): void
    {
        foreach ([false, true] as $archiveDuplicate) {
            $pdo = $this->database();
            $registry = $this->registry();
            $pdo->exec("INSERT INTO supplier (id, name) VALUES (7, 'Existing tenant')");
            $pdo->exec('INSERT INTO users (id) VALUES (91)');
            if (!$archiveDuplicate) {
                $pdo->exec("INSERT INTO work_report_links (id, supplier_id, scope, client_id, token, revoked_at)
                    VALUES (90, 999, 'client', 100, '" . self::TOKEN . "', '2026-01-09 00:00:00')");
            }
            $targetBefore = self::targetLink($pdo);
            $tokens = $archiveDuplicate ? [31 => self::TOKEN, 32 => self::TOKEN] : [31 => self::TOKEN];
            [$source, $preflight, $decisions, $linkDecisions] = $this->context($pdo, $registry, false, $tokens);
            self::assertNotNull($linkDecisions);
            self::assertSame(count($tokens), $linkDecisions->inventory->collisionCount());

            $pdo->beginTransaction();
            try {
                (new CompanyBackupDatabaseImporter($pdo, new LinkHookImportSchema()))->restore(
                    $source, $preflight, $decisions, $this->sensitiveData(), $linkDecisions,
                );
                $statement = $pdo->query('SELECT token, revoked_at FROM work_report_links WHERE id != 90 ORDER BY id');
                self::assertInstanceOf(\PDOStatement::class, $statement);
                $restored = $statement->fetchAll(PDO::FETCH_ASSOC);
                self::assertCount(count($tokens), $restored);
                $issued = [];
                foreach ($restored as $row) {
                    self::assertMatchesRegularExpression('/\A[0-9a-f]{48}\z/D', $row['token']);
                    self::assertNotSame(self::TOKEN, $row['token']);
                    self::assertSame('2026-01-03 04:05:06', $row['revoked_at']);
                    $issued[$row['token']] = true;
                }
                self::assertCount(count($tokens), $issued);
                if (!$archiveDuplicate) {
                    self::assertSame($targetBefore, self::targetLink($pdo));
                }
            } finally {
                $pdo->rollBack();
            }
        }
    }

    public function testChangedTargetCollisionAndMissingPlanFailBeforeRestoredWrites(): void
    {
        foreach (['stale', 'missing'] as $case) {
            $pdo = $this->database();
            $registry = $this->registry();
            $pdo->exec("INSERT INTO supplier (id, name) VALUES (7, 'Existing tenant')");
            $pdo->exec('INSERT INTO users (id) VALUES (91)');
            [$source, $preflight, $decisions, $linkDecisions] = $this->context($pdo, $registry, false);
            self::assertNotNull($linkDecisions);
            if ($case === 'stale') {
                $pdo->exec("INSERT INTO work_report_links (id, supplier_id, scope, client_id, token, revoked_at)
                    VALUES (90, 999, 'client', 100, '" . self::TOKEN . "', '2026-01-09 00:00:00')");
            }
            $before = self::counts($pdo);
            $targetBefore = self::targetLink($pdo);
            $pdo->beginTransaction();
            try {
                try {
                    (new CompanyBackupDatabaseImporter($pdo, new LinkHookImportSchema()))->restore(
                        $source, $preflight, $decisions, $this->sensitiveData(),
                        $case === 'missing' ? null : $linkDecisions,
                    );
                    self::fail('Neplatný plán musí import odmítnout před zápisy.');
                } catch (CompanyBackupImportWriteException $e) {
                    self::assertSame($case === 'stale'
                        ? 'work_report_link_inventory_stale'
                        : 'work_report_link_import_context_missing', $e->errorCode);
                    self::assertStringNotContainsString(self::TOKEN, $e->getMessage());
                }
                self::assertSame($before, self::counts($pdo));
                self::assertSame($targetBefore, self::targetLink($pdo));
            } finally {
                $pdo->rollBack();
            }
        }
    }

    public function testExplicitRejectDecisionStopsBeforeImporterAndEmptyActivePlanIsRequired(): void
    {
        $pdo = $this->database();
        $registry = $this->registry();
        $pdo->exec("INSERT INTO supplier (id, name) VALUES (7, 'Existing tenant')");
        $pdo->exec('INSERT INTO users (id) VALUES (91)');
        $pdo->exec("INSERT INTO work_report_links (id, supplier_id, scope, client_id, token, revoked_at)
            VALUES (90, 999, 'client', 100, '" . self::TOKEN . "', '2026-01-09 00:00:00')");
        [, $preflight, , $linkDecisions] = $this->context($pdo, $registry, false);
        self::assertNotNull($linkDecisions);
        $before = self::counts($pdo);
        try {
            CompanyBackupWorkReportLinkDecisionPlan::fromArray([
                'data_preflight_binding_sha256' => $preflight->bindingSha256,
                'link_inventory_sha256' => $linkDecisions->inventory->sha256(),
                'decisions' => [['source_link_id' => 31, 'action' => 'reject']],
            ], $linkDecisions->inventory, $preflight->bindingSha256,
                $registry->fingerprint, self::INSTANCE_ID, 91);
            self::fail('Explicitní odmítnutí nesmí vytvořit importní plán.');
        } catch (CompanyBackupPreflightException $e) {
            self::assertSame('work_report_link_collision_rejected', $e->errorCode);
        }
        self::assertSame($before, self::counts($pdo));

        $emptyPdo = $this->database();
        $emptyPdo->exec("INSERT INTO supplier (id, name) VALUES (7, 'Existing tenant')");
        $emptyPdo->exec('INSERT INTO users (id) VALUES (91)');
        [$source, $emptyPreflight, $decisions, $emptyPlan] = $this->context(
            $emptyPdo, $this->registry(), false, [],
        );
        self::assertNotNull($emptyPlan);
        self::assertSame(0, $emptyPlan->inventory->count());
        $emptyPdo->beginTransaction();
        try {
            (new CompanyBackupDatabaseImporter($emptyPdo, new LinkHookImportSchema()))->restore(
                $source, $emptyPreflight, $decisions, $this->sensitiveData(), $emptyPlan,
            );
            self::assertSame(0, self::counts($emptyPdo)['work_report_links']);
        } finally {
            $emptyPdo->rollBack();
        }
    }

    public function testAbsentLinkObjectRejectsUnexpectedLinkPlan(): void
    {
        $pdo = $this->database();
        $registry = $this->registry(false);
        $pdo->exec("INSERT INTO supplier (id, name) VALUES (7, 'Existing tenant')");
        $pdo->exec('INSERT INTO users (id) VALUES (91)');
        [$source, $preflight, $decisions] = $this->context($pdo, $registry, false);
        $emptyInventory = new \MyInvoice\Service\Backup\Company\CompanyBackupWorkReportLinkInventory();
        $unexpectedPlan = CompanyBackupWorkReportLinkDecisionPlan::fromArray([
            'data_preflight_binding_sha256' => $preflight->bindingSha256,
            'link_inventory_sha256' => $emptyInventory->sha256(),
            'decisions' => [],
        ], $emptyInventory, $preflight->bindingSha256, $registry->fingerprint,
            self::INSTANCE_ID, 91);
        $before = self::counts($pdo);
        $pdo->beginTransaction();
        try {
            try {
                (new CompanyBackupDatabaseImporter($pdo, new LinkHookImportSchema()))->restore(
                    $source, $preflight, $decisions, $this->sensitiveData(), $unexpectedPlan,
                );
                self::fail('Plán bez objektu odkazů musí být odmítnut.');
            } catch (CompanyBackupImportWriteException $e) {
                self::assertSame('work_report_link_import_context_missing', $e->errorCode);
            }
            self::assertSame($before, self::counts($pdo));
        } finally {
            $pdo->rollBack();
        }

        $unexpectedPreflight = new CompanyBackupDataPreflightResult(
            $preflight->externalReferences, $preflight->rowCount, $preflight->identityCount,
            $preflight->sourceKeyCount, $preflight->sourceIndexBytes,
            $preflight->referenceOccurrenceCount, $registry->fingerprint,
            self::TECHNICAL_BINDING, workReportLinkInventory: $emptyInventory,
        );
        $matchingReferences = CompanyBackupReferenceDecisionPlan::fromArray([
            'format' => CompanyBackupReferenceDecisionPlan::FORMAT,
            'version' => CompanyBackupReferenceDecisionPlan::VERSION,
            'data_preflight_binding_sha256' => $unexpectedPreflight->bindingSha256,
            'decisions' => [],
        ], $unexpectedPreflight, $registry, self::INSTANCE_ID, 91);
        $pdo->beginTransaction();
        try {
            try {
                (new CompanyBackupDatabaseImporter($pdo, new LinkHookImportSchema()))->restore(
                    $source, $unexpectedPreflight, $matchingReferences, $this->sensitiveData(),
                );
                self::fail('Inventář bez objektu odkazů musí být odmítnut.');
            } catch (CompanyBackupImportWriteException $e) {
                self::assertSame('work_report_link_import_context_missing', $e->errorCode);
            }
            self::assertSame($before, self::counts($pdo));
        } finally {
            $pdo->rollBack();
        }
    }

    public function testPlanWithChangedRestoreActorFailsAtImporterBoundaryBeforeWrites(): void
    {
        $pdo = $this->database();
        $registry = $this->registry();
        $pdo->exec("INSERT INTO supplier (id, name) VALUES (7, 'Existing tenant')");
        $pdo->exec('INSERT INTO users (id) VALUES (91)');
        [$source, $preflight, $decisions, $linkDecisions] = $this->context($pdo, $registry, false);
        self::assertNotNull($linkDecisions);
        $wrongActorPlan = CompanyBackupWorkReportLinkDecisionPlan::fromArray([
            'data_preflight_binding_sha256' => $preflight->bindingSha256,
            'link_inventory_sha256' => $linkDecisions->inventory->sha256(),
            'decisions' => [],
        ], $linkDecisions->inventory, $preflight->bindingSha256,
            $registry->fingerprint, self::INSTANCE_ID, 92);
        $before = self::counts($pdo);
        $pdo->beginTransaction();
        try {
            try {
                (new CompanyBackupDatabaseImporter($pdo, new LinkHookImportSchema()))->restore(
                    $source, $preflight, $decisions, $this->sensitiveData(), $wrongActorPlan,
                );
                self::fail('Plán jiného obnovujícího aktéra musí být odmítnut.');
            } catch (CompanyBackupPreflightException $e) {
                self::assertSame('work_report_link_decision_context_mismatch', $e->errorCode);
            }
            self::assertSame($before, self::counts($pdo));
        } finally {
            $pdo->rollBack();
        }
    }

    public function testImportTokenGuardIssuesEachSourceIdAtMostOnce(): void
    {
        $pdo = $this->database();
        [, , , $plan] = $this->context($pdo, $this->registry(), false);
        self::assertNotNull($plan);
        $guard = new CompanyBackupWorkReportLinkImportTokenGuard($pdo, $plan->inventory, $plan);
        self::assertSame(self::TOKEN, $guard->resolve(31, self::TOKEN));
        try {
            $guard->resolve(31, self::TOKEN);
            self::fail('Stejný zdrojový odkaz nesmí vydat token dvakrát.');
        } catch (CompanyBackupPreflightException $e) {
            self::assertSame('work_report_link_decision_stale', $e->errorCode);
            self::assertStringNotContainsString(self::TOKEN, $e->getMessage());
        }
    }

    /** @return array{supplier:int,clients:int,projects:int,work_report_links:int} */
    private static function counts(PDO $database): array
    {
        $counts = [];
        foreach (['supplier', 'clients', 'projects', 'work_report_links'] as $table) {
            $statement = $database->query('SELECT COUNT(*) FROM ' . $table);
            self::assertInstanceOf(\PDOStatement::class, $statement);
            $counts[$table] = (int) $statement->fetchColumn();
        }
        return $counts;
    }

    /** @return array<string,mixed>|false */
    private static function targetLink(PDO $database): array|false
    {
        $statement = $database->query('SELECT * FROM work_report_links WHERE id = 90');
        self::assertInstanceOf(\PDOStatement::class, $statement);
        return $statement->fetch(PDO::FETCH_ASSOC);
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

    private function registry(bool $includeLinks = true): TenantDataRegistrySnapshot
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
            ...($includeLinks ? [CompanyBackupWorkReportLinksDefinition::definition()] : []),
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

    /**
     * @param array<int,string> $linkTokens
     * @return array{LinkHookImportSource,CompanyBackupDataPreflightResult,CompanyBackupReferenceDecisionPlan,?CompanyBackupWorkReportLinkDecisionPlan}
     */
    private function context(PDO $database, TenantDataRegistrySnapshot $registry, bool $mismatch,
        array $linkTokens = [31 => self::TOKEN]): array
    {
        $rows = [
            'table:supplier' => [['id' => 7, 'name' => 'Restored tenant']],
            'table:clients' => [
                ['id' => 11, 'supplier_id' => 7],
                ['id' => 12, 'supplier_id' => 7],
            ],
            'table:projects' => [['id' => 21, 'client_id' => $mismatch ? 12 : 11]],
        ];
        if ($registry->registry->definition('table:work_report_links') !== null) {
            $rows['table:work_report_links'] = [];
            foreach ($linkTokens as $id => $_) {
                $rows['table:work_report_links'][] = [
                    'id' => $id, 'supplier_id' => 7, 'scope' => 'project',
                    'client_id' => 11, 'project_id' => 21,
                    'created_by_user_id' => null, 'created_at' => '2026-01-01 01:02:03',
                    'last_sent_at' => null, 'last_viewed_at' => null,
                    'revoked_at' => '2026-01-03 04:05:06',
                ];
            }
        }
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
        $secretValues = [];
        foreach ($linkTokens as $id => $token) {
            if (isset($rows['table:work_report_links'])) {
                $secretValues[] = CompanyBackupSecretValue::fromPlaintext(
                    'table:work_report_links', CompanyBackupSecretScope::Column,
                    'token', ['id' => $id], $token,
                );
            }
        }
        $payload = CompanyBackupSecretPayload::fromValues($secretValues, $registry);
        $linkInventory = null;
        if (isset($rows['table:work_report_links'])) {
            $linkCollector = new CompanyBackupWorkReportLinkPreflightInventoryCollector();
            foreach ($payload->values() as $value) {
                if ($value->registryKey === 'table:work_report_links') {
                    $linkCollector->acceptSecret($value);
                }
            }
            foreach ($rows['table:work_report_links'] as $row) {
                $linkCollector->acceptRow($row);
            }
            $linkInventory = $linkCollector->finish($database);
        }
        $rowCount = 4 + count($rows['table:work_report_links'] ?? []);
        $preflight = new CompanyBackupDataPreflightResult(
            new CompanyBackupExternalReferenceInventory([]), $rowCount, $rowCount,
            6 + 2 * count($rows['table:work_report_links'] ?? []), 1_024, 0,
            $registry->fingerprint, self::TECHNICAL_BINDING,
            workReportLinkInventory: $linkInventory,
        );
        $decisions = CompanyBackupReferenceDecisionPlan::fromArray([
            'format' => CompanyBackupReferenceDecisionPlan::FORMAT,
            'version' => CompanyBackupReferenceDecisionPlan::VERSION,
            'data_preflight_binding_sha256' => $preflight->bindingSha256,
            'decisions' => [],
        ], $preflight, $registry, self::INSTANCE_ID, 91);
        $linkDecisions = $linkInventory === null ? null :
            CompanyBackupWorkReportLinkDecisionPlan::fromArray([
                'data_preflight_binding_sha256' => $preflight->bindingSha256,
                'link_inventory_sha256' => $linkInventory->sha256(),
                'decisions' => array_map(
                    static fn (array $entry): array => [
                        'source_link_id' => $entry['source_link_id'], 'action' => 'regenerate',
                    ],
                    array_values(array_filter($linkInventory->entries(),
                        static fn (array $entry): bool => $entry['collision'])),
                ),
            ], $linkInventory, $preflight->bindingSha256, $registry->fingerprint,
                self::INSTANCE_ID, 91);
        return [new LinkHookImportSource($registry, $inventory, $files, $rows, $payload),
            $preflight, $decisions, $linkDecisions];
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
