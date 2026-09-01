<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup\Company;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Backup\Company\CompanyBackupDataSourceException;
use MyInvoice\Service\Backup\Company\CompanyBackupProtectedSecretProjection;
use MyInvoice\Service\Backup\Company\CompanyBackupSecretStorage;
use MyInvoice\Service\Backup\Company\CompanyBackupSqlProtectedSecretSource;
use MyInvoice\Service\Backup\Company\CompanyBackupTableSchema;
use MyInvoice\Service\Backup\Registry\TenantDataDefinition;
use MyInvoice\Service\Backup\Registry\TenantDataObjectKind;
use MyInvoice\Service\Backup\Registry\TenantDataPolicy;
use MyInvoice\Service\Backup\Registry\TenantDataRegistry;
use MyInvoice\Service\Backup\Registry\TenantDataRegistryFactory;
use MyInvoice\Service\Backup\Registry\TenantSecretPolicy;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;

final class CompanyBackupSqlProtectedSecretSourceTest extends TestCase
{
    public function testReadsOnlyTenantScopedSecretsAndDecryptsDeclaredStorage(): void
    {
        $encryption = $this->encryption();
        $encrypted = $encryption->encrypt('synthetic-application-secret');
        $contextEncrypted = $encryption->encryptFor(
            'synthetic-context-secret',
            'backup:synthetic-context',
        );
        $data = $this->streamStatement(
            [[
                'id' => 7,
                'context_value' => $contextEncrypted,
                'domain_salt' => "\0synthetic-domain-salt\xff",
                'encrypted_value' => $encrypted,
            ]],
            [7],
        );
        $sql = '';
        $pdo = $this->schemaPdo(
            $data,
            $sql,
            columns: [
                $this->schemaColumn('id', 'int', 'auto_increment'),
                $this->schemaColumn('name', 'varchar'),
                $this->schemaColumn('context_value', 'varbinary'),
                $this->schemaColumn('domain_salt', 'varbinary'),
                $this->schemaColumn('encrypted_value', 'varbinary'),
            ],
        );
        $projection = CompanyBackupProtectedSecretProjection::fromDefinition(
            $this->definition(),
        );

        $values = iterator_to_array(
            (new CompanyBackupSqlProtectedSecretSource($encryption))->values(
                $pdo,
                7,
                $projection,
            ),
            false,
        );

        self::assertSame(
            [
                'synthetic-context-secret',
                "\0synthetic-domain-salt\xff",
                'synthetic-application-secret',
            ],
            array_map(static fn ($value): string => $value->plaintext(), $values),
        );
        self::assertSame(
            ['context_value', 'domain_salt', 'encrypted_value'],
            array_map(static fn ($value): string => $value->name, $values),
        );
        self::assertSame([['id' => 7], ['id' => 7], ['id' => 7]], array_map(
            static fn ($value): array => $value->primaryKey,
            $values,
        ));
        self::assertStringContainsString(
            'SELECT `_company_secret`.`id`, `_company_secret`.`context_value`, '
                . '`_company_secret`.`domain_salt`, '
                . '`_company_secret`.`encrypted_value` FROM `supplier`',
            $sql,
        );
        self::assertStringContainsString(
            'WHERE `_company_secret`.`id` = ? ORDER BY `_company_secret`.`id`',
            $sql,
        );
        self::assertStringNotContainsString('name', $sql);
        self::assertStringNotContainsString($encrypted, $sql);
    }

    public function testResolvesContextTemplateFromTenantAndPrimaryKey(): void
    {
        $encryption = $this->encryption();
        $encrypted = $encryption->encryptFor(
            '1000000005/0100',
            'payroll:42:73:bank_account',
        );
        $definition = $this->dynamicContextDefinition();
        $projection = CompanyBackupProtectedSecretProjection::fromDefinition(
            $definition,
        );
        $data = $this->streamStatement([[
            'id' => 73,
            'supplier_id' => 42,
            'bank_account_ciphertext' => $encrypted,
        ]], [42]);
        $sql = '';
        $pdo = $this->schemaPdo(
            $data,
            $sql,
            columns: [
                $this->schemaColumn('id', 'bigint', 'auto_increment'),
                $this->schemaColumn('supplier_id', 'int'),
                $this->schemaColumn('bank_account_ciphertext', 'varchar'),
            ],
            table: 'payroll_institution_accounts',
        );

        $values = iterator_to_array(
            (new CompanyBackupSqlProtectedSecretSource($encryption))->values(
                $pdo,
                42,
                $projection,
            ),
            false,
        );

        self::assertCount(1, $values);
        self::assertSame('1000000005/0100', $values[0]->plaintext());
        self::assertSame(['id' => 73], $values[0]->primaryKey);
        self::assertSame(
            'payroll:{supplier_id}:{id}:bank_account',
            $projection->contexts['bank_account_ciphertext'] ?? null,
        );
        self::assertStringContainsString(
            'SELECT `_company_secret`.`id`, `_company_secret`.`supplier_id`, '
                . '`_company_secret`.`bank_account_ciphertext` '
                . 'FROM `payroll_institution_accounts`',
            $sql,
        );
        self::assertStringContainsString(
            'WHERE `_company_secret`.`supplier_id` = ? '
                . 'ORDER BY `_company_secret`.`id`',
            $sql,
        );
        self::assertStringNotContainsString($encrypted, $sql);
    }

    public function testRejectsUndecryptableValueWithoutReturningCiphertext(): void
    {
        $data = $this->streamStatement([[
            'id' => 7,
            'context_value' => null,
            'domain_salt' => null,
            'encrypted_value' => 'enc:v1:not-valid-ciphertext',
        ]], [7]);
        $sql = '';
        $pdo = $this->schemaPdo(
            $data,
            $sql,
            columns: [
                $this->schemaColumn('id', 'int', 'auto_increment'),
                $this->schemaColumn('context_value', 'varbinary'),
                $this->schemaColumn('domain_salt', 'varbinary'),
                $this->schemaColumn('encrypted_value', 'varbinary'),
            ],
        );

        try {
            iterator_to_array(
                (new CompanyBackupSqlProtectedSecretSource($this->encryption()))
                    ->values(
                        $pdo,
                        7,
                        CompanyBackupProtectedSecretProjection::fromDefinition(
                            $this->definition(),
                        ),
                    ),
            );
            self::fail('Nedešifrovatelný protected secret nesmí vstoupit do payloadu.');
        } catch (CompanyBackupDataSourceException $e) {
            self::assertSame('secret_source_decrypt_failed', $e->errorCode);
            self::assertSame('table:supplier', $e->registryKey);
            self::assertSame('encrypted_value', $e->column);
            self::assertStringNotContainsString(
                'not-valid-ciphertext',
                $e->getMessage(),
            );
        }
    }

    public function testRejectsInvalidContextCoordinateBeforeDecrypting(): void
    {
        $encrypted = $this->encryption()->encryptFor(
            'synthetic-bank-account',
            'payroll:42:73:bank_account',
        );
        $data = $this->streamStatement([[
            'id' => '73:foreign',
            'supplier_id' => 42,
            'bank_account_ciphertext' => $encrypted,
        ]], [42]);
        $sql = '';
        $pdo = $this->schemaPdo(
            $data,
            $sql,
            columns: [
                $this->schemaColumn('id', 'bigint', 'auto_increment'),
                $this->schemaColumn('supplier_id', 'int'),
                $this->schemaColumn('bank_account_ciphertext', 'varchar'),
            ],
            table: 'payroll_institution_accounts',
        );

        try {
            iterator_to_array(
                (new CompanyBackupSqlProtectedSecretSource($this->encryption()))
                    ->values(
                        $pdo,
                        42,
                        CompanyBackupProtectedSecretProjection::fromDefinition(
                            $this->dynamicContextDefinition(),
                        ),
                    ),
            );
            self::fail('Neplatná souřadnice AAD nesmí vstoupit do dešifrování.');
        } catch (CompanyBackupDataSourceException $e) {
            self::assertSame('secret_source_context_invalid', $e->errorCode);
            self::assertSame('bank_account_ciphertext', $e->column);
            self::assertStringNotContainsString('73:foreign', $e->getMessage());
        }
    }

    public function testProjectionRejectsMissingStorageAndGeneratedSecret(): void
    {
        $definition = $this->definition();
        $details = $definition->details;
        unset($details['secrets']['domain_salt']['storage']);

        try {
            CompanyBackupProtectedSecretProjection::fromDefinition(
                new TenantDataDefinition(
                    $definition->key,
                    $definition->kind,
                    $definition->policy,
                    $definition->profiles,
                    $details,
                ),
            );
            self::fail('Protected secret bez storage kontraktu nesmí být čten.');
        } catch (CompanyBackupDataSourceException $e) {
            self::assertSame('secret_source_storage_missing', $e->errorCode);
            self::assertSame('domain_salt', $e->column);
        }

        $projection = CompanyBackupProtectedSecretProjection::fromDefinition(
            $definition,
        );
        try {
            $projection->assertRuntimeSchema(new CompanyBackupTableSchema(
                ['id', 'context_value', 'domain_salt', 'encrypted_value'],
                ['domain_salt'],
                ['id'],
                ['context_value', 'domain_salt', 'encrypted_value'],
            ));
            self::fail('Generovaný protected secret není autoritativní zdroj.');
        } catch (CompanyBackupDataSourceException $e) {
            self::assertSame('secret_source_schema_invalid', $e->errorCode);
            self::assertSame('domain_salt', $e->column);
        }
    }

    public function testProductionAiSaltHasExplicitRawStorage(): void
    {
        $definition = TenantDataRegistryFactory::draftV1()->definition(
            'table:supplier',
        );
        self::assertNotNull($definition);

        $projection = CompanyBackupProtectedSecretProjection::fromDefinition(
            $definition,
        );

        self::assertSame(['ai_pseudo_salt'], $projection->columns);
        self::assertSame(
            CompanyBackupSecretStorage::Raw,
            $projection->storage['ai_pseudo_salt'] ?? null,
        );
        self::assertSame(null, $projection->contexts['ai_pseudo_salt'] ?? null);
    }

    private function definition(): TenantDataDefinition
    {
        return new TenantDataDefinition(
            'table:supplier',
            TenantDataObjectKind::Table,
            TenantDataPolicy::TenantRoot,
            [TenantDataRegistry::COMPANY_BACKUP_PROFILE],
            [
                'primary_key' => ['id'],
                'ownership' => [
                    'strategy' => 'selected_supplier',
                    'column' => 'id',
                ],
                'secrets' => [
                    'context_value' => [
                        'policy' => TenantSecretPolicy::ProtectedDomainSecret->value,
                        'storage' =>
                            CompanyBackupSecretStorage::ApplicationEncryptedContext->value,
                        'context' => 'backup:synthetic-context',
                    ],
                    'domain_salt' => [
                        'policy' => TenantSecretPolicy::ProtectedDomainSecret->value,
                        'storage' => CompanyBackupSecretStorage::Raw->value,
                    ],
                    'encrypted_value' => [
                        'policy' => TenantSecretPolicy::ProtectedDomainSecret->value,
                        'storage' =>
                            CompanyBackupSecretStorage::ApplicationEncrypted->value,
                    ],
                ],
            ],
        );
    }

    private function dynamicContextDefinition(): TenantDataDefinition
    {
        return new TenantDataDefinition(
            'table:payroll_institution_accounts',
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
                    'bank_account_ciphertext' => [
                        'policy' =>
                            TenantSecretPolicy::ProtectedDomainSecret->value,
                        'storage' =>
                            CompanyBackupSecretStorage::ApplicationEncryptedContext->value,
                        'context' =>
                            'payroll:{supplier_id}:{id}:bank_account',
                    ],
                ],
            ],
        );
    }

    private function encryption(): SecretEncryption
    {
        return new SecretEncryption(new Config([
            'app' => [
                'secret_encryption_key' => base64_encode(str_repeat('s', 32)),
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

    /**
     * @param list<array<string,string|null>> $columns
     */
    private function schemaPdo(
        PDOStatement $data,
        string &$sql,
        array $columns,
        string $table = 'supplier',
    ): PDO {
        $schema = $this->statement($columns, [$table], PDO::FETCH_ASSOC);
        $primary = $this->statement(['id'], [$table], PDO::FETCH_COLUMN);
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

    /**
     * @param list<array<string,mixed>> $rows
     * @param list<mixed> $params
     */
    private function streamStatement(array $rows, array $params): PDOStatement
    {
        $statement = $this->createMock(PDOStatement::class);
        $statement->expects(self::once())
            ->method('execute')
            ->with($params)
            ->willReturn(true);
        $statement->expects(self::atLeastOnce())
            ->method('fetch')
            ->with(PDO::FETCH_ASSOC)
            ->willReturnOnConsecutiveCalls(...[...$rows, false]);
        $statement->expects(self::once())->method('closeCursor')->willReturn(true);
        return $statement;
    }
}
