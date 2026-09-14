<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup\Registry;

use MyInvoice\Service\Backup\Company\CompanyBackupSubmissionRecipientsProjection;
use MyInvoice\Service\Backup\Registry\CompanyBackupSubmissionRecipientsDefinition;
use MyInvoice\Service\Backup\Registry\TenantDataPolicy;
use MyInvoice\Service\Backup\Registry\TenantDataRegistry;
use MyInvoice\Service\Backup\Registry\TenantDataRegistryFactory;
use PHPUnit\Framework\TestCase;

final class TenantDataRegistryFactorySubmissionRecipientsTest extends TestCase
{
    public function testOnlyCanonicalSubmissionRecipientsAreActivated(): void
    {
        $registry = TenantDataRegistryFactory::draftV1();
        $definition = $registry->definition(CompanyBackupSubmissionRecipientsProjection::REGISTRY_KEY);
        self::assertNotNull($definition);
        CompanyBackupSubmissionRecipientsProjection::assertDefinition($definition);
        self::assertSame(
            CompanyBackupSubmissionRecipientsDefinition::definition()->toArrayForProfile(
                TenantDataRegistry::COMPANY_BACKUP_PROFILE,
            ),
            $definition->toArrayForProfile(TenantDataRegistry::COMPANY_BACKUP_PROFILE),
        );
        self::assertSame(TenantDataPolicy::TenantOwned, $definition->policy);
        self::assertSame(['strategy' => 'submission_recipient_scope'], $definition->details['ownership']);
        self::assertNull($registry->definition('table:submission_outbox'));
        self::assertNull($registry->definition('table:isds_gateway_sessions'));
    }

    public function testExistingGlobalCatalogPoliciesRemainUnchanged(): void
    {
        $registry = TenantDataRegistryFactory::draftV1();
        foreach (['bank_rule_templates', 'countries', 'vat_rates'] as $table) {
            $definition = $registry->definition('table:' . $table);
            self::assertNotNull($definition);
            self::assertSame(TenantDataPolicy::GlobalReference, $definition->policy, $table);
        }
    }
}
