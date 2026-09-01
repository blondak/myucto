<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup\Company;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Backup\Company\CompanyBackupDataSourceException;
use MyInvoice\Service\Backup\Company\CompanyBackupOptionalSecretProjection;
use MyInvoice\Service\Backup\Company\CompanyBackupSecretSelection;
use MyInvoice\Service\Backup\Company\CompanyBackupSecretStorage;
use MyInvoice\Service\Backup\Company\CompanyBackupSqlOptionalSecretSource;
use MyInvoice\Service\Backup\Registry\TenantDataDefinition;
use MyInvoice\Service\Backup\Registry\TenantDataObjectKind;
use MyInvoice\Service\Backup\Registry\TenantDataPolicy;
use MyInvoice\Service\Backup\Registry\TenantDataRegistry;
use MyInvoice\Service\Backup\Registry\TenantDataRegistryFactory;
use MyInvoice\Service\Backup\Registry\TenantDataRegistrySnapshot;
use MyInvoice\Service\Backup\Registry\TenantSecretPolicy;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;

final class CompanyBackupSqlOptionalSecretSourceTest extends TestCase
{
    public function testReadsAndDecryptsOnlyExplicitTenantRowAndColumn(): void
    {
        $encryption = $this->encryption();
        $encrypted = $encryption->encrypt('synthetic-smtp-password');
        $data = $this->statement(
            [['id' => 31, 'smtp_password_enc' => $encrypted]],
            [7, 31],
        );
        $sql = '';
        $pdo = $this->schemaPdo($data, $sql, [
            $this->schemaColumn('id', 'int', 'auto_increment'),
            $this->schemaColumn('supplier_id', 'int'),
            $this->schemaColumn('code', 'varchar'),
            $this->schemaColumn('imap_password_enc', 'varchar'),
            $this->schemaColumn('smtp_password_enc', 'varchar'),
        ]);
        $definition = $this->definition();
        $selection = $this->selection($definition, [[
            'registry_key' => $definition->key,
            'scope' => 'column',
            'name' => 'smtp_password_enc',
            'primary_key' => ['id' => 31],
        ]]);
        $projection = CompanyBackupOptionalSecretProjection::fromSelection(
            $definition,
            $selection->entriesFor($definition->key),
        );

        $values = iterator_to_array(
            (new CompanyBackupSqlOptionalSecretSource($encryption))->values(
                $pdo,
                7,
                $projection,
            ),
            false,
        );

        self::assertCount(1, $values);
        self::assertSame('smtp_password_enc', $values[0]->name);
        self::assertSame(['id' => 31], $values[0]->primaryKey);
        self::assertSame('synthetic-smtp-password', $values[0]->plaintext());
        self::assertStringContainsString(
            'SELECT `_company_optional_secret`.`id`, '
                . '`_company_optional_secret`.`smtp_password_enc` '
                . 'FROM `email_profiles`',
            $sql,
        );
        self::assertStringContainsString(
            'WHERE `_company_optional_secret`.`supplier_id` = ? '
                . 'AND `_company_optional_secret`.`id` = ? LIMIT 2',
            $sql,
        );
        self::assertStringNotContainsString('imap_password_enc', $sql);
        self::assertStringNotContainsString($encrypted, $sql);
    }

    public function testMissingSelectedValueFailsWithoutFallingBackToOmission(): void
    {
        $definition = $this->definition();
        $selection = $this->selection($definition, [[
            'registry_key' => $definition->key,
            'scope' => 'column',
            'name' => 'smtp_password_enc',
            'primary_key' => ['id' => 31],
        ]]);
        $data = $this->statement([], [7, 31]);
        $sql = '';
        $pdo = $this->schemaPdo($data, $sql, [
            $this->schemaColumn('id', 'int', 'auto_increment'),
            $this->schemaColumn('supplier_id', 'int'),
            $this->schemaColumn('imap_password_enc', 'varchar'),
            $this->schemaColumn('smtp_password_enc', 'varchar'),
        ]);

        try {
            iterator_to_array((new CompanyBackupSqlOptionalSecretSource(
                $this->encryption(),
            ))->values(
                $pdo,
                7,
                CompanyBackupOptionalSecretProjection::fromSelection(
                    $definition,
                    $selection->entries(),
                ),
            ));
            self::fail('Vybraný credential nesmí při změně zdroje tiše zmizet.');
        } catch (CompanyBackupDataSourceException $e) {
            self::assertSame('secret_selected_value_missing', $e->errorCode);
            self::assertSame('smtp_password_enc', $e->column);
        }
    }

    public function testProjectionRejectsOptionalSecretWithoutStorageContract(): void
    {
        $definition = $this->definition();
        $details = $definition->details;
        unset($details['secrets']['smtp_password_enc']['storage']);
        $invalid = new TenantDataDefinition(
            $definition->key,
            $definition->kind,
            $definition->policy,
            $definition->profiles,
            $details,
        );
        $selection = $this->selection($invalid, [[
            'registry_key' => $invalid->key,
            'scope' => 'column',
            'name' => 'smtp_password_enc',
            'primary_key' => ['id' => 31],
        ]]);

        try {
            CompanyBackupOptionalSecretProjection::fromSelection(
                $invalid,
                $selection->entries(),
            );
            self::fail('Vybraný credential bez at-rest kontraktu nesmí být čten.');
        } catch (CompanyBackupDataSourceException $e) {
            self::assertSame('secret_source_storage_missing', $e->errorCode);
            self::assertSame('smtp_password_enc', $e->column);
        }
    }

    public function testProjectionRejectsDynamicContextForOptionalCredential(): void
    {
        $definition = $this->definition();
        $details = $definition->details;
        $details['secrets']['smtp_password_enc']['storage'] =
            CompanyBackupSecretStorage::ApplicationEncryptedContext->value;
        $details['secrets']['smtp_password_enc']['context'] =
            'backup:{supplier_id}:{id}:smtp_password';
        $invalid = new TenantDataDefinition(
            $definition->key,
            $definition->kind,
            $definition->policy,
            $definition->profiles,
            $details,
        );
        $selection = $this->selection($invalid, [[
            'registry_key' => $invalid->key,
            'scope' => 'column',
            'name' => 'smtp_password_enc',
            'primary_key' => ['id' => 31],
        ]]);

        try {
            CompanyBackupOptionalSecretProjection::fromSelection(
                $invalid,
                $selection->entries(),
            );
            self::fail(
                'Volitelný credential nesmí používat řádkový context template.',
            );
        } catch (CompanyBackupDataSourceException $e) {
            self::assertSame('secret_source_storage_invalid', $e->errorCode);
            self::assertSame('smtp_password_enc', $e->column);
        }
    }

    public function testProductionOptionalColumnsDeclareExactAtRestStorage(): void
    {
        $draft = TenantDataRegistryFactory::draftV1();
        $definitions = [];
        foreach ([
            'table:email_profiles',
            'table:signing_profiles',
            'table:supplier',
        ] as $registryKey) {
            $definition = $draft->definition($registryKey);
            self::assertNotNull($definition);
            $definitions[] = $definition;
        }
        $registry = TenantDataRegistrySnapshot::fromRegistry(
            new TenantDataRegistry(
                $draft->version,
                $definitions,
                [TenantDataRegistry::COMPANY_BACKUP_PROFILE],
            ),
            TenantDataRegistry::COMPANY_BACKUP_PROFILE,
        );
        $selection = CompanyBackupSecretSelection::fromArray([
            'registry_fingerprint' => $registry->fingerprint,
            'entries' => [
                [
                    'registry_key' => 'table:supplier',
                    'scope' => 'column',
                    'name' => 'idoklad_client_id',
                    'primary_key' => ['id' => 7],
                ],
                [
                    'registry_key' => 'table:supplier',
                    'scope' => 'column',
                    'name' => 'openai_api_key_enc',
                    'primary_key' => ['id' => 7],
                ],
                [
                    'registry_key' => 'table:email_profiles',
                    'scope' => 'column',
                    'name' => 'smtp_password_enc',
                    'primary_key' => ['id' => 31],
                ],
                [
                    'registry_key' => 'table:signing_profiles',
                    'scope' => 'column',
                    'name' => 'pdf_tsa_password_enc',
                    'primary_key' => ['id' => 41],
                ],
            ],
        ], $registry);

        $supplier = $registry->registry->definition('table:supplier');
        $email = $registry->registry->definition('table:email_profiles');
        $signing = $registry->registry->definition('table:signing_profiles');
        self::assertNotNull($supplier);
        self::assertNotNull($email);
        self::assertNotNull($signing);
        $supplierProjection = CompanyBackupOptionalSecretProjection::fromSelection(
            $supplier,
            $selection->entriesFor($supplier->key),
        );
        self::assertSame(
            CompanyBackupSecretStorage::Raw,
            $supplierProjection->storage['idoklad_client_id'] ?? null,
        );
        self::assertSame(
            CompanyBackupSecretStorage::ApplicationEncrypted,
            $supplierProjection->storage['openai_api_key_enc'] ?? null,
        );
        self::assertSame(
            CompanyBackupSecretStorage::ApplicationEncrypted,
            CompanyBackupOptionalSecretProjection::fromSelection(
                $email,
                $selection->entriesFor($email->key),
            )->storage['smtp_password_enc'] ?? null,
        );
        self::assertSame(
            CompanyBackupSecretStorage::ApplicationEncrypted,
            CompanyBackupOptionalSecretProjection::fromSelection(
                $signing,
                $selection->entriesFor($signing->key),
            )->storage['pdf_tsa_password_enc'] ?? null,
        );
    }

    private function definition(): TenantDataDefinition
    {
        return new TenantDataDefinition(
            'table:email_profiles',
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
                    'imap_password_enc' => [
                        'policy' => TenantSecretPolicy::OptionalCredential->value,
                        'storage' => CompanyBackupSecretStorage::ApplicationEncrypted->value,
                    ],
                    'smtp_password_enc' => [
                        'policy' => TenantSecretPolicy::OptionalCredential->value,
                        'storage' => CompanyBackupSecretStorage::ApplicationEncrypted->value,
                    ],
                ],
            ],
        );
    }

    /**
     * @param list<array<string,mixed>> $entries
     */
    private function selection(
        TenantDataDefinition $definition,
        array $entries,
    ): CompanyBackupSecretSelection {
        $registry = TenantDataRegistrySnapshot::fromRegistry(new TenantDataRegistry(
            1,
            [$definition],
            [TenantDataRegistry::COMPANY_BACKUP_PROFILE],
        ), TenantDataRegistry::COMPANY_BACKUP_PROFILE);
        return CompanyBackupSecretSelection::fromArray([
            'registry_fingerprint' => $registry->fingerprint,
            'entries' => $entries,
        ], $registry);
    }

    private function encryption(): SecretEncryption
    {
        return new SecretEncryption(new Config([
            'app' => [
                'secret_encryption_key' => base64_encode(str_repeat('o', 32)),
            ],
        ]));
    }

    /** @return array<string,string|null> */
    private function schemaColumn(
        string $name,
        string $type,
        string $extra = '',
    ): array {
        return [
            'COLUMN_NAME' => $name,
            'DATA_TYPE' => $type,
            'EXTRA' => $extra,
            'GENERATION_EXPRESSION' => null,
            'TABLE_TYPE' => 'BASE TABLE',
        ];
    }

    /** @param list<array<string,string|null>> $columns */
    private function schemaPdo(
        PDOStatement $data,
        string &$sql,
        array $columns,
    ): PDO {
        $schema = $this->statement($columns, ['email_profiles'], PDO::FETCH_ASSOC);
        $primary = $this->statement(['id'], ['email_profiles'], PDO::FETCH_COLUMN);
        $pdo = $this->createMock(PDO::class);
        $pdo->expects(self::exactly(3))
            ->method('prepare')
            ->willReturnCallback(static function (string $query) use (
                $schema,
                $primary,
                $data,
                &$sql,
            ): PDOStatement {
                if (str_contains($query, 'information_schema`.`COLUMNS')) {
                    return $schema;
                }
                if (str_contains($query, 'KEY_COLUMN_USAGE')) {
                    return $primary;
                }
                $sql = $query;
                return $data;
            });
        return $pdo;
    }

    /**
     * @param list<mixed> $rows
     * @param list<mixed> $params
     */
    private function statement(
        array $rows,
        array $params,
        int $fetchMode = PDO::FETCH_ASSOC,
    ): PDOStatement {
        $statement = $this->createMock(PDOStatement::class);
        $statement->expects(self::once())
            ->method('execute')
            ->with($params)
            ->willReturn(true);
        $statement->expects(self::once())
            ->method('fetchAll')
            ->with($fetchMode)
            ->willReturn($rows);
        $statement->expects(self::once())->method('closeCursor')->willReturn(true);
        return $statement;
    }
}
