<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Tenant;

use MyInvoice\Infrastructure\Cache\EntityCache;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\TenantDomainMiddleware;
use MyInvoice\Repository\SupplierDomainRepository;
use MyInvoice\Service\Tenant\HostnameNormalizer;
use MyInvoice\Service\Tenant\PublicTenantGuard;
use MyInvoice\Service\Tenant\TenantDomainContext;
use MyInvoice\Service\Tenant\TenantDomainResolver;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;

final class PublicTenantGuardTest extends TestCase
{
    public function testCanonicalKeepsExistingPublicTokensCompatible(): void
    {
        self::assertTrue($this->guard()->allows($this->request($this->context(
            TenantDomainContext::CANONICAL,
            null,
            null,
        )), 44));
    }

    public function testCustomDomainOnlyAcceptsTokenOfItsOwnSupplierAndPurpose(): void
    {
        $guard = $this->guard();
        $public = $this->request($this->context(TenantDomainContext::CUSTOM, 44, 'public_links'));
        $portal = $this->request($this->context(TenantDomainContext::CUSTOM, 44, 'portal'));

        self::assertTrue($guard->allows($public, 44));
        self::assertFalse($guard->allows($public, 45));
        self::assertFalse($guard->allows($portal, 44));
    }

    private function guard(): PublicTenantGuard
    {
        $connection = $this->createStub(Connection::class);
        $domains = new SupplierDomainRepository($connection, EntityCache::disabled());
        return new PublicTenantGuard(new TenantDomainResolver(
            new Config(['app' => ['url' => 'https://app.example.test']]),
            new HostnameNormalizer(),
            $domains,
        ));
    }

    private function request(TenantDomainContext $context): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest('GET', 'https://portal.example.test/api/public/invoice/token')
            ->withAttribute(TenantDomainMiddleware::ATTR_CONTEXT, $context);
    }

    private function context(string $mode, ?int $supplierId, ?string $purpose): TenantDomainContext
    {
        return new TenantDomainContext(
            $mode,
            $mode === TenantDomainContext::CANONICAL ? 'app.example.test' : 'portal.example.test',
            $mode === TenantDomainContext::CANONICAL
                ? 'https://app.example.test'
                : 'https://portal.example.test',
            $supplierId !== null ? 5 : null,
            $supplierId,
            $purpose,
            $supplierId !== null ? 'active' : null,
        );
    }
}
