<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup\Company;

use MyInvoice\Service\Backup\Company\CompanyBackupTableProjection;
use MyInvoice\Service\Backup\Company\CompanyBackupReference;
use MyInvoice\Service\Backup\Company\CompanyBackupSourceIdentityProjection;
use MyInvoice\Service\Backup\Company\CompanyBackupTenantSqlSelector;
use MyInvoice\Service\Backup\Registry\TenantDataRegistryFactory;
use PHPUnit\Framework\TestCase;

final class CompanyBackupBankProjectionTest extends TestCase
{
    public function testStatementOwnershipCannotBeDeferredIntoLegacyDedupScope(): void
    {
        $definition = TenantDataRegistryFactory::draftV1()->definition('table:bank_statements');
        self::assertNotNull($definition);
        $projection = CompanyBackupTableProjection::fromDefinition($definition);
        self::assertFalse($projection->allowsDeferredUpdates);
    }

    public function testMachinePayloadCannotKeepUnmaterializedBankOwner(): void
    {
        $definition = TenantDataRegistryFactory::draftV1()->definition('table:bank_statements');
        self::assertNotNull($definition);
        $projection = CompanyBackupTableProjection::fromDefinition($definition);
        $this->expectExceptionMessage('data_bank_statement_owner_missing');
        $projection->assertCompleteSourceRow(array_fill_keys($projection->dataColumns, null));
    }

    public function testCompanySelectionIncludesUnmatchedTransactionsOnlyOfOwnedStatement(): void
    {
        $definition = TenantDataRegistryFactory::draftV1()->definition('table:bank_transactions');
        self::assertNotNull($definition);
        $projection = CompanyBackupTableProjection::fromDefinition($definition);
        $selection = (new CompanyBackupTenantSqlSelector())->select($projection, 11);
        // Produkční normalizace používá MariaDB, behaviorální případy jsou
        // v CompanyBackupSqlRowSourceTest, nikoli v náhradním SQLite dialektu.
        self::assertStringContainsString(
            \MyInvoice\Repository\BankStatementOwnershipResolver::sql('_backup_statement'), $selection->where,
        );
        self::assertSame([11, 11], $selection->params);
        self::assertSame('bank_transaction_relationships',
            $definition->details['accounting_archive']['selector']);
    }

    public function testOwnedAccountsKeepAccountingAnalyticsAndRemapOnlyOwnershipAndCurrency(): void
    {
        $registry = TenantDataRegistryFactory::draftV1();
        $definition = $registry->definition('table:supplier_bank_accounts');
        self::assertNotNull($definition);
        $projection = CompanyBackupTableProjection::fromDefinition($definition);
        $row = [
            'id' => 31, 'supplier_id' => 11, 'currency_id' => 41,
            'label' => 'Syntetický účet', 'account_number' => '1000000005',
            'bank_code' => '0100', 'iban' => null, 'currency' => 'CZK',
            'account_canonical' => '1000000005', 'bank_code_norm' => '0100',
            'kind' => 'savings', 'analytic_suffix' => '001', 'source' => 'manual',
            'is_active' => 1, 'created_at' => '2022-01-01 00:00:00',
            'updated_at' => '2022-01-02 00:00:00',
        ];
        self::assertSame(array_keys($row), $projection->dataColumns);
        $projection->assertRegistryTargets($registry);
        $projection->assertCompleteSourceRow($row);
        $mapped = $projection->references->remap($row,
            static fn (CompanyBackupReference $reference, array $key): array => [$key[0] + 100],
        );
        self::assertSame(array_replace($row, ['supplier_id' => 111, 'currency_id' => 141]), $mapped);
        self::assertSame(['supplier_id', 'account_canonical', 'bank_code_norm'],
            CompanyBackupSourceIdentityProjection::fromDefinition($definition)->naturalKeyColumns);
        self::assertSame([], $projection->restoreOverrides->overrides);
    }

    public function testOwnTransferRemapsBothLegsWithoutRecalculatingAmount(): void
    {
        $registry = TenantDataRegistryFactory::draftV1();
        $definition = $registry->definition('table:bank_transfer_matches');
        self::assertNotNull($definition);
        $projection = CompanyBackupTableProjection::fromDefinition($definition);
        $row = [
            'id' => 1, 'supplier_id' => 11, 'out_transaction_id' => 21,
            'in_transaction_id' => 22, 'amount' => '123.45', 'currency' => 'CZK',
            'matched_at' => '2022-01-01 00:00:00',
        ];
        self::assertSame(array_keys($row), $projection->dataColumns);
        $projection->assertRegistryTargets($registry);
        $projection->assertCompleteSourceRow($row);
        $visited = [];
        $mapped = $projection->references->remap($row,
            static function (CompanyBackupReference $reference, array $key) use (&$visited): array {
                $visited[] = $reference->signature();
                return [$key[0] + 100];
            },
        );
        self::assertSame([
            'in_transaction_id->bank_transactions:id',
            'out_transaction_id->bank_transactions:id',
            'supplier_id->supplier:id',
        ], $visited);
        self::assertSame(array_replace($row, [
            'supplier_id' => 111, 'out_transaction_id' => 121, 'in_transaction_id' => 122,
        ]), $mapped);
        self::assertSame([], $projection->restoreOverrides->overrides);
    }
}
