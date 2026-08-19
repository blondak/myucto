<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Tenant;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Repository\SupplierDomainRepository;
use MyInvoice\Service\Tenant\HostnameNormalizer;
use MyInvoice\Service\Tenant\TenantDomainContext;
use MyInvoice\Service\Tenant\TenantDomainFeature;
use MyInvoice\Service\Tenant\TenantDomainPolicy;
use MyInvoice\Service\Tenant\TenantDomainResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * Vlastní domény jsou opt-in a VYPNUTÉ jsou výchozí stav každé instalace.
 * Tenhle test hlídá, že vypnutá featura vrací přesně předchozí chování: každý
 * hostname je canonical, nic se nedohledává v DB a nic nekončí na 421.
 */
final class TenantDomainFeatureToggleTest extends TestCase
{
    /** @return array<string,array{string}> */
    public static function foreignHostProvider(): array
    {
        return [
            'jiná doména'      => ['jinak.example.test'],
            'www varianta'     => ['www.app.example.test'],
            'bez www varianty' => ['example.test'],
            'LAN IP'           => ['192.168.1.10'],
            'localhost'        => ['localhost'],
        ];
    }

    #[DataProvider('foreignHostProvider')]
    public function testDisabledFeatureTreatsEveryHostAsCanonical(string $host): void
    {
        $context = $this->resolver(false)->resolve(
            (new ServerRequestFactory())->createServerRequest('GET', 'https://' . $host . '/')
        );

        self::assertSame(TenantDomainContext::CANONICAL, $context->mode);
        self::assertFalse($context->locksSupplier());
        self::assertNull((new TenantDomainPolicy())->denial($context, 'GET', '/'));
    }

    #[DataProvider('foreignHostProvider')]
    public function testEnabledFeatureStillRejectsForeignHost(string $host): void
    {
        $context = $this->resolver(true)->resolve(
            (new ServerRequestFactory())->createServerRequest('GET', 'https://' . $host . '/')
        );

        self::assertSame(TenantDomainContext::UNKNOWN, $context->mode);
        $denial = (new TenantDomainPolicy())->denial($context, 'GET', '/');
        self::assertNotNull($denial);
        self::assertSame(421, $denial['status']);
    }

    public function testDisabledFeatureKeepsCanonicalOriginForCsrfComparison(): void
    {
        $context = $this->resolver(false)->resolve(
            (new ServerRequestFactory())->createServerRequest('GET', 'https://jinak.example.test/')
        );

        // CsrfMiddleware porovnává právě tenhle origin. Musí zůstat app.url,
        // ne origin requestu, jinak by vypnutá featura zpřísnila CSRF kontrolu.
        self::assertSame('https://app.example.test', $context->origin);
    }

    /**
     * Prázdné `domains.enabled` nesmí featuru zapnout omylem — instalace bez
     * klíče v cfg.php (a bez baseline defaultu) je vypnutá.
     */
    public function testMissingConfigKeyMeansDisabled(): void
    {
        self::assertFalse((new TenantDomainFeature(new Config([])))->isEnabled());
        self::assertFalse((new TenantDomainFeature(new Config(['domains' => ['enabled' => false]])))->isEnabled());
        self::assertTrue((new TenantDomainFeature(new Config(['domains' => ['enabled' => true]])))->isEnabled());
        // ENV override přichází jako string; bool cast v Config je až u load().
        self::assertTrue((new TenantDomainFeature(new Config(['domains' => ['enabled' => '1']])))->isEnabled());
    }

    private function resolver(bool $enabled): TenantDomainResolver
    {
        if ($enabled) {
            $domains = $this->createStub(SupplierDomainRepository::class);
            $domains->method('findByHostname')->willReturn(null);
        } else {
            // Vypnutá featura se na domény nesmí ani zeptat — jinak by každý
            // request platil dotaz do DB za rozhodnutí, které je předem známé.
            $domains = $this->createMock(SupplierDomainRepository::class);
            $domains->expects(self::never())->method('findByHostname');
        }

        return new TenantDomainResolver(
            new Config(['app' => ['url' => 'https://app.example.test']]),
            new HostnameNormalizer(),
            $domains,
            new TenantDomainFeature(new Config(['domains' => ['enabled' => $enabled]])),
        );
    }
}
