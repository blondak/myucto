<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Middleware;

use MyInvoice\Infrastructure\Cache\EntityCache;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\FirstRunLockMiddleware;
use MyInvoice\Middleware\TenantDomainMiddleware;
use MyInvoice\Repository\SupplierDomainRepository;
use MyInvoice\Service\Tenant\HostnameNormalizer;
use MyInvoice\Service\Tenant\TenantDomainPolicy;
use MyInvoice\Service\Tenant\TenantDomainResolver;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

final class TenantDomainMiddlewareTest extends TestCase
{
    public function testFirstRunSetupBypassesUnknownHostBeforeDomainLookup(): void
    {
        $resolver = $this->resolver('https://app.example.test');
        $firstRun = $this->createStub(FirstRunLockMiddleware::class);
        $firstRun->method('needsSetup')->willReturn(true);
        $firstRun->method('allowsDuringSetup')->willReturn(true);
        $middleware = new TenantDomainMiddleware(
            $resolver,
            new TenantDomainPolicy(),
            new ResponseFactory(),
            $firstRun,
        );

        $response = $middleware->process(
            (new ServerRequestFactory())->createServerRequest(
                'GET',
                'http://192.0.2.20/api/auth/setup-status',
            ),
            $this->okHandler(),
        );

        self::assertSame(204, $response->getStatusCode());
    }

    public function testUnknownHostIsDeniedAfterSetup(): void
    {
        // Neplatný canonical hostname dovede resolver do UNKNOWN bez DB lookupu.
        $resolver = $this->resolver('https://bad_host');
        $firstRun = $this->createStub(FirstRunLockMiddleware::class);
        $firstRun->method('needsSetup')->willReturn(false);
        $middleware = new TenantDomainMiddleware(
            $resolver,
            new TenantDomainPolicy(),
            new ResponseFactory(),
            $firstRun,
        );

        $response = $middleware->process(
            (new ServerRequestFactory())->createServerRequest('GET', 'https://unknown.example.test/portal'),
            $this->okHandler(),
        );
        $payload = json_decode((string) $response->getBody(), true);

        self::assertSame(421, $response->getStatusCode());
        self::assertSame('unknown_host', $payload['error']['code'] ?? null);
    }

    private function resolver(string $canonicalUrl): TenantDomainResolver
    {
        $connection = $this->createStub(Connection::class);
        return new TenantDomainResolver(
            new Config(['app' => ['url' => $canonicalUrl]]),
            new HostnameNormalizer(),
            new SupplierDomainRepository($connection, EntityCache::disabled()),
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
}
