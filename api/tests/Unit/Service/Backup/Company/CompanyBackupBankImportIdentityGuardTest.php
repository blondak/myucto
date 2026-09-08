<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup\Company;

use MyInvoice\Service\Backup\Company\CompanyBackupBankImportIdentityGuard;
use MyInvoice\Service\Backup\Company\CompanyBackupDataSourceException;
use MyInvoice\Service\Backup\Company\CompanyBackupTableProjection;
use MyInvoice\Service\Backup\Registry\TenantDataDefinition;
use MyInvoice\Service\Backup\Registry\TenantDataObjectKind;
use MyInvoice\Service\Backup\Registry\TenantDataPolicy;
use MyInvoice\Service\Backup\Registry\TenantDataRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CompanyBackupBankImportIdentityGuardTest extends TestCase
{
    /** @return array<string,array{string,string,string}> */
    public static function identities(): array
    {
        return [
            'email movement' => ['bank_transactions', 'email_notice', 'email:' . str_repeat('a', 64)],
            'idoklad movement' => ['bank_transactions', 'idoklad', 'idoklad:123'],
            'email month' => ['bank_statements', 'email_notice', 'email-month:' . str_repeat('a', 64)],
            'idoklad month' => ['bank_statements', 'idoklad', 'idoklad-month:123:2026-09'],
        ];
    }

    #[DataProvider('identities')]
    public function testRequiresPortableIdentityInsteadOfGuessingFromLegacyHash(string $table, string $source, string $identity): void
    {
        CompanyBackupBankImportIdentityGuard::assertRow('table:' . $table,
            ['source' => $source, 'external_identity' => $identity]);
        $this->expectException(CompanyBackupDataSourceException::class);
        $this->expectExceptionMessage('data_bank_external_identity_unresolved');
        CompanyBackupBankImportIdentityGuard::assertRow('table:' . $table,
            ['source' => $source, 'source_ref' => 'supplier-7:' . str_repeat('b', 64)]);
    }

    public function testOrdinaryStatementDoesNotNeedExternalConnectorIdentity(): void
    {
        CompanyBackupBankImportIdentityGuard::assertRow('table:bank_statements', ['source' => 'gpc']);
        CompanyBackupBankImportIdentityGuard::assertRow('table:bank_transactions', ['source' => 'statement']);
        $this->addToAssertionCount(1);
    }

    public function testSharedExportAndImportProjectionRejectsUnresolvedIdentity(): void
    {
        $definition = new TenantDataDefinition('table:bank_transactions', TenantDataObjectKind::Table,
            TenantDataPolicy::TenantOwnedIndirect, [TenantDataRegistry::COMPANY_BACKUP_PROFILE], [
                'primary_key' => ['id'], 'ownership' => ['strategy' => 'bank_transaction_relationships'],
                'secrets' => [], 'company_backup' => [
                    'data_columns' => ['id', 'source', 'external_identity'],
                    'references' => [], 'embedded_references' => [], 'generated_columns' => [],
                    'omit_columns' => [], 'restore_overrides' => [],
                ],
            ]);
        $projection = CompanyBackupTableProjection::fromDefinition($definition);
        $this->expectException(CompanyBackupDataSourceException::class);
        $this->expectExceptionMessage('data_bank_external_identity_unresolved');
        $projection->assertCompleteSourceRow(['id' => 1, 'source' => 'email_notice', 'external_identity' => null]);
    }
}
