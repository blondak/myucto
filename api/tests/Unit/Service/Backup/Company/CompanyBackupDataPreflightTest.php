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
use MyInvoice\Service\Backup\Company\CompanyBackupImportArchiveSource;
use MyInvoice\Service\Backup\Company\CompanyBackupPayrollStatutoryResultSetAssembler;
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

    public function testRejectsSubmissionCorrelationCollisionFromEncryptedArchive(): void
    {
        $this->database->exec('CREATE TABLE submission_outbox (correlation_reference TEXT UNIQUE)');
        [$archive, $validation] = $this->archive(countryReference: 7, submission: true);
        $preflight = new CompanyBackupDataPreflight($this->limits());
        self::assertSame(4, $preflight->inspect($archive, self::PASSWORD, $validation, $this->database)->rowCount);
        $this->database->exec("INSERT INTO submission_outbox VALUES ('SYNTHETIC-ISDS-001')");
        try {
            $preflight->inspect($archive, self::PASSWORD, $validation, $this->database);
            self::fail('Kontrola archivu musí odmítnout cílovou kolizi.');
        } catch (CompanyBackupPreflightException $e) {
            self::assertSame('submission_correlation_collision', $e->errorCode);
        }
        self::assertSame(0, $this->temporaryIndexCount());
        self::assertSame('unchanged', $this->sentinelValue());
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
        self::assertSame(
            0,
            $this->temporaryIndexCount(),
            implode(', ', $this->temporaryIndexNames()),
        );
    }

    public function testVerifiedArchiveSourceReplaysCanonicalRows(): void
    {
        [$archive, $validation] = $this->archive(countryReference: 7);
        $source = new CompanyBackupImportArchiveSource(
            $archive,
            self::PASSWORD,
            $validation,
            $this->limits(),
        );
        $passes = [];
        for ($pass = 0; $pass < 2; $pass++) {
            $rows = [];
            $source->consumeRows(
                'table:supplier',
                static function (array $row) use (&$rows): void {
                    $rows[] = $row;
                },
            );
            $passes[] = $rows;
        }
        $source->close();

        self::assertSame([
            [[
                'id' => 42,
                'name' => 'Synthetic supplier',
            ]],
            [[
                'id' => 42,
                'name' => 'Synthetic supplier',
            ]],
        ], $passes);
        try {
            $source->consumeRows(
                'table:supplier',
                static function (array $row): void {},
            );
            self::fail('Zavřený zdroj archivu nesmí znovu číst payload.');
        } catch (CompanyBackupPreflightException $e) {
            self::assertSame('source_archive_reader_closed', $e->errorCode);
        }
    }

    public function testImportSourceRejectsNewPasswordAndChangedArchive(): void
    {
        [$archive, $validation] = $this->archive(countryReference: 7);
        $wrongPassword = new CompanyBackupImportArchiveSource(
            $archive,
            'different-synthetic-password',
            $validation,
            $this->limits(),
        );
        try {
            $wrongPassword->consumeRows(
                'table:supplier',
                static function (array $row): void {},
            );
            self::fail('Import musí nové heslo zálohy znovu ověřit.');
        } catch (CompanyBackupPreflightException $e) {
            self::assertSame('source_archive_unlock_failed', $e->errorCode);
        }
        $wrongPassword->close();

        $changed = new CompanyBackupImportArchiveSource(
            $archive,
            self::PASSWORD,
            $validation,
            $this->limits(),
        );
        self::assertIsInt(file_put_contents($archive, 'changed', FILE_APPEND));
        try {
            $changed->close();
            self::fail('Změněný upload nesmí zůstat platným importním zdrojem.');
        } catch (CompanyBackupPreflightException $e) {
            self::assertSame('source_archive_changed', $e->errorCode);
        }
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
        self::assertSame(
            0,
            $this->temporaryIndexCount(),
            implode(', ', $this->temporaryIndexNames()),
        );
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

    public function testRejectsBrokenStatutoryAggregateBeforeImportPlan(): void
    {
        [$archive, $validation] = $this->archive(
            countryReference: 7,
            statutoryAggregate: true,
        );

        try {
            (new CompanyBackupDataPreflight($this->limits()))->inspect(
                $archive,
                self::PASSWORD,
                $validation,
                $this->database,
            );
            self::fail('Neplatná kořenová pečeť musí zastavit datový preflight.');
        } catch (CompanyBackupPreflightException $e) {
            self::assertSame('data_aggregate_hash_value_invalid', $e->errorCode);
            self::assertSame('table:payroll_statutory_results', $e->registryKey);
            self::assertSame('result_set_hash', $e->column);
        }

        self::assertSame('unchanged', $this->sentinelValue());
        self::assertSame(
            0,
            $this->temporaryIndexCount(),
            implode(', ', $this->temporaryIndexNames()),
        );
    }

    public function testAcceptsCompleteStatutoryAggregateAndCleansBothIndexes(): void
    {
        [$archive, $validation] = $this->archive(
            countryReference: 7,
            statutoryAggregate: true,
            breakStatutoryAggregate: false,
        );

        $result = (new CompanyBackupDataPreflight($this->limits()))->inspect(
            $archive,
            self::PASSWORD,
            $validation,
            $this->database,
        );

        self::assertSame(6, $result->rowCount);
        self::assertSame(6, $result->identityCount);
        self::assertSame('unchanged', $this->sentinelValue());
        self::assertSame(0, $this->temporaryIndexCount());
    }

    /** @return array{string,CompanyBackupTechnicalValidation} */
    private function archive(
        int $countryReference,
        bool $statutoryAggregate = false,
        bool $breakStatutoryAggregate = true,
        bool $submission = false,
    ): array
    {
        $registry = $this->registry($statutoryAggregate, $submission);
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
        if ($submission) {
            $payloads['table:submission_outbox'] = self::jsonl([[
                'id' => 61, 'supplier_id' => 42, 'correlation_reference' => 'SYNTHETIC-ISDS-001',
            ]]);
        }
        if ($statutoryAggregate) {
            $person = $this->statutoryPerson();
            $relationship = $this->statutoryRelationship();
            $header = $this->statutoryHeader();
            $header['result_set_hash'] =
                CompanyBackupPayrollStatutoryResultSetAssembler::calculate(
                    $header,
                    [$person],
                    [$relationship],
                );
            if ($breakStatutoryAggregate) {
                $header['result_set_hash'] = str_repeat('f', 64);
            }
            $payloads['table:payroll_statutory_person_results'] =
                self::jsonl([$person]);
            $payloads['table:payroll_statutory_relationship_results'] =
                self::jsonl([$relationship]);
            $payloads['table:payroll_statutory_results'] = self::jsonl([$header]);
        }
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

    private function registry(bool $statutoryAggregate = false, bool $submission = false): TenantDataRegistry
    {
        $definitions = [
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
        ];
        if ($statutoryAggregate) {
            $definitions[] = $this->tableDefinition(
                'payroll_statutory_person_results',
                TenantDataPolicy::TenantOwned,
                array_keys($this->statutoryPerson()),
                preservedIdentifiers: [
                    'employee_id',
                    'revision_id',
                    'statutory_result_id',
                    'supplier_id',
                ],
            );
            $definitions[] = $this->tableDefinition(
                'payroll_statutory_relationship_results',
                TenantDataPolicy::TenantOwned,
                array_keys($this->statutoryRelationship()),
                preservedIdentifiers: [
                    'employee_id',
                    'employment_id',
                    'person_result_id',
                    'revision_id',
                    'statutory_result_id',
                    'supplier_id',
                ],
            );
            $definitions[] = $this->tableDefinition(
                'payroll_statutory_results',
                TenantDataPolicy::TenantOwned,
                array_keys($this->statutoryHeader()),
                preservedIdentifiers: [
                    'revision_id',
                    'ruleset_id',
                    'supplier_id',
                ],
            );
        }
        if ($submission) {
            $definitions[] = $this->tableDefinition('submission_outbox', TenantDataPolicy::TenantOwned,
                ['id', 'supplier_id', 'correlation_reference'], preservedIdentifiers: ['supplier_id']);
        }
        return new TenantDataRegistry(
            1,
            $definitions,
            [TenantDataRegistry::COMPANY_BACKUP_PROFILE],
        );
    }

    /** @return array<string,mixed> */
    private function statutoryHeader(): array
    {
        return [
            'id' => 31,
            'supplier_id' => 42,
            'revision_id' => 51,
            'calculation_kind' => 'social_insurance',
            'schema_version' => 'payroll-social-result.v1',
            'result_status' => 'calculated',
            'ruleset_id' => 'cz-social-2026.1',
            'ruleset_hash' => str_repeat('a', 64),
            'input_snapshot_json' => CanonicalJson::encode(['period' => '2026-06']),
            'result_snapshot_json' => CanonicalJson::encode(['amount_minor' => 2_500]),
            'result_set_hash' => str_repeat('0', 64),
        ];
    }

    /** @return array<string,mixed> */
    private function statutoryPerson(): array
    {
        return [
            'id' => 41,
            'supplier_id' => 42,
            'statutory_result_id' => 31,
            'revision_id' => 51,
            'calculation_kind' => 'social_insurance',
            'employee_id' => 17,
            'result_status' => 'calculated',
            'input_snapshot_json' => CanonicalJson::encode(['employee_id' => 17]),
            'result_snapshot_json' => CanonicalJson::encode(['amount_minor' => 1_700]),
        ];
    }

    /** @return array<string,mixed> */
    private function statutoryRelationship(): array
    {
        return [
            'id' => 61,
            'supplier_id' => 42,
            'statutory_result_id' => 31,
            'person_result_id' => 41,
            'revision_id' => 51,
            'calculation_kind' => 'social_insurance',
            'employee_id' => 17,
            'employment_id' => 19,
            'result_status' => 'calculated',
            'input_snapshot_json' => CanonicalJson::encode(['employment_id' => 19]),
            'result_snapshot_json' => CanonicalJson::encode(['amount_minor' => 190]),
        ];
    }

    /**
     * @param list<string> $dataColumns
     * @param list<string>|null $naturalKey
     * @param list<array<string,mixed>> $references
     * @param list<array<string,mixed>> $embeddedReferences
     * @param list<string> $preservedIdentifiers
     */
    private function tableDefinition(
        string $table,
        TenantDataPolicy $policy,
        array $dataColumns,
        ?array $naturalKey = null,
        array $references = [],
        array $embeddedReferences = [],
        array $preservedIdentifiers = [],
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
                    ...($preservedIdentifiers === [] ? [] : [
                        'preserved_identifiers' => $preservedIdentifiers,
                    ]),
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
            . " WHERE type = 'table' AND name LIKE 'company_backup_%'",
        );
        if ($statement === false) {
            throw new \RuntimeException('Nelze ověřit dočasné tabulky.');
        }
        $count = $statement->fetchColumn();
        return is_int($count) ? $count : (int) $count;
    }

    /** @return list<string> */
    private function temporaryIndexNames(): array
    {
        $statement = $this->database->query(
            "SELECT name FROM sqlite_temp_master"
            . " WHERE type = 'table' AND name LIKE 'company_backup_%'"
            . ' ORDER BY name',
        );
        if ($statement === false) {
            return [];
        }
        return array_values(array_filter(
            $statement->fetchAll(PDO::FETCH_COLUMN),
            is_string(...),
        ));
    }
}
