<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup\Registry;

use MyInvoice\Service\Backup\Company\CompanyBackupProtectedSecretProjection;
use MyInvoice\Service\Backup\Company\CompanyBackupRawSecretMaterialization;
use MyInvoice\Service\Backup\Company\CompanyBackupSecretStorage;
use MyInvoice\Service\Backup\Company\CompanyBackupTableProjection;
use MyInvoice\Service\Backup\Company\CompanyBackupWorkReportLinkPolicy;
use MyInvoice\Service\Backup\Registry\CompanyBackupWorkReportLinksDefinition;
use MyInvoice\Service\Backup\Registry\TenantDataDefinition;
use MyInvoice\Service\Backup\Registry\TenantDataPolicy;
use MyInvoice\Service\Backup\Registry\TenantDataRegistry;
use MyInvoice\Service\Backup\Registry\TenantDataRegistryFactory;
use MyInvoice\Service\Backup\Registry\TenantSecretPolicy;
use PHPUnit\Framework\TestCase;

final class TenantDataRegistryFactoryWorkReportLinksTest extends TestCase
{
    public function testDraftUsesCanonicalCompanyOnlyDefinitionWithoutActivatingReportsOrInvoices(): void
    {
        $registry = TenantDataRegistryFactory::draftV1();
        $definition = $registry->definition(CompanyBackupWorkReportLinkPolicy::REGISTRY_KEY);
        self::assertNotNull($definition);
        self::assertSame(CompanyBackupWorkReportLinksDefinition::definition()->toArrayForProfile(
            TenantDataRegistry::COMPANY_BACKUP_PROFILE,
        ), $definition->toArrayForProfile(TenantDataRegistry::COMPANY_BACKUP_PROFILE));
        self::assertSame(TenantDataPolicy::TenantOwned, $definition->policy);
        self::assertTrue($definition->hasProfile(TenantDataRegistry::COMPANY_BACKUP_PROFILE));
        self::assertFalse($definition->hasProfile(TenantDataRegistry::ACCOUNTING_ARCHIVE_PROFILE));
        self::assertFalse($registry->isComplete(TenantDataRegistry::COMPANY_BACKUP_PROFILE));
        self::assertTrue($registry->isComplete(TenantDataRegistry::ACCOUNTING_ARCHIVE_PROFILE));
        self::assertNotContains(CompanyBackupWorkReportLinkPolicy::REGISTRY_KEY, array_map(
            static fn (TenantDataDefinition $item): string => $item->key,
            $registry->definitionsFor(TenantDataRegistry::ACCOUNTING_ARCHIVE_PROFILE),
        ));
        foreach (['work_reports', 'work_report_items', 'work_report_materials',
            'recurring_invoice_templates', 'small_assets'] as $table) {
            self::assertNull($registry->definition('table:' . $table), $table);
        }
        foreach (['invoices', 'invoice_items', 'purchase_invoices', 'purchase_invoice_items'] as $table) {
            $invoice = $registry->definition('table:' . $table);
            self::assertNotNull($invoice);
            self::assertArrayNotHasKey('data_columns', $invoice->details['company_backup'] ?? []);
        }
    }

    public function testExactLinkReferencesAreClosedInDraftRegistry(): void
    {
        $registry = TenantDataRegistryFactory::draftV1();
        $definition = $registry->definition(CompanyBackupWorkReportLinkPolicy::REGISTRY_KEY);
        self::assertNotNull($definition);
        $projection = CompanyBackupTableProjection::fromDefinition($definition);
        $projection->assertRegistryTargets($registry);
        $targets = array_map(
            static fn ($reference): string => $reference->target,
            $projection->references->references,
        );
        self::assertSame(['table:clients', 'table:users', 'table:projects', 'table:supplier'], $targets);
        foreach (array_unique($targets) as $target) {
            $targetDefinition = $registry->definition($target);
            self::assertNotNull($targetDefinition, $target);
            self::assertTrue($targetDefinition->hasProfile(TenantDataRegistry::COMPANY_BACKUP_PROFILE), $target);
            if ($target !== 'table:users') {
                CompanyBackupTableProjection::fromDefinition($targetDefinition)
                    ->assertRegistryTargets($registry);
            } else {
                self::assertSame(TenantDataPolicy::InstanceOwned, $targetDefinition->policy);
            }
        }
        self::assertNull($registry->definition('table:work_reports'));
    }

    public function testProtectedRawTokenAndImmediateProjectReferenceRemainCanonical(): void
    {
        $definition = TenantDataRegistryFactory::draftV1()->definition(CompanyBackupWorkReportLinkPolicy::REGISTRY_KEY);
        self::assertNotNull($definition);
        $projection = CompanyBackupTableProjection::fromDefinition($definition);
        self::assertFalse($projection->allowsDeferredUpdates);
        self::assertNotContains('token', $projection->dataColumns);
        self::assertSame(TenantSecretPolicy::ProtectedDomainSecret, $projection->secretPolicies['token']);
        self::assertSame('token', $projection->requiredSecretEnvelopeColumn());
        $secret = CompanyBackupProtectedSecretProjection::fromDefinition($definition);
        self::assertSame(['token'], $secret->columns);
        self::assertSame(CompanyBackupSecretStorage::Raw, $secret->storage['token']);
        self::assertCount(1, $projection->protectedSecretMaterializations->materializations);
        $materialization = $projection->protectedSecretMaterializations->materializations[0];
        self::assertInstanceOf(CompanyBackupRawSecretMaterialization::class, $materialization);
        self::assertSame('token<-raw_bytes_v1:48@supplier_id', $materialization->signature());
    }
}
