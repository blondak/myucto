<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Tenant;

use MyInvoice\Service\Tenant\TenantDomainContext;
use PHPUnit\Framework\TestCase;

final class TenantDomainContextTest extends TestCase
{
    public function testCustomPortalDomainLocksSupplierAndDoesNotGainPublicPurpose(): void
    {
        $context = new TenantDomainContext(
            TenantDomainContext::CUSTOM,
            'portal.example.test',
            'https://portal.example.test',
            4,
            12,
            'portal',
            'active',
        );

        self::assertTrue($context->locksSupplier());
        self::assertTrue($context->allowsPortal());
        self::assertFalse($context->allowsPublicLinks());
        self::assertSame(12, $context->toArray()['supplier_id']);
    }

    public function testCanonicalOriginAllowsBothPurposesWithoutTenantLock(): void
    {
        $context = new TenantDomainContext(
            TenantDomainContext::CANONICAL,
            'app.example.test',
            'https://app.example.test',
        );

        self::assertFalse($context->locksSupplier());
        self::assertTrue($context->allowsPortal());
        self::assertTrue($context->allowsPublicLinks());
    }
}
