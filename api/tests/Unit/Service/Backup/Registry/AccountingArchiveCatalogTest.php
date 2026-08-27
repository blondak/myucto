<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup\Registry;

use MyInvoice\Service\Accounting\Archive\AccountingArchiveCatalog;
use MyInvoice\Service\Accounting\Archive\AccountingArchiveTable;
use MyInvoice\Service\Backup\Registry\TenantDataDefinition;
use MyInvoice\Service\Backup\Registry\TenantDataObjectKind;
use MyInvoice\Service\Backup\Registry\TenantDataPolicy;
use MyInvoice\Service\Backup\Registry\TenantDataRegistry;
use MyInvoice\Service\Backup\Registry\TenantDataRegistryCoverageValidator;
use MyInvoice\Service\Backup\Registry\TenantDataRegistryFactory;
use MyInvoice\Service\Backup\Registry\TenantSecretPolicy;
use PHPUnit\Framework\TestCase;

final class AccountingArchiveCatalogTest extends TestCase
{
    public function testFactoryPublishesCompleteStableArchiveProfile(): void
    {
        $registry = TenantDataRegistryFactory::draftV1();
        $catalog = new AccountingArchiveCatalog($registry);

        self::assertFalse($registry->isComplete(TenantDataRegistry::COMPANY_BACKUP_PROFILE));
        self::assertTrue($registry->isComplete(TenantDataRegistry::ACCOUNTING_ARCHIVE_PROFILE));

        $export = $catalog->forExport();
        $restore = $catalog->forRestore();
        self::assertCount(57, $export);
        self::assertSame('supplier', $export[0]->name);
        self::assertSame('exchange_rates', $export[56]->name);
        self::assertSame('supplier', $restore[0]->name);
        self::assertSame('currencies', $restore[3]->name);
        self::assertSame('exchange_rates', $restore[56]->name);

        $exportNames = array_map(
            static fn (AccountingArchiveTable $table): string => $table->name,
            $export,
        );
        $restoreNames = array_map(
            static fn (AccountingArchiveTable $table): string => $table->name,
            $restore,
        );
        sort($exportNames, SORT_STRING);
        sort($restoreNames, SORT_STRING);
        self::assertSame($exportNames, $restoreNames);

        $report = (new TenantDataRegistryCoverageValidator())->evaluate(
            $registry,
            TenantDataRegistry::ACCOUNTING_ARCHIVE_PROFILE,
            array_map(static fn (string $table): string => 'table:' . $table, $exportNames),
        );
        self::assertTrue($report->isSafe());
    }

    public function testCatalogCarriesSelectorsSecretsAndRemapMetadata(): void
    {
        $catalog = new AccountingArchiveCatalog(TenantDataRegistryFactory::draftV1());

        $supplier = $catalog->get('supplier');
        self::assertNotNull($supplier);
        self::assertContains('idoklad_client_secret_enc', $supplier->omitColumns);
        self::assertContains('fakturoid_client_id', $supplier->omitColumns);
        self::assertSame(
            TenantSecretPolicy::ProtectedDomainSecret,
            $supplier->secretPolicies['ai_pseudo_salt'] ?? null,
        );

        $invoices = $catalog->get('invoices');
        self::assertNotNull($invoices);
        self::assertContains('approval_token', $invoices->omitColumns);
        self::assertContains('public_token', $invoices->omitColumns);
        self::assertNotContains('approval_token_expires_at', $invoices->omitColumns);

        $stock = $catalog->get('stock_levels');
        self::assertNotNull($stock);
        self::assertSame('stock_enabled', $stock->featureFlag);
        self::assertSame(['supplier_id', 'warehouse_id', 'stock_item_id'], $stock->primaryKey);

        $taxLoss = $catalog->get('tax_losses');
        self::assertNotNull($taxLoss);
        self::assertSame(
            ['source_return_id' => 'income_tax_returns'],
            $taxLoss->softReferences,
        );

        $bankStatement = $catalog->get('bank_statements');
        self::assertNotNull($bankStatement);
        self::assertSame(TenantDataPolicy::GlobalReference, $bankStatement->policy);
        self::assertSame('bank_statement_relationships', $bankStatement->selector);
    }

    public function testCatalogRejectsMissingProjectionMetadata(): void
    {
        $registry = new TenantDataRegistry(
            1,
            [new TenantDataDefinition(
                'table:supplier',
                TenantDataObjectKind::Table,
                TenantDataPolicy::TenantRoot,
                [TenantDataRegistry::ACCOUNTING_ARCHIVE_PROFILE],
                [
                    'primary_key' => ['id'],
                    'ownership' => [
                        'strategy' => 'selected_supplier',
                        'column' => 'id',
                    ],
                ],
            )],
            [TenantDataRegistry::ACCOUNTING_ARCHIVE_PROFILE],
        );

        $this->expectException(\LogicException::class);

        (new AccountingArchiveCatalog($registry))->forExport();
    }
}
