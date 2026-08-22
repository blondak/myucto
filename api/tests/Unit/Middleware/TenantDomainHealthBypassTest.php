<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Middleware;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Middleware\FirstRunLockMiddleware;
use MyInvoice\Middleware\TenantDomainMiddleware;
use MyInvoice\Repository\SupplierDomainRepository;
use MyInvoice\Service\System\AppUrlConfiguration;
use MyInvoice\Service\Tenant\HostnameNormalizer;
use MyInvoice\Service\Tenant\TenantDomainFeature;
use MyInvoice\Service\Tenant\TenantDomainPolicy;
use MyInvoice\Service\Tenant\TenantDomainResolver;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\NullLogger;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * H-29 — host gate (`421`) vs. monitoring.
 *
 * `TenantDomainMiddlewareTest` pokrývá případ, kdy je `app.url` nepoužitelné.
 * Tenhle soubor drží druhou půlku: i s PLATNÝM `app.url` musí zůstat read-only
 * `/api/health` dostupný přes cizí hostname. Bez toho by monitoring hostingu
 * (servisní hostname, interní jméno instance) dostával `421` a nešlo by odlišit
 * výpadek instance od chybné konfigurace `app.url`.
 *
 * Rozšíření výjimky má hranici a ta se tu taky hlídá: vlastní domény firem ji
 * nedostávají a nezdravotní cesty přes cizí host dál padají na `421`.
 */
final class TenantDomainHealthBypassTest extends TestCase
{
    private const CANONICAL = 'https://app.example.test';

    /** @param array<string,mixed>|null $domain */
    private function middleware(?array $domain = null): TenantDomainMiddleware
    {
        $config = new Config(['app' => ['url' => self::CANONICAL]]);
        $domains = $this->createStub(SupplierDomainRepository::class);
        $domains->method('findByHostname')->willReturn($domain);

        $firstRun = $this->createStub(FirstRunLockMiddleware::class);
        $firstRun->method('needsSetup')->willReturn(false);

        return new TenantDomainMiddleware(
            new TenantDomainResolver(
                $config,
                new HostnameNormalizer(),
                $domains,
                new TenantDomainFeature(new Config(['domains' => ['enabled' => true]])),
            ),
            new TenantDomainPolicy(),
            new ResponseFactory(),
            $firstRun,
            new AppUrlConfiguration($config, new HostnameNormalizer(), new NullLogger()),
        );
    }

    private function okHandler(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return (new ResponseFactory())->createResponse(204);
            }
        };
    }

    private function call(TenantDomainMiddleware $middleware, string $method, string $url): ResponseInterface
    {
        return $middleware->process(
            (new ServerRequestFactory())->createServerRequest($method, $url),
            $this->okHandler(),
        );
    }

    /**
     * Jádro H-29 bodu 2. Bez výjimky vrací tenhle případ `421 unknown_host`,
     * protože `monitoring.example.test` není canonical ani v `supplier_domains`.
     */
    public function testHealthOverForeignHostIsNot421EvenWithValidAppUrl(): void
    {
        foreach (['GET', 'HEAD'] as $method) {
            $response = $this->call(
                $this->middleware(),
                $method,
                'https://monitoring.example.test/api/health',
            );

            self::assertSame(
                204,
                $response->getStatusCode(),
                $method . ' /api/health přes cizí Host nesmí skončit 421 — monitoring hostingu '
                . 'volá přes hostname instance, ne přes app.url.',
            );
        }
    }

    public function testHealthOverBareIpIsNot421(): void
    {
        $response = $this->call($this->middleware(), 'GET', 'https://192.0.2.10/api/health');

        self::assertSame(204, $response->getStatusCode());
    }

    public function testForeignHostStillGets421ForEverythingElse(): void
    {
        foreach ([
            ['GET', '/api/invoices'],
            ['POST', '/api/health'],
            ['GET', '/api/health/'],
            ['GET', '/'],
        ] as [$method, $path]) {
            $response = $this->call(
                $this->middleware(),
                $method,
                'https://monitoring.example.test' . $path,
            );
            $payload = json_decode((string) $response->getBody(), true);

            self::assertSame(421, $response->getStatusCode(), "$method $path");
            self::assertSame('unknown_host', $payload['error']['code'] ?? null, "$method $path");
        }
    }

    /**
     * Hranice výjimky: na vlastní doméně firmy zůstává health mimo klientské
     * rozhraní. Verze a provozní stav instalace do klientské domény nepatří.
     */
    public function testCustomTenantDomainDoesNotGetTheHealthException(): void
    {
        $middleware = $this->middleware([
            'id' => 5,
            'supplier_id' => 9,
            'purpose' => 'portal',
            'status' => 'active',
        ]);

        $response = $this->call($middleware, 'GET', 'https://klient.example.test/api/health');
        $payload = json_decode((string) $response->getBody(), true);

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('client_surface_only', $payload['error']['code'] ?? null);
    }

    public function testCanonicalHostKeepsWorkingNormally(): void
    {
        $response = $this->call($this->middleware(), 'GET', self::CANONICAL . '/api/health');

        self::assertSame(204, $response->getStatusCode());
    }

    /**
     * H-29 bod 3 — tenant se určuje z `Host`, NIKDY z `X-Forwarded-Host`.
     * Kdyby resolver poslouchal proxy hlavičku, mohl by si klient tenanta zvolit
     * sám jedním headerem.
     */
    public function testForwardedHostHeaderCannotOverrideTheRealHost(): void
    {
        $middleware = $this->middleware();
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', 'https://monitoring.example.test/api/invoices')
            ->withHeader('X-Forwarded-Host', parse_url(self::CANONICAL, PHP_URL_HOST));

        $response = $middleware->process($request, $this->okHandler());
        $payload = json_decode((string) $response->getBody(), true);

        self::assertSame(421, $response->getStatusCode());
        self::assertSame('unknown_host', $payload['error']['code'] ?? null);
    }
}
