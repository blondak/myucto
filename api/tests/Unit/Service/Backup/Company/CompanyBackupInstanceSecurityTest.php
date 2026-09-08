<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup\Company;

use MyInvoice\Service\Backup\Company\CompanyBackupDataSourceException;
use MyInvoice\Service\Backup\Company\CompanyBackupTableProjection;
use MyInvoice\Service\Backup\Registry\TenantDataPolicy;
use MyInvoice\Service\Backup\Registry\TenantDataRegistry;
use MyInvoice\Service\Backup\Registry\TenantDataRegistryFactory;
use PHPUnit\Framework\TestCase;

final class CompanyBackupInstanceSecurityTest extends TestCase
{
    public function testExternalLookupCachesCannotBecomeCompanyDataPayload(): void
    {
        $registry = TenantDataRegistryFactory::draftV1();
        foreach (['ares_cache', 'crpdph_cache', 'vies_cache'] as $table) {
            $definition = $registry->definition('table:' . $table);
            self::assertNotNull($definition);
            self::assertSame(TenantDataPolicy::RuntimeDerived, $definition->policy);
            try {
                CompanyBackupTableProjection::fromDefinition($definition);
                self::fail('TTL cache se nemá přenášet jako účetní evidence.');
            } catch (CompanyBackupDataSourceException $e) {
                self::assertSame('data_object_kind_unsupported', $e->errorCode);
            }
        }
    }

    public function testInstanceAuthenticationCannotBecomeCompanyDataPayload(): void
    {
        $registry = TenantDataRegistryFactory::draftV1();
        foreach (['api_tokens', 'api_token_ips', 'sessions', 'password_resets', 'login_attempts',
            'trusted_devices', 'webauthn_ceremonies', 'webauthn_credentials', 'mfa_step_up_proofs'] as $table) {
            $definition = $registry->definition('table:' . $table);
            self::assertNotNull($definition);
            self::assertSame(TenantDataPolicy::InstanceOwned, $definition->policy);
            self::assertFalse($definition->policy->hasMachineDataPayload());
            self::assertFalse($definition->hasProfile(TenantDataRegistry::ACCOUNTING_ARCHIVE_PROFILE));
            self::assertNotEmpty($definition->details['reason']);
            try {
                CompanyBackupTableProjection::fromDefinition($definition);
                self::fail('Instanční autentizace nesmí být obnovitelný SQL payload.');
            } catch (CompanyBackupDataSourceException $e) {
                self::assertSame('data_object_kind_unsupported', $e->errorCode);
            }
        }
    }
}
