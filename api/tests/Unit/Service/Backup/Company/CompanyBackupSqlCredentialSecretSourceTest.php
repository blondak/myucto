<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup\Company;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Backup\Company\CompanyBackupCredentialSecretBundle;
use MyInvoice\Service\Backup\Company\CompanyBackupCredentialTableProjection;
use MyInvoice\Service\Backup\Company\CompanyBackupDataSourceException;
use MyInvoice\Service\Backup\Company\CompanyBackupFileAreaRootResolver;
use MyInvoice\Service\Backup\Company\CompanyBackupSecretSelection;
use MyInvoice\Service\Backup\Company\CompanyBackupSigningCredentialsProjection;
use MyInvoice\Service\Backup\Company\CompanyBackupSqlCredentialSecretSource;
use MyInvoice\Service\Backup\Registry\TenantDataDefinition;
use MyInvoice\Service\Backup\Registry\TenantDataObjectKind;
use MyInvoice\Service\Backup\Registry\TenantDataPolicy;
use MyInvoice\Service\Backup\Registry\TenantDataRegistry;
use MyInvoice\Service\Backup\Registry\TenantDataRegistrySnapshot;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;

final class CompanyBackupSqlCredentialSecretSourceTest extends TestCase
{
    /** @var list<string> */
    private array $paths = [];

    protected function tearDown(): void
    {
        foreach (array_reverse($this->paths) as $path) {
            if (is_file($path) || is_link($path)) {
                @unlink($path);
            } else {
                @rmdir($path);
            }
        }
    }

    public function testReadsExactCompanyRowFileAndDecryptedPassphrase(): void
    {
        $encryption = $this->encryption();
        $root = $this->certificateRoot();
        $pfx = "synthetic-company-pfx\0bytes";
        $this->certificateFile($root, 7, 41, $pfx);
        $row = $this->row($encryption);
        $sql = '';
        $projection = $this->projection();
        $pdo = $this->pdo(
            [$row + ['_company_owner_user_id' => null]],
            $sql,
            $projection,
        );

        $values = iterator_to_array((new CompanyBackupSqlCredentialSecretSource(
            $encryption,
            $this->roots($root),
        ))->values(
            $pdo,
            7,
            $projection,
            $this->entries(),
        ), false);

        self::assertCount(1, $values);
        self::assertSame('company_file', $values[0]->name);
        self::assertSame(['id' => 11], $values[0]->primaryKey);
        $bundle = CompanyBackupCredentialSecretBundle::fromJson(
            $values[0]->plaintext(),
            $projection,
            'company_file',
            ['id' => 11],
        );
        self::assertSame($pfx, $bundle->attachment('certificate_path'));
        self::assertSame(
            'synthetic-company-passphrase',
            $bundle->secret('encrypted_passphrase'),
        );
        self::assertStringContainsString(
            'FROM `signing_credentials` AS `_company_credential` '
                . 'JOIN `signing_profiles` AS `_company_profile`',
            $sql,
        );
        self::assertStringContainsString(
            'WHERE `_company_profile`.`supplier_id` = ? '
                . 'AND `_company_credential`.`id` = ? LIMIT 2',
            $sql,
        );
        self::assertIsString($row['encrypted_passphrase']);
        self::assertIsString($row['certificate_path']);
        self::assertStringNotContainsString($row['encrypted_passphrase'], $sql);
        self::assertStringNotContainsString($row['certificate_path'], $sql);
    }

    public function testRejectsPersonalVariantBeforeReadingItsFile(): void
    {
        $encryption = $this->encryption();
        $root = $this->certificateRoot();
        $row = $this->row($encryption);
        $sql = '';
        $projection = $this->projection();
        $pdo = $this->pdo(
            [$row + ['_company_owner_user_id' => 19]],
            $sql,
            $projection,
        );

        $this->assertSourceError(
            'secret_selection_consent_required',
            fn () => iterator_to_array((new CompanyBackupSqlCredentialSecretSource(
                $encryption,
                $this->roots($root),
            ))->values($pdo, 7, $projection, $this->entries())),
        );
        self::assertSame([], glob($root . DIRECTORY_SEPARATOR . '*') ?: []);
    }

    public function testRejectsTenantForeignStoredPathEvenWhenFileExists(): void
    {
        $encryption = $this->encryption();
        $root = $this->certificateRoot();
        $this->certificateFile($root, 8, 41, 'foreign-company-pfx');
        $row = $this->row($encryption);
        $row['certificate_path'] =
            'signing/profiles/supplier-8/profile-41/profile.p12';
        $sql = '';
        $projection = $this->projection();
        $pdo = $this->pdo(
            [$row + ['_company_owner_user_id' => null]],
            $sql,
            $projection,
        );

        $this->assertSourceError(
            'credential_attachment_path_mismatch',
            fn () => iterator_to_array((new CompanyBackupSqlCredentialSecretSource(
                $encryption,
                $this->roots($root),
            ))->values($pdo, 7, $projection, $this->entries())),
        );
    }

    public function testRejectsSymlinkAtExpectedCredentialPath(): void
    {
        $encryption = $this->encryption();
        $root = $this->certificateRoot();
        $expected = $this->certificateFile($root, 7, 41, 'placeholder');
        self::assertTrue(unlink($expected));
        $target = $root . DIRECTORY_SEPARATOR . 'synthetic-target.p12';
        self::assertSame(20, file_put_contents($target, 'synthetic-target-pfx'));
        $this->paths[] = $target;
        if (!@symlink($target, $expected)) {
            self::markTestSkipped('Platforma testu nedovoluje vytvořit symlink.');
        }
        $row = $this->row($encryption);
        $sql = '';
        $projection = $this->projection();
        $pdo = $this->pdo(
            [$row + ['_company_owner_user_id' => null]],
            $sql,
            $projection,
        );

        $this->assertSourceError(
            'credential_attachment_path_unsafe',
            fn () => iterator_to_array((new CompanyBackupSqlCredentialSecretSource(
                $encryption,
                $this->roots($root),
            ))->values($pdo, 7, $projection, $this->entries())),
        );
    }

    /** @param callable():mixed $operation */
    private function assertSourceError(string $code, callable $operation): void
    {
        try {
            $operation();
            self::fail('Neplatný zdroj credentialu musí být odmítnut.');
        } catch (CompanyBackupDataSourceException $e) {
            self::assertSame($code, $e->errorCode);
            self::assertSame('table:signing_credentials', $e->registryKey);
        }
    }

    /** @return list<\MyInvoice\Service\Backup\Company\CompanyBackupSecretSelectionEntry> */
    private function entries(): array
    {
        $registry = $this->registry();
        return CompanyBackupSecretSelection::fromArray([
            'registry_fingerprint' => $registry->fingerprint,
            'entries' => [[
                'registry_key' => 'table:signing_credentials',
                'scope' => 'credential_variant',
                'name' => 'company_file',
                'primary_key' => ['id' => 11],
            ]],
        ], $registry)->entries();
    }

    private function projection(): CompanyBackupCredentialTableProjection
    {
        return CompanyBackupCredentialTableProjection::fromDefinition(
            $this->definition(),
        );
    }

    private function registry(): TenantDataRegistrySnapshot
    {
        $profile = TenantDataRegistry::COMPANY_BACKUP_PROFILE;
        return TenantDataRegistrySnapshot::fromRegistry(new TenantDataRegistry(
            1,
            [$this->definition()],
            [$profile],
        ), $profile);
    }

    private function definition(): TenantDataDefinition
    {
        return new TenantDataDefinition(
            'table:signing_credentials',
            TenantDataObjectKind::Table,
            TenantDataPolicy::OptionalCredential,
            [TenantDataRegistry::COMPANY_BACKUP_PROFILE],
            [
                'primary_key' => ['id'],
                'ownership' =>
                    CompanyBackupSigningCredentialsProjection::ownership(),
                'company_backup_credential' =>
                    CompanyBackupSigningCredentialsProjection::metadata(),
            ],
        );
    }

    /** @return array<string,int|string|null> */
    private function row(SecretEncryption $encryption): array
    {
        return [
            'id' => 11,
            'profile_id' => 41,
            'vault_credential_id' => null,
            'certificate_path' =>
                'signing/profiles/supplier-7/profile-41/profile.p12',
            'certificate_fingerprint' => str_repeat('a', 64),
            'certificate_subject' => 'CN=Synthetic company',
            'certificate_email' => 'synthetic@example.test',
            'certificate_valid_from' => '2026-01-01 00:00:00',
            'certificate_valid_to' => '2028-01-01 00:00:00',
            'certificate_usage_json' => '{"pdf":true}',
            'passphrase_policy' => 'encrypted_store',
            'passphrase_profile_id' => null,
            'encrypted_passphrase' =>
                $encryption->encrypt('synthetic-company-passphrase'),
            'is_active' => 1,
            'created_by' => 3,
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-02 00:00:00',
            'deleted_at' => null,
        ];
    }

    /** @param list<array<string,mixed>> $rows */
    private function pdo(
        array $rows,
        string &$sql,
        CompanyBackupCredentialTableProjection $projection,
    ): PDO
    {
        $schema = $this->statement(array_map(
            static fn (string $column): array => [
                'COLUMN_NAME' => $column,
                'DATA_TYPE' => in_array(
                    $column,
                    ['id', 'profile_id', 'vault_credential_id', 'created_by'],
                    true,
                ) ? 'bigint' : 'varchar',
                'EXTRA' => $column === 'id' ? 'auto_increment' : '',
                'GENERATION_EXPRESSION' => null,
                'TABLE_TYPE' => 'BASE TABLE',
            ],
            $projection->columns,
        ), ['signing_credentials'], PDO::FETCH_ASSOC);
        $primary = $this->statement(
            ['id'],
            ['signing_credentials'],
            PDO::FETCH_COLUMN,
        );
        $nullable = array_fill_keys($projection->nullableColumns, true);
        $nullability = $this->statement(array_map(
            static fn (string $column): array => [
                'COLUMN_NAME' => $column,
                'IS_NULLABLE' => isset($nullable[$column]) ? 'YES' : 'NO',
            ],
            $projection->columns,
        ), ['signing_credentials'], PDO::FETCH_ASSOC);
        $references = $this->statement([
            $this->foreignKey('created_by', 'created_by', 'users'),
            $this->foreignKey('profile_id', 'profile_id', 'signing_profiles'),
            $this->foreignKey(
                'vault_credential_id',
                'vault_credential_id',
                'epo_signing_credentials',
            ),
        ], ['signing_credentials'], PDO::FETCH_ASSOC);
        $data = $this->statement($rows, [7, 11], PDO::FETCH_ASSOC);
        $pdo = $this->createMock(PDO::class);
        $pdo->expects(self::exactly(5))
            ->method('prepare')
            ->willReturnCallback(static function (string $query) use (
                $schema,
                $primary,
                $nullability,
                $references,
                $data,
                &$sql,
            ): PDOStatement {
                if (str_contains($query, '`c`.`COLUMN_NAME`')) {
                    return $schema;
                }
                if (str_contains($query, "`CONSTRAINT_NAME` = 'PRIMARY'")) {
                    return $primary;
                }
                if (str_contains($query, '`IS_NULLABLE`')) {
                    return $nullability;
                }
                if (str_contains($query, '`REFERENCED_TABLE_NAME` IS NOT NULL')) {
                    return $references;
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
    private function statement(array $rows, array $params, int $fetchMode): PDOStatement
    {
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

    /** @return array<string,int|string> */
    private function foreignKey(
        string $constraint,
        string $column,
        string $target,
    ): array {
        return [
            'CONSTRAINT_NAME' => $constraint,
            'COLUMN_NAME' => $column,
            'ORDINAL_POSITION' => 1,
            'REFERENCED_TABLE_NAME' => $target,
            'REFERENCED_COLUMN_NAME' => 'id',
        ];
    }

    private function roots(string $root): CompanyBackupFileAreaRootResolver
    {
        return new class($root) implements CompanyBackupFileAreaRootResolver {
            public function __construct(private readonly string $root) {}

            public function resolve(string $storageSubdirectory): string
            {
                TestCase::assertSame('signing/profiles', $storageSubdirectory);
                return $this->root;
            }
        };
    }

    private function certificateRoot(): string
    {
        $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'company-credential-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($root, 0700));
        $this->paths[] = $root;
        return $root;
    }

    private function certificateFile(
        string $root,
        int $supplierId,
        int $profileId,
        string $contents,
    ): string {
        $supplier = $root . DIRECTORY_SEPARATOR . 'supplier-' . $supplierId;
        self::assertTrue(mkdir($supplier, 0700));
        $this->paths[] = $supplier;
        $profile = $supplier . DIRECTORY_SEPARATOR . 'profile-' . $profileId;
        self::assertTrue(mkdir($profile, 0700));
        $this->paths[] = $profile;
        $file = $profile . DIRECTORY_SEPARATOR . 'profile.p12';
        self::assertSame(strlen($contents), file_put_contents($file, $contents));
        $this->paths[] = $file;
        return $file;
    }

    private function encryption(): SecretEncryption
    {
        return new SecretEncryption(new Config([
            'app' => [
                'secret_encryption_key' => base64_encode(str_repeat('p', 32)),
            ],
        ]));
    }
}
