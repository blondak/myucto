<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup\Company;

use MyInvoice\Service\Backup\Company\CompanyBackupImportWriteException;
use MyInvoice\Service\Backup\Company\CompanyBackupPreparedImportRow;
use MyInvoice\Service\Backup\Company\CompanyBackupReferenceConstraint;
use MyInvoice\Service\Backup\Company\CompanyBackupReferenceMapping;
use MyInvoice\Service\Backup\Company\CompanyBackupSourceIdentityProjection;
use MyInvoice\Service\Backup\Company\CompanyBackupSqlInsertWriter;
use MyInvoice\Service\Backup\Company\CompanyBackupTableSchema;
use MyInvoice\Service\Backup\Registry\TenantDataDefinition;
use MyInvoice\Service\Backup\Registry\TenantDataObjectKind;
use MyInvoice\Service\Backup\Registry\TenantDataPolicy;
use MyInvoice\Service\Backup\Registry\TenantDataRegistry;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;

final class CompanyBackupSqlInsertWriterTest extends TestCase
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
        $this->database->exec(
            'CREATE TABLE synthetic_records ('
                . 'id INTEGER PRIMARY KEY, supplier_id INTEGER NOT NULL,'
                . 'payload_binary BLOB NULL, label TEXT NOT NULL)',
        );
        $this->database->exec(
            'CREATE TABLE protected_records ('
                . 'id INTEGER PRIMARY KEY, supplier_id INTEGER NOT NULL,'
                . 'label TEXT NOT NULL, personal_ciphertext BLOB NULL,'
                . 'personal_hash BLOB NULL, personal_masked TEXT NULL)',
        );
    }

    public function testInsertsAllowlistedRowDecodesCodecAndKeepsTransactionOpen(): void
    {
        $definition = $this->definition();
        self::assertTrue($this->database->beginTransaction());
        $writer = new CompanyBackupSqlInsertWriter(
            $this->database,
            $definition,
            new CompanyBackupTableSchema(
                ['id', 'supplier_id', 'payload_binary', 'label'],
                [],
                ['id'],
                ['payload_binary'],
            ),
            1,
        );

        $writer->insert($this->prepared(
            $definition,
            [
                'id' => 11,
                'supplier_id' => 7,
                'payload_binary' => '00ff41',
                'label' => 'Source',
            ],
            [
                'id' => 101,
                'supplier_id' => 71,
                'payload_binary' => '00ff41',
                'label' => 'Imported',
            ],
        ));
        self::assertSame(1, $writer->insertedRows());
        $writer->finish();

        $statement = $this->database->query(
            'SELECT id, supplier_id, hex(payload_binary) AS payload_hex, label'
                . ' FROM synthetic_records',
        );
        if (!$statement instanceof PDOStatement) {
            throw new \RuntimeException('Kontrolní řádek nelze načíst.');
        }
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        self::assertSame([
            'id' => 101,
            'supplier_id' => 71,
            'payload_hex' => '00FF41',
            'label' => 'Imported',
        ], $row);
        self::assertTrue($this->database->inTransaction());
        self::assertTrue($this->database->rollBack());
        self::assertSame(0, $this->rowCount('synthetic_records'));
    }

    public function testRequiresExactRowIdentityAndManifestCount(): void
    {
        $definition = $this->definition();
        self::assertTrue($this->database->beginTransaction());
        $writer = new CompanyBackupSqlInsertWriter(
            $this->database,
            $definition,
            $this->schema(),
            1,
        );
        $valid = $this->prepared(
            $definition,
            [
                'id' => 11,
                'supplier_id' => 7,
                'payload_binary' => null,
                'label' => 'Source',
            ],
            [
                'id' => 101,
                'supplier_id' => 71,
                'payload_binary' => null,
                'label' => 'Imported',
            ],
        );
        $wrongIdentity = $this->prepared(
            $definition,
            [
                'id' => 11,
                'supplier_id' => 7,
                'payload_binary' => null,
                'label' => 'Source',
            ],
            [
                'id' => 102,
                'supplier_id' => 71,
                'payload_binary' => null,
                'label' => 'Different identity',
            ],
        );
        $wrongIdentity = new CompanyBackupPreparedImportRow(
            $valid->row,
            $valid->sourceIdentity,
            $wrongIdentity->targetIdentity,
        );

        $this->assertWriteError(
            'import_row_insert_invalid',
            fn () => $writer->insert($wrongIdentity),
        );
        $invalidValue = new CompanyBackupPreparedImportRow(
            [...$valid->row, 'label' => ['not', 'scalar']],
            $valid->sourceIdentity,
            $valid->targetIdentity,
        );
        $this->assertWriteError(
            'import_row_insert_invalid',
            fn () => $writer->insert($invalidValue),
        );
        self::assertSame(0, $writer->insertedRows());
        $this->assertWriteError(
            'import_row_count_incomplete',
            fn () => $writer->finish(),
        );
        $writer->insert($valid);
        $this->assertWriteError(
            'import_row_count_exceeded',
            fn () => $writer->insert($valid),
        );
        $writer->finish();
        $this->assertWriteError(
            'import_insert_writer_closed',
            fn () => $writer->insert($valid),
        );
        self::assertTrue($this->database->rollBack());
    }

    public function testRequiresEveryProtectedOutputAndWritesNoOtherSecretColumn(): void
    {
        $definition = $this->protectedDefinition();
        $schema = new CompanyBackupTableSchema(
            [
                'id',
                'supplier_id',
                'label',
                'personal_ciphertext',
                'personal_hash',
                'personal_masked',
            ],
            [],
            ['id'],
            ['personal_ciphertext', 'personal_hash'],
        );
        self::assertTrue($this->database->beginTransaction());
        $writer = new CompanyBackupSqlInsertWriter(
            $this->database,
            $definition,
            $schema,
            1,
        );
        $prepared = $this->prepared(
            $definition,
            ['id' => 5, 'supplier_id' => 7, 'label' => 'Source'],
            ['id' => 105, 'supplier_id' => 71, 'label' => 'Imported'],
        );

        $this->assertWriteError(
            'import_protected_values_invalid',
            fn () => $writer->insert($prepared, [
                'personal_ciphertext' => "cipher\0text",
                'personal_hash' => "hash\0value",
            ]),
        );
        self::assertSame(0, $this->rowCount('protected_records'));
        $writer->insert($prepared, [
            'personal_masked' => '******1234',
            'personal_hash' => "hash\0value",
            'personal_ciphertext' => "cipher\0text",
        ]);
        $writer->finish();

        $statement = $this->database->query(
            'SELECT id, supplier_id, label, hex(personal_ciphertext) AS cipher,'
                . ' hex(personal_hash) AS lookup_hash, personal_masked'
                . ' FROM protected_records',
        );
        if (!$statement instanceof PDOStatement) {
            throw new \RuntimeException('Kontrolní secret řádek nelze načíst.');
        }
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        self::assertSame([
            'id' => 105,
            'supplier_id' => 71,
            'label' => 'Imported',
            'cipher' => strtoupper(bin2hex("cipher\0text")),
            'lookup_hash' => strtoupper(bin2hex("hash\0value")),
            'personal_masked' => '******1234',
        ], $row);
        self::assertTrue($this->database->rollBack());
    }

    public function testFailsClosedOutsideTransactionAndHidesDatabaseValues(): void
    {
        $definition = $this->definition();
        $this->assertWriteError(
            'import_transaction_required',
            fn () => new CompanyBackupSqlInsertWriter(
                $this->database,
                $definition,
                $this->schema(),
                1,
            ),
        );

        self::assertTrue($this->database->beginTransaction());
        $lostTransaction = new CompanyBackupSqlInsertWriter(
            $this->database,
            $definition,
            $this->schema(),
            1,
        );
        self::assertTrue($this->database->rollBack());
        $this->assertWriteError(
            'import_transaction_lost',
            fn () => $lostTransaction->insert($this->prepared(
                $definition,
                [
                    'id' => 11,
                    'supplier_id' => 7,
                    'payload_binary' => null,
                    'label' => 'Source',
                ],
                [
                    'id' => 101,
                    'supplier_id' => 71,
                    'payload_binary' => null,
                    'label' => 'Imported',
                ],
            )),
        );

        $this->database->exec(
            "INSERT INTO synthetic_records"
                . " (id, supplier_id, payload_binary, label)"
                . " VALUES (101, 71, NULL, 'Existing')",
        );
        self::assertTrue($this->database->beginTransaction());
        $writer = new CompanyBackupSqlInsertWriter(
            $this->database,
            $definition,
            $this->schema(),
            1,
        );
        $prepared = $this->prepared(
            $definition,
            [
                'id' => 11,
                'supplier_id' => 7,
                'payload_binary' => null,
                'label' => 'source-secret-marker',
            ],
            [
                'id' => 101,
                'supplier_id' => 71,
                'payload_binary' => null,
                'label' => 'target-secret-marker',
            ],
        );

        try {
            $writer->insert($prepared);
            self::fail('Kolize primárního klíče musí zastavit import.');
        } catch (CompanyBackupImportWriteException $e) {
            self::assertSame('import_row_insert_failed', $e->errorCode);
            self::assertStringNotContainsString('source-secret-marker', $e->getMessage());
            self::assertStringNotContainsString('target-secret-marker', $e->getMessage());
            self::assertNotNull($e->getPrevious());
        }
        self::assertTrue($this->database->rollBack());
    }

    private function definition(): TenantDataDefinition
    {
        return new TenantDataDefinition(
            'table:synthetic_records',
            TenantDataObjectKind::Table,
            TenantDataPolicy::TenantOwned,
            [TenantDataRegistry::COMPANY_BACKUP_PROFILE],
            [
                'primary_key' => ['id'],
                'ownership' => [
                    'strategy' => 'supplier_id',
                    'column' => 'supplier_id',
                ],
                'secrets' => [],
                'company_backup' => [
                    'column_codecs' => ['payload_binary' => 'binary_hex'],
                    'data_columns' => [
                        'id',
                        'supplier_id',
                        'payload_binary',
                        'label',
                    ],
                    'embedded_references' => [],
                    'generated_columns' => [],
                    'omit_columns' => [],
                    'references' => [$this->supplierReference()],
                    'restore_overrides' => [],
                ],
            ],
        );
    }

    private function protectedDefinition(): TenantDataDefinition
    {
        return new TenantDataDefinition(
            'table:protected_records',
            TenantDataObjectKind::Table,
            TenantDataPolicy::TenantOwned,
            [TenantDataRegistry::COMPANY_BACKUP_PROFILE],
            [
                'primary_key' => ['id'],
                'ownership' => [
                    'strategy' => 'supplier_id',
                    'column' => 'supplier_id',
                ],
                'secrets' => [
                    'personal_ciphertext' => [
                        'policy' => 'protected_domain_secret',
                        'storage' => 'application_encrypted_context',
                        'context' =>
                            'payroll:{supplier_id}:{id}:personal_identifier',
                    ],
                ],
                'company_backup' => [
                    'data_columns' => ['id', 'supplier_id', 'label'],
                    'embedded_references' => [],
                    'generated_columns' => [],
                    'omit_columns' => [
                        'personal_hash' => 'rederived_from_protected_secret',
                        'personal_masked' => 'rederived_from_protected_secret',
                    ],
                    'protected_secret_materializations' => [[
                        'entity_id_column' => 'id',
                        'field' => 'personal_identifier',
                        'materializer' => 'payroll_sensitive_v1',
                        'nullable' => true,
                        'secret_column' => 'personal_ciphertext',
                        'target_columns' => [
                            'ciphertext' => 'personal_ciphertext',
                            'lookup_hash' => 'personal_hash',
                            'masked' => 'personal_masked',
                        ],
                        'tenant_id_column' => 'supplier_id',
                    ]],
                    'references' => [$this->supplierReference()],
                    'restore_overrides' => [],
                ],
            ],
        );
    }

    /**
     * @param array<string,mixed> $sourceRow
     * @param array<string,mixed> $targetRow
     */
    private function prepared(
        TenantDataDefinition $definition,
        array $sourceRow,
        array $targetRow,
    ): CompanyBackupPreparedImportRow {
        $identity = CompanyBackupSourceIdentityProjection::fromDefinition($definition);
        return new CompanyBackupPreparedImportRow(
            $targetRow,
            $identity->identityForRow($sourceRow),
            $identity->identityForRow($targetRow),
        );
    }

    private function schema(): CompanyBackupTableSchema
    {
        return new CompanyBackupTableSchema(
            ['id', 'supplier_id', 'payload_binary', 'label'],
            [],
            ['id'],
            ['payload_binary'],
        );
    }

    /**
     * @return array{
     *   columns:list<string>,
     *   target:string,
     *   target_columns:list<string>,
     *   mapping:string,
     *   constraint:string,
     *   nullable_columns:list<string>,
     *   fallbacks:list<string>
     * }
     */
    private function supplierReference(): array
    {
        return [
            'columns' => ['supplier_id'],
            'target' => 'table:supplier',
            'target_columns' => ['id'],
            'mapping' => CompanyBackupReferenceMapping::TenantId->value,
            'constraint' => CompanyBackupReferenceConstraint::Required->value,
            'nullable_columns' => [],
            'fallbacks' => [],
        ];
    }

    private function rowCount(string $table): int
    {
        $statement = $this->database->query('SELECT COUNT(*) FROM "' . $table . '"');
        if ($statement === false) {
            throw new \RuntimeException('Kontrolní počet řádků nelze načíst.');
        }
        return (int) $statement->fetchColumn();
    }

    /** @param callable():mixed $operation */
    private function assertWriteError(
        string $errorCode,
        callable $operation,
    ): void {
        try {
            $operation();
            self::fail('Neplatný SQL import musí být odmítnut.');
        } catch (CompanyBackupImportWriteException $e) {
            self::assertSame($errorCode, $e->errorCode);
        }
    }
}
