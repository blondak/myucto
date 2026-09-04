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
use MyInvoice\Service\Backup\Company\CompanyBackupSecretInventory;
use MyInvoice\Service\Backup\Company\CompanyBackupTechnicalValidation;
use MyInvoice\Service\Backup\Company\Upcast\BackupUpcasterRegistry;
use MyInvoice\Service\Backup\Registry\TenantDataDefinition;
use MyInvoice\Service\Backup\Registry\TenantDataObjectKind;
use MyInvoice\Service\Backup\Registry\TenantDataPolicy;
use MyInvoice\Service\Backup\Registry\TenantDataRegistry;
use MyInvoice\Service\Backup\Registry\TenantDataRegistrySnapshot;
use PDO;
use PHPUnit\Framework\TestCase;

final class CompanyBackupDataPreflightTest extends TestCase
{
    private const PASSWORD = 'synthetic-preflight-password-42';

    /** @var list<string> */
    private array $archives = [];

    private PDO $database;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite není dostupné pro izolovaný SQL test.');
        }
        $this->database = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $this->database->exec(
            "CREATE TABLE business_sentinel (id INTEGER PRIMARY KEY, value TEXT NOT NULL)",
        );
        $this->database->exec(
            "INSERT INTO business_sentinel (id, value) VALUES (1, 'unchanged')",
        );
    }

    protected function tearDown(): void
    {
        foreach ($this->archives as $archive) {
            if (is_file($archive) || is_link($archive)) {
                @unlink($archive);
            }
        }
    }

    public function testBuildsIndexThenNormalizesCompleteReferenceGraph(): void
    {
        [$archive, $validation] = $this->archive(countryReference: 7);

        $result = (new CompanyBackupDataPreflight($this->limits()))->inspect(
            $archive,
            self::PASSWORD,
            $validation,
            $this->database,
        );

        self::assertSame(3, $result->rowCount);
        self::assertSame(3, $result->identityCount);
        self::assertSame(6, $result->sourceKeyCount);
        self::assertSame(4, $result->referenceOccurrenceCount);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/D', $result->bindingSha256);
        $global = $result->externalReferences->find(
            CompanyBackupReferenceMapping::GlobalNaturalKey,
            'table:countries',
            ['iso2' => 'CZ'],
        );
        self::assertNotNull($global);
        self::assertSame(2, $global->occurrenceCount);
        self::assertNotNull($result->externalReferences->find(
            CompanyBackupReferenceMapping::Actor,
            'table:users',
            ['id' => 9],
        ));
        self::assertSame('unchanged', $this->sentinelValue());
        self::assertSame(0, $this->temporaryIndexCount());
    }

    public function testMissingInternalTargetFailsWithoutBusinessWriteAndCleansIndex(): void
    {
        [$archive, $validation] = $this->archive(countryReference: 8);

        try {
            (new CompanyBackupDataPreflight($this->limits()))->inspect(
                $archive,
                self::PASSWORD,
                $validation,
                $this->database,
            );
            self::fail('Reference mimo úplný zdrojový payload musí zastavit preflight.');
        } catch (CompanyBackupPreflightException $e) {
            self::assertSame('source_reference_unresolved', $e->errorCode);
            self::assertSame('table:synthetic_records', $e->registryKey);
            self::assertSame('country_id', $e->column);
            self::assertStringNotContainsString('8', $e->getMessage());
        }

        self::assertSame('unchanged', $this->sentinelValue());
        self::assertSame(0, $this->temporaryIndexCount());
    }

    public function testReferenceOccurrenceLimitFailsAndCleansIndex(): void
    {
        [$archive, $validation] = $this->archive(countryReference: 7);

        try {
            (new CompanyBackupDataPreflight(
                $this->limits(maxReferenceOccurrences: 3),
            ))->inspect(
                $archive,
                self::PASSWORD,
                $validation,
                $this->database,
            );
            self::fail('Limit musí zastavit i opakované interní reference.');
        } catch (CompanyBackupPreflightException $e) {
            self::assertSame(
                'source_reference_occurrence_limit_exceeded',
                $e->errorCode,
            );
            self::assertSame('table:synthetic_records', $e->registryKey);
            self::assertSame('snapshot_json', $e->column);
        }

        self::assertSame('unchanged', $this->sentinelValue());
        self::assertSame(0, $this->temporaryIndexCount());
    }

    /** @return array{string,CompanyBackupTechnicalValidation} */
    private function archive(int $countryReference): array
    {
        $registry = $this->registry();
        $snapshot = TenantDataRegistrySnapshot::fromRegistry(
            $registry,
            TenantDataRegistry::COMPANY_BACKUP_PROFILE,
        );
        $payloads = [
            'table:countries' => self::jsonl([[
                'id' => 7,
                'iso2' => 'CZ',
                'name' => 'Synthetic country',
            ]]),
            'table:synthetic_records' => self::jsonl([[
                'approved_by' => 9,
                'code' => 'REC-1',
                'country_id' => $countryReference,
                'id' => 31,
                'snapshot_json' => CanonicalJson::encode(['country' => 'CZ']),
                'supplier_id' => 42,
            ]]),
            'table:supplier' => self::jsonl([[
                'id' => 42,
                'name' => 'Synthetic supplier',
            ]]),
        ];
        $objects = [];
        foreach ($payloads as $registryKey => $payload) {
            $definition = $snapshot->registry->definition($registryKey);
            if (!$definition instanceof TenantDataDefinition) {
                throw new \LogicException('Syntetická definice nebyla nalezena.');
            }
            $objects[] = [
                'registry_key' => $registryKey,
                'path' => 'data/' . str_replace(':', '-', $registryKey) . '.jsonl',
                'order' => count($objects) + 1,
                'rows' => 1,
                'bytes' => strlen($payload),
                'sha256' => hash('sha256', $payload),
            ];
        }

        $path = tempnam(sys_get_temp_dir(), 'myucto-data-preflight-');
        if ($path === false) {
            throw new \RuntimeException('Nelze vytvořit syntetickou cestu archivu.');
        }
        @unlink($path);
        $path .= '.zip';
        $this->archives[] = $path;
        $format = new CompanyBackupFormat();
        $manifest = $format->parseManifest($format->encodeManifest([
            'product' => CompanyBackupFormat::PRODUCT,
            'format' => CompanyBackupFormat::FORMAT,
            'format_version' => ['major' => 1, 'minor' => 0],
            'backup_id' => '0191f7a0-7c22-7bd1-8cd4-6e18cb55b8a1',
            'source' => [
                'app_version' => '5.28.1',
                'schema_revision' => CompanyBackupFormat::CURRENT_SCHEMA_REVISION,
            ],
            'capabilities' => ['required' => [], 'optional' => []],
            'registry' => $snapshot->toArray(),
            'data' => [
                'format' => CompanyBackupDataInventory::FORMAT,
                'version' => CompanyBackupDataInventory::VERSION,
                'objects' => $objects,
            ],
            'files' => [
                'format' => CompanyBackupFileInventory::FORMAT,
                'version' => CompanyBackupFileInventory::VERSION,
                'areas' => [],
            ],
            'secrets' => [
                'format' => CompanyBackupSecretInventory::FORMAT,
                'version' => CompanyBackupSecretInventory::VERSION,
                'omissions' => [],
            ],
        ]));
        $writer = new CompanyBackupArchiveWriter(
            $path,
            self::PASSWORD,
            $format,
            $this->limits(),
        );
        foreach ($payloads as $registryKey => $payload) {
            $writer->addString(
                'data/' . str_replace(':', '-', $registryKey) . '.jsonl',
                $payload,
            );
        }
        $writer->finish($manifest, "Syntetická záloha.\n");
        $inspection = (new CompanyBackupArchiveInspector(
            $format,
            BackupUpcasterRegistry::empty(),
            $this->limits(),
        ))->inspect(
            $path,
            self::PASSWORD,
            '5.28.1',
            CompanyBackupFormat::CURRENT_SCHEMA_REVISION,
        );

        return [
            $path,
            new CompanyBackupTechnicalValidation(
                $inspection,
                $snapshot,
                '5.28.1',
                CompanyBackupFormat::CURRENT_SCHEMA_REVISION,
            ),
        ];
    }

    private function registry(): TenantDataRegistry
    {
        return new TenantDataRegistry(1, [
            $this->tableDefinition(
                'countries',
                TenantDataPolicy::GlobalReference,
                ['id', 'iso2', 'name'],
                naturalKey: ['iso2'],
            ),
            $this->tableDefinition(
                'supplier',
                TenantDataPolicy::TenantRoot,
                ['id', 'name'],
            ),
            $this->tableDefinition(
                'synthetic_records',
                TenantDataPolicy::TenantOwned,
                [
                    'approved_by',
                    'code',
                    'country_id',
                    'id',
                    'snapshot_json',
                    'supplier_id',
                ],
                naturalKey: ['supplier_id', 'code'],
                references: [
                    [
                        'columns' => ['approved_by'],
                        'target' => 'table:users',
                        'target_columns' => ['id'],
                        'mapping' => CompanyBackupReferenceMapping::Actor->value,
                        'constraint' => CompanyBackupReferenceConstraint::Optional->value,
                        'nullable_columns' => ['approved_by'],
                        'fallbacks' => ['restore_actor'],
                    ],
                    [
                        'columns' => ['country_id'],
                        'target' => 'table:countries',
                        'target_columns' => ['id'],
                        'mapping' =>
                            CompanyBackupReferenceMapping::GlobalNaturalKey->value,
                        'constraint' => CompanyBackupReferenceConstraint::Required->value,
                        'nullable_columns' => [],
                        'fallbacks' => [],
                    ],
                    [
                        'columns' => ['supplier_id'],
                        'target' => 'table:supplier',
                        'target_columns' => ['id'],
                        'mapping' => CompanyBackupReferenceMapping::TenantId->value,
                        'constraint' => CompanyBackupReferenceConstraint::Required->value,
                        'nullable_columns' => [],
                        'fallbacks' => [],
                    ],
                ],
                embeddedReferences: [[
                    'column' => 'snapshot_json',
                    'condition' => null,
                    'fallbacks' => [],
                    'mapping' => CompanyBackupReferenceMapping::GlobalNaturalKey->value,
                    'nullable' => false,
                    'path' => ['country'],
                    'target' => 'table:countries',
                    'target_columns' => ['iso2'],
                ]],
            ),
            new TenantDataDefinition(
                'table:users',
                TenantDataObjectKind::Table,
                TenantDataPolicy::InstanceOwned,
                [TenantDataRegistry::COMPANY_BACKUP_PROFILE],
                [
                    'primary_key' => ['id'],
                    'ownership' => ['strategy' => 'instance'],
                ],
            ),
        ], [TenantDataRegistry::COMPANY_BACKUP_PROFILE]);
    }

    /**
     * @param list<string> $dataColumns
     * @param list<string>|null $naturalKey
     * @param list<array<string,mixed>> $references
     * @param list<array<string,mixed>> $embeddedReferences
     */
    private function tableDefinition(
        string $table,
        TenantDataPolicy $policy,
        array $dataColumns,
        ?array $naturalKey = null,
        array $references = [],
        array $embeddedReferences = [],
    ): TenantDataDefinition {
        return new TenantDataDefinition(
            'table:' . $table,
            TenantDataObjectKind::Table,
            $policy,
            [TenantDataRegistry::COMPANY_BACKUP_PROFILE],
            [
                'primary_key' => ['id'],
                ...($naturalKey === null ? [] : ['natural_key' => $naturalKey]),
                'ownership' => ['strategy' => 'synthetic'],
                'secrets' => [],
                'company_backup' => [
                    'data_columns' => $dataColumns,
                    'embedded_references' => $embeddedReferences,
                    'generated_columns' => [],
                    'omit_columns' => [],
                    'references' => $references,
                    'restore_overrides' => [],
                ],
            ],
        );
    }

    private function limits(
        int $maxReferenceOccurrences = 100,
    ): CompanyBackupArchiveLimits {
        return new CompanyBackupArchiveLimits(
            maxArchiveBytes: 1_000_000,
            maxEntries: 30,
            maxEntryBytes: 40_000,
            maxExpandedBytes: 160_000,
            maxCompressionRatio: 1_000,
            maxManifestBytes: 40_000,
            maxChecksumsBytes: 8_192,
            maxReferenceRequirements: 20,
            maxSourceIdentities: 20,
            maxSourceIndexEntries: 80,
            maxSourceIndexBytes: 160_000,
            maxReferenceOccurrences: $maxReferenceOccurrences,
        );
    }

    /** @param list<array<string,mixed>> $rows */
    private static function jsonl(array $rows): string
    {
        return implode('', array_map(
            static fn (array $row): string => CanonicalJson::encode($row) . "\n",
            $rows,
        ));
    }

    private function sentinelValue(): string
    {
        $statement = $this->database->query(
            'SELECT value FROM business_sentinel WHERE id = 1',
        );
        if ($statement === false) {
            throw new \RuntimeException('Nelze ověřit kontrolní business řádek.');
        }
        $value = $statement->fetchColumn();
        return is_string($value) ? $value : '';
    }

    private function temporaryIndexCount(): int
    {
        $statement = $this->database->query(
            "SELECT COUNT(*) FROM sqlite_temp_master"
            . " WHERE type = 'table' AND name LIKE 'company_backup_source_%'",
        );
        if ($statement === false) {
            throw new \RuntimeException('Nelze ověřit dočasné tabulky.');
        }
        $count = $statement->fetchColumn();
        return is_int($count) ? $count : (int) $count;
    }
}
