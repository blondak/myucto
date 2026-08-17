<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Tenant;

use MyInvoice\Service\Tenant\TenantDomainContext;
use MyInvoice\Service\Tenant\TenantDomainPolicy;
use PHPUnit\Framework\TestCase;

final class TenantDomainPolicyTest extends TestCase
{
    private TenantDomainPolicy $policy;

    protected function setUp(): void
    {
        $this->policy = new TenantDomainPolicy();
    }

    public function testUnknownHostnameIsRejectedBeforeRouting(): void
    {
        $denial = $this->policy->denial(
            new TenantDomainContext(TenantDomainContext::UNKNOWN, 'unknown.example.test', ''),
            'GET',
            '/portal',
        );

        self::assertSame(421, $denial['status'] ?? null);
        self::assertSame('unknown_host', $denial['code'] ?? null);
    }

    public function testPendingHostnameOnlyServesItsExactVerificationEndpoint(): void
    {
        $context = $this->context(TenantDomainContext::VERIFICATION, 'all');
        $token = str_repeat('a', 64);

        self::assertNull($this->policy->denial(
            $context,
            'GET',
            '/api/public/domain-verification/' . $token,
        ));
        self::assertSame(421, $this->policy->denial($context, 'GET', '/portal')['status'] ?? null);
        self::assertSame(
            421,
            $this->policy->denial(
                $context,
                'POST',
                '/api/public/domain-verification/' . $token,
            )['status'] ?? null,
        );
    }

    public function testPortalOnlyDomainCannotServePublicTokenRoutes(): void
    {
        $context = $this->context(TenantDomainContext::CUSTOM, 'portal');

        self::assertSame(
            404,
            $this->policy->denial($context, 'GET', '/api/public/invoice/' . str_repeat('a', 48))['status'] ?? null,
        );
        self::assertNull($this->policy->denial($context, 'GET', '/portal'));
    }

    public function testPublicOnlyDomainCannotServePortalButCanServePublicPage(): void
    {
        $context = $this->context(TenantDomainContext::CUSTOM, 'public_links');

        self::assertSame(404, $this->policy->denial($context, 'GET', '/')['status'] ?? null);
        self::assertSame(404, $this->policy->denial($context, 'GET', '/portal')['status'] ?? null);
        self::assertNull($this->policy->denial($context, 'GET', '/invoice/token'));
        self::assertNull($this->policy->denial($context, 'GET', '/assets/app.js'));
    }

    private function context(string $mode, string $purpose): TenantDomainContext
    {
        return new TenantDomainContext(
            $mode,
            'portal.example.test',
            'https://portal.example.test',
            7,
            12,
            $purpose,
            $mode === TenantDomainContext::CUSTOM ? 'active' : 'pending',
        );
    }
}
