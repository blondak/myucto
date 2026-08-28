<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup\Company;

use MyInvoice\Service\Backup\Company\CompanyBackupCredentialTableProjection;
use MyInvoice\Service\Backup\Company\CompanyBackupDataSourceException;
use MyInvoice\Service\Backup\Company\CompanyBackupSecretDeclaration;
use MyInvoice\Service\Backup\Company\CompanyBackupSecretScope;
use MyInvoice\Service\Backup\Company\CompanyBackupSigningCredentialsProjection;
use MyInvoice\Service\Backup\Company\CompanyBackupSqlSecretOmissionCountSource;
use MyInvoice\Service\Backup\Registry\TenantDataDefinition;
use MyInvoice\Service\Backup\Registry\TenantDataObjectKind;
use MyInvoice\Service\Backup\Registry\TenantDataPolicy;
use MyInvoice\Service\Backup\Registry\TenantDataRegistry;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;

final class CompanyBackupSqlSecretOmissionCountSourceTest extends TestCase
{
    public function testClassifiesEveryCredentialVariantInsideTenantScope(): void
    {
        $definition = $this->definition();
        $statement = $this->statement([
            [
                'owner_user_id' => null,
                'certificate_path' => 'signing/company.p12',
                'vault_credential_id' => null,
            ],
            [
                'owner_user_id' => 42,
                'certificate_path' => 'signing/personal.p12',
                'vault_credential_id' => null,
            ],
            [
                'owner_user_id' => '42',
                'certificate_path' => null,
                'vault_credential_id' => '91',
            ],
        ]);
        $pdo = $this->createMock(PDO::class);
        $sql = '';
        $pdo->expects(self::once())
            ->method('prepare')
            ->willReturnCallback(static function (string $value) use (
                &$sql,
                $statement,
            ): PDOStatement {
                $sql = $value;
                return $statement;
            });

        $counts = (new CompanyBackupSqlSecretOmissionCountSource())->counts(
            $pdo,
            7,
            $definition,
            $this->declarations($definition),
            $this->registry($definition),
        );

        self::assertSame([
            'table:signing_credentials:credential_variant:company_file' => 1,
            'table:signing_credentials:credential_variant:personal_file' => 1,
            'table:signing_credentials:credential_variant:personal_vault' => 1,
        ], $counts);
        self::assertStringContainsString(
            'WHERE `_profile`.`supplier_id` = ?',
            $sql,
        );
        self::assertStringNotContainsString('encrypted_passphrase', $sql);
    }

    public function testRejectsCredentialThatWouldDisappearBetweenDeclaredVariants(): void
    {
        $definition = $this->definition();
        $pdo = $this->createMock(PDO::class);
        $pdo->expects(self::once())
            ->method('prepare')
            ->willReturn($this->statement([[
                'owner_user_id' => null,
                'certificate_path' => 'signing/company.p12',
                'vault_credential_id' => 91,
            ]]));

        try {
            (new CompanyBackupSqlSecretOmissionCountSource())->counts(
                $pdo,
                7,
                $definition,
                $this->declarations($definition),
                $this->registry($definition),
            );
            self::fail('Nejednoznačný credential nesmí z inventáře tiše zmizet.');
        } catch (CompanyBackupDataSourceException $e) {
            self::assertSame('credential_variant_ambiguous', $e->errorCode);
        }
    }

    /** @param list<array<string,mixed>> $rows */
    private function statement(array $rows): PDOStatement
    {
        $statement = $this->createMock(PDOStatement::class);
        $statement->expects(self::once())
            ->method('execute')
            ->with([7])
            ->willReturn(true);
        $statement->expects(self::once())
            ->method('fetchAll')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn($rows);
        $statement->expects(self::once())->method('closeCursor')->willReturn(true);
        return $statement;
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
                'ownership' => CompanyBackupSigningCredentialsProjection::ownership(),
                'company_backup_credential' =>
                    CompanyBackupSigningCredentialsProjection::metadata(),
            ],
        );
    }

    /** @return list<CompanyBackupSecretDeclaration> */
    private function declarations(TenantDataDefinition $definition): array
    {
        $projection = CompanyBackupCredentialTableProjection::fromDefinition(
            $definition,
        );
        return array_map(
            static fn (array $variant): CompanyBackupSecretDeclaration =>
                new CompanyBackupSecretDeclaration(
                    $definition->key,
                    CompanyBackupSecretScope::CredentialVariant,
                    $variant['name'],
                    $variant['policy'],
                ),
            $projection->variants,
        );
    }

    private function registry(TenantDataDefinition $definition): TenantDataRegistry
    {
        return new TenantDataRegistry(
            1,
            [$definition],
            [TenantDataRegistry::COMPANY_BACKUP_PROFILE],
        );
    }
}
