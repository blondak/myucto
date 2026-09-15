<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup\Company;

use MyInvoice\Service\Backup\CanonicalJson;
use MyInvoice\Service\Backup\Company\CompanyBackupArchiveInspector;
use MyInvoice\Service\Backup\Company\CompanyBackupArchiveLimits;
use MyInvoice\Service\Backup\Company\CompanyBackupArchiveWriter;
use MyInvoice\Service\Backup\Company\CompanyBackupDataInventory;
use MyInvoice\Service\Backup\Company\CompanyBackupDataPreflight;
use MyInvoice\Service\Backup\Company\CompanyBackupFileInventory;
use MyInvoice\Service\Backup\Company\CompanyBackupFormat;
use MyInvoice\Service\Backup\Company\CompanyBackupPreflightException;
use MyInvoice\Service\Backup\Company\CompanyBackupReferenceConstraint;
use MyInvoice\Service\Backup\Company\CompanyBackupReferenceMapping;
use MyInvoice\Service\Backup\Company\CompanyBackupSecretEnvelopeCipher;
use MyInvoice\Service\Backup\Company\CompanyBackupSecretEnvelopeDescriptor;
use MyInvoice\Service\Backup\Company\CompanyBackupSecretInventory;
use MyInvoice\Service\Backup\Company\CompanyBackupSecretPayload;
use MyInvoice\Service\Backup\Company\CompanyBackupSecretScope;
use MyInvoice\Service\Backup\Company\CompanyBackupSecretValue;
use MyInvoice\Service\Backup\Company\CompanyBackupTechnicalValidation;
use MyInvoice\Service\Backup\Company\Upcast\BackupUpcasterRegistry;
use MyInvoice\Service\Backup\Registry\CompanyBackupWorkReportLinksDefinition;
use MyInvoice\Service\Backup\Registry\TenantDataDefinition;
use MyInvoice\Service\Backup\Registry\TenantDataObjectKind;
use MyInvoice\Service\Backup\Registry\TenantDataPolicy;
use MyInvoice\Service\Backup\Registry\TenantDataRegistry;
use MyInvoice\Service\Backup\Registry\TenantDataRegistrySnapshot;
use PDO;
use PHPUnit\Framework\TestCase;

final class CompanyBackupWorkReportLinkPreflightInspectTest extends TestCase
{
    private const PASSWORD = 'synthetic-work-link-preflight-password';
    private const TOKEN = '0123456789abcdef0123456789abcdef0123456789abcdef';
    private const BACKUP_ID = '0191f7a0-7c22-7bd1-8cd4-6e18cb55b8a1';

    /** @var list<string> */
    private array $archives = [];

    private PDO $database;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite není dostupné.');
        }
        $this->database = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->database->exec('CREATE TABLE work_report_links (id INTEGER PRIMARY KEY, supplier_id INTEGER, token TEXT UNIQUE, revoked_at TEXT)');
    }

    protected function tearDown(): void
    {
        foreach ($this->archives as $archive) {
            @unlink($archive);
        }
    }

    public function testEncryptedArchiveInspectBindsTargetCollisionIncludingRevokedLink(): void
    {
        [$archive, $validation] = $this->archive([self::linkRow()], [self::secret()]);
        $preflight = new CompanyBackupDataPreflight(self::limits());
        $clean = $preflight->inspect($archive, self::PASSWORD, $validation, $this->database);
        self::assertNotNull($clean->workReportLinkInventory);
        self::assertSame(1, $clean->workReportLinkInventory->count());
        self::assertSame(0, $clean->workReportLinkInventory->collisionCount());
        self::assertSame(hash('sha256', self::TOKEN), $clean->workReportLinkInventory->entries()[0]['token_fingerprint']);
        self::assertStringNotContainsString(self::TOKEN, CanonicalJson::encode($clean->toArray()));

        $this->database->exec("INSERT INTO work_report_links VALUES (90, 999, '" . self::TOKEN . "', '2026-01-01 00:00:00')");
        $colliding = $preflight->inspect($archive, self::PASSWORD, $validation, $this->database);
        self::assertNotNull($colliding->workReportLinkInventory);
        self::assertSame(1, $colliding->workReportLinkInventory->collisionCount());
        self::assertNotSame($clean->bindingSha256, $colliding->bindingSha256);
        self::assertStringNotContainsString(self::TOKEN, CanonicalJson::encode($colliding->toArray()));
    }

    public function testActiveEmptyObjectWithEncryptedEmptyPayloadProducesBoundEmptyInventory(): void
    {
        [$archive, $validation] = $this->archive([], []);
        $result = (new CompanyBackupDataPreflight(self::limits()))->inspect(
            $archive, self::PASSWORD, $validation, $this->database,
        );
        self::assertNotNull($result->workReportLinkInventory);
        self::assertSame(0, $result->workReportLinkInventory->count());
        self::assertSame($result->workReportLinkInventory->sha256(), $result->toArray()['work_report_link_inventory_sha256']);
    }

    public function testArchiveRowWithoutProtectedTokenFailsPreflight(): void
    {
        [$archive, $validation] = $this->archive([self::linkRow()], []);
        try {
            (new CompanyBackupDataPreflight(self::limits()))->inspect(
                $archive, self::PASSWORD, $validation, $this->database,
            );
            self::fail('Chybějící chráněný token musí zastavit preflight.');
        } catch (CompanyBackupPreflightException $e) {
            self::assertSame('work_report_link_secret_row_mismatch', $e->errorCode);
            self::assertStringNotContainsString(self::TOKEN, $e->getMessage());
        }
    }

    public function testOrphanProtectedTokenWithoutArchiveRowFailsPreflight(): void
    {
        [$archive, $validation] = $this->archive([], [self::secret()]);
        try {
            (new CompanyBackupDataPreflight(self::limits()))->inspect(
                $archive, self::PASSWORD, $validation, $this->database,
            );
            self::fail('Osamocený token v obálce musí zastavit preflight.');
        } catch (CompanyBackupPreflightException $e) {
            self::assertSame('work_report_link_secret_row_mismatch', $e->errorCode);
            self::assertStringNotContainsString(self::TOKEN, $e->getMessage());
        }
    }

    /**
     * @param list<array<string,mixed>> $links
     * @param list<CompanyBackupSecretValue> $secrets
     * @return array{string,CompanyBackupTechnicalValidation}
     */
    private function archive(array $links, array $secrets): array
    {
        $snapshot = TenantDataRegistrySnapshot::fromRegistry(self::registry(), TenantDataRegistry::COMPANY_BACKUP_PROFILE);
        $payload = CompanyBackupSecretPayload::fromValues($secrets, $snapshot);
        $sealed = (new CompanyBackupSecretEnvelopeCipher())->seal(
            $payload->toJson(), self::PASSWORD, self::BACKUP_ID, $snapshot->fingerprint,
        );
        $rowsByKey = [
            'table:clients' => [['id' => 17, 'supplier_id' => 42]],
            'table:projects' => [],
            'table:supplier' => [['id' => 42]],
            'table:work_report_links' => $links,
        ];
        $dataObjects = [];
        $contents = [];
        foreach ($rowsByKey as $key => $rows) {
            $path = 'data/' . str_replace(':', '-', $key) . '.jsonl';
            $jsonl = implode('', array_map(static fn (array $row): string => CanonicalJson::encode($row) . "\n", $rows));
            $contents[$path] = $jsonl;
            $dataObjects[] = [
                'registry_key' => $key,
                'path' => $path,
                'order' => count($dataObjects) + 1,
                'rows' => count($rows),
                'bytes' => strlen($jsonl),
                'sha256' => hash('sha256', $jsonl),
            ];
        }
        $secretInventory = CompanyBackupSecretInventory::fromCounts([], $snapshot)->toArray();
        $secretInventory['envelope'] = $sealed->descriptor->toArray();
        $format = new CompanyBackupFormat([CompanyBackupSecretEnvelopeDescriptor::CAPABILITY]);
        $manifest = $format->parseManifest($format->encodeManifest([
            'product' => CompanyBackupFormat::PRODUCT,
            'format' => CompanyBackupFormat::FORMAT,
            'format_version' => ['major' => 1, 'minor' => 0],
            'backup_id' => self::BACKUP_ID,
            'source' => ['app_version' => '5.28.1', 'schema_revision' => CompanyBackupFormat::CURRENT_SCHEMA_REVISION],
            'capabilities' => ['required' => [CompanyBackupSecretEnvelopeDescriptor::CAPABILITY], 'optional' => []],
            'registry' => $snapshot->toArray(),
            'data' => ['format' => CompanyBackupDataInventory::FORMAT, 'version' => CompanyBackupDataInventory::VERSION, 'objects' => $dataObjects],
            'files' => ['format' => CompanyBackupFileInventory::FORMAT, 'version' => CompanyBackupFileInventory::VERSION, 'areas' => []],
            'secrets' => $secretInventory,
        ]));
        $temp = tempnam(sys_get_temp_dir(), 'myucto-link-preflight-');
        if ($temp === false) {
            throw new \RuntimeException('Nelze vytvořit cestu archivu.');
        }
        @unlink($temp);
        $archive = $temp . '.zip';
        $this->archives[] = $archive;
        $writer = new CompanyBackupArchiveWriter($archive, self::PASSWORD, $format, self::limits());
        foreach ($contents as $path => $jsonl) {
            $writer->addString($path, $jsonl);
        }
        $writer->addSecretEnvelope($sealed);
        $writer->finish($manifest, "Syntetická záloha.\n");
        $inspection = (new CompanyBackupArchiveInspector($format, BackupUpcasterRegistry::empty(), self::limits()))->inspect(
            $archive, self::PASSWORD, '5.28.1', CompanyBackupFormat::CURRENT_SCHEMA_REVISION,
        );
        return [$archive, new CompanyBackupTechnicalValidation(
            $inspection, $snapshot, '5.28.1', CompanyBackupFormat::CURRENT_SCHEMA_REVISION,
        )];
    }

    private static function registry(): TenantDataRegistry
    {
        $definitions = [
            self::table('clients', TenantDataPolicy::TenantOwned, ['id', 'supplier_id']),
            self::table('projects', TenantDataPolicy::TenantOwned, ['id', 'supplier_id', 'client_id']),
            self::table('supplier', TenantDataPolicy::TenantRoot, ['id']),
            CompanyBackupWorkReportLinksDefinition::definition(),
            new TenantDataDefinition('table:users', TenantDataObjectKind::Table, TenantDataPolicy::InstanceOwned,
                [TenantDataRegistry::COMPANY_BACKUP_PROFILE], ['primary_key' => ['id'], 'ownership' => ['strategy' => 'instance']]),
        ];
        return new TenantDataRegistry(1, $definitions, [TenantDataRegistry::COMPANY_BACKUP_PROFILE]);
    }

    /** @param list<string> $columns */
    private static function table(string $name, TenantDataPolicy $policy, array $columns): TenantDataDefinition
    {
        return new TenantDataDefinition('table:' . $name, TenantDataObjectKind::Table, $policy,
            [TenantDataRegistry::COMPANY_BACKUP_PROFILE], [
                'primary_key' => ['id'],
                'ownership' => ['strategy' => $policy === TenantDataPolicy::TenantRoot ? 'synthetic' : 'supplier_id', 'column' => 'supplier_id'],
                'secrets' => [],
                'company_backup' => ['data_columns' => $columns, 'embedded_references' => [], 'generated_columns' => [],
                    'omit_columns' => [], 'references' => array_values(array_map(
                        static fn (string $column): array => [
                            'columns' => [$column],
                            'target' => $column === 'client_id' ? 'table:clients' : 'table:supplier',
                            'target_columns' => ['id'],
                            'mapping' => CompanyBackupReferenceMapping::TenantId->value,
                            'constraint' => CompanyBackupReferenceConstraint::Required->value,
                            'nullable_columns' => [],
                            'fallbacks' => [],
                        ],
                        array_intersect(['client_id', 'supplier_id'], $columns),
                    )), 'restore_overrides' => []],
            ]);
    }

    /** @return array<string,mixed> */
    private static function linkRow(): array
    {
        return [
            'id' => 11, 'supplier_id' => 42, 'scope' => 'client', 'client_id' => 17,
            'project_id' => null, 'created_by_user_id' => null,
            'created_at' => '2026-01-01 00:00:00', 'last_sent_at' => null,
            'last_viewed_at' => null, 'revoked_at' => '2026-01-02 00:00:00',
        ];
    }

    private static function secret(): CompanyBackupSecretValue
    {
        return CompanyBackupSecretValue::fromPlaintext(
            'table:work_report_links', CompanyBackupSecretScope::Column, 'token', ['id' => 11], self::TOKEN,
        );
    }

    private static function limits(): CompanyBackupArchiveLimits
    {
        return new CompanyBackupArchiveLimits(maxArchiveBytes: 1_000_000, maxEntries: 30,
            maxEntryBytes: 40_000, maxExpandedBytes: 160_000,
            maxCompressionRatio: 1_000, maxManifestBytes: 40_000, maxChecksumsBytes: 8_192,
            maxReferenceRequirements: 20, maxSourceIdentities: 20,
            maxSourceIndexEntries: 80, maxSourceIndexBytes: 160_000,
            maxReferenceOccurrences: 100);
    }
}
