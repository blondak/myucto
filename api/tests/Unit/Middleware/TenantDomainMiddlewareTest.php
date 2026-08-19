<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Middleware;

use MyInvoice\Infrastructure\Cache\EntityCache;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\FirstRunLockMiddleware;
use MyInvoice\Middleware\TenantDomainMiddleware;
use MyInvoice\Repository\SupplierDomainRepository;
use MyInvoice\Service\System\AppUrlConfiguration;
use MyInvoice\Service\Tenant\HostnameNormalizer;
use MyInvoice\Service\Tenant\TenantDomainPolicy;
use MyInvoice\Service\Tenant\TenantDomainResolver;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\NullLogger;
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
            $this->appUrl('https://app.example.test'),
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
            $this->appUrl('https://bad_host'),
        );

        $response = $middleware->process(
            (new ServerRequestFactory())->createServerRequest('GET', 'https://unknown.example.test/portal'),
            $this->okHandler(),
        );
        $payload = json_decode((string) $response->getBody(), true);

        self::assertSame(421, $response->getStatusCode());
        self::assertSame('unknown_host', $payload['error']['code'] ?? null);
    }

    public function testAbsentAndExactlyEmptyCanonicalUrlRetainLegacyRequestHostFallback(): void
    {
        foreach ([
            'absent' => new Config([]),
            'exactly empty' => new Config(['app' => ['url' => '']]),
        ] as $case => $config) {
            $connection = $this->createStub(Connection::class);
            $resolver = new TenantDomainResolver(
                $config,
                new HostnameNormalizer(),
                new SupplierDomainRepository($connection, EntityCache::disabled()),
            );
            $firstRun = $this->createStub(FirstRunLockMiddleware::class);
            $firstRun->method('needsSetup')->willReturn(false);
            $middleware = new TenantDomainMiddleware(
                $resolver,
                new TenantDomainPolicy(),
                new ResponseFactory(),
                $firstRun,
                new AppUrlConfiguration($config, new HostnameNormalizer(), new NullLogger()),
            );

            $response = $middleware->process(
                (new ServerRequestFactory())->createServerRequest(
                    'GET',
                    'https://fallback.example.test/api/auth/setup-status',
                ),
                $this->okHandler(),
            );

            self::assertSame(204, $response->getStatusCode(), $case);
        }
    }

    public function testHealthRemainsReachableWhenCanonicalUrlIsMalformed(): void
    {
        $resolver = $this->resolver('https://bad_host');
        $firstRun = $this->createStub(FirstRunLockMiddleware::class);
        $firstRun->method('needsSetup')->willReturn(false);
        $middleware = new TenantDomainMiddleware(
            $resolver,
            new TenantDomainPolicy(),
            new ResponseFactory(),
            $firstRun,
            $this->appUrl('https://bad_host'),
        );

        $response = $middleware->process(
            (new ServerRequestFactory())->createServerRequest(
                'GET',
                'https://diagnostics.example.test/api/health',
            ),
            $this->okHandler(),
        );

        self::assertSame(204, $response->getStatusCode());
    }

    public function testMalformedCanonicalUrlDoesNotBypassOtherRoutesOrMethods(): void
    {
        foreach ([
            ['POST', '/api/health'],
            ['OPTIONS', '/api/health'],
            ['GET', '/api/health/'],
            ['GET', '/api/health/details'],
            ['GET', '/api/version'],
            ['GET', '/api/auth/setup-preflight'],
            ['GET', '/'],
        ] as [$method, $path]) {
            $firstRun = $this->createStub(FirstRunLockMiddleware::class);
            $firstRun->method('needsSetup')->willReturn(false);
            $middleware = new TenantDomainMiddleware(
                $this->resolver('https://bad_host'),
                new TenantDomainPolicy(),
                new ResponseFactory(),
                $firstRun,
                $this->appUrl('https://bad_host'),
            );

            $response = $middleware->process(
                (new ServerRequestFactory())->createServerRequest(
                    $method,
                    'https://diagnostics.example.test' . $path,
                ),
                $this->okHandler(),
            );
            $payload = json_decode((string) $response->getBody(), true);

            self::assertSame(421, $response->getStatusCode(), "$method $path");
            self::assertSame('unknown_host', $payload['error']['code'] ?? null, "$method $path");
        }
    }

    public function testWhitespaceCanonicalUrlGetsOnlyTheExactReadOnlyHealthException(): void
    {
        foreach ([
            ['GET', '/api/health', 204],
            ['HEAD', '/api/health', 204],
            ['POST', '/api/health', 421],
            ['GET', '/api/version', 421],
            ['GET', '/', 421],
        ] as [$method, $path, $expectedStatus]) {
            $firstRun = $this->createStub(FirstRunLockMiddleware::class);
            $firstRun->method('needsSetup')->willReturn(false);
            $middleware = new TenantDomainMiddleware(
                $this->resolver(" \t\r\n "),
                new TenantDomainPolicy(),
                new ResponseFactory(),
                $firstRun,
                $this->appUrl(" \t\r\n "),
            );

            $response = $middleware->process(
                (new ServerRequestFactory())->createServerRequest(
                    $method,
                    'https://diagnostics.example.test' . $path,
                ),
                $this->okHandler(),
            );

            self::assertSame($expectedStatus, $response->getStatusCode(), "$method $path");
        }
    }

    public function testHeadHealthAlsoRemainsReachableWithMalformedCanonicalUrl(): void
    {
        $firstRun = $this->createStub(FirstRunLockMiddleware::class);
        $firstRun->method('needsSetup')->willReturn(false);
        $middleware = new TenantDomainMiddleware(
            $this->resolver('not an origin'),
            new TenantDomainPolicy(),
            new ResponseFactory(),
            $firstRun,
            $this->appUrl('not an origin'),
        );

        $response = $middleware->process(
            (new ServerRequestFactory())->createServerRequest(
                'HEAD',
                'https://diagnostics.example.test/api/health',
            ),
            $this->okHandler(),
        );

        self::assertSame(204, $response->getStatusCode());
    }

    public function testStoredDomainCollidingWithCanonicalHostnameFailsClosed(): void
    {
        $appUrl = $this->appUrl('https://portal.example.test');
        $firstRun = $this->createStub(FirstRunLockMiddleware::class);
        $firstRun->method('needsSetup')->willReturn(false);
        $middleware = new TenantDomainMiddleware(
            $this->resolver('https://portal.example.test', ['id' => 73]),
            new TenantDomainPolicy(),
            new ResponseFactory(),
            $firstRun,
            $appUrl,
        );

        $response = $middleware->process(
            (new ServerRequestFactory())->createServerRequest(
                'GET',
                'https://portal.example.test/',
            ),
            $this->okHandler(),
        );
        $payload = json_decode((string) $response->getBody(), true);

        self::assertSame(421, $response->getStatusCode());
        self::assertSame('canonical_hostname_conflict', $payload['error']['code'] ?? null);
        self::assertSame(
            AppUrlConfiguration::STATE_HOSTNAME_CONFLICT,
            $appUrl->status()['state'],
        );
    }

    public function testCanonicalHostnameConflictKeepsOnlyExactReadOnlyHealthReachable(): void
    {
        foreach ([
            ['GET', '/api/health', 204],
            ['HEAD', '/api/health', 204],
            ['POST', '/api/health', 421],
            ['GET', '/api/health/', 421],
            ['GET', '/api/version', 421],
            ['GET', '/', 421],
        ] as [$method, $path, $expectedStatus]) {
            $appUrl = $this->appUrl('https://portal.example.test');
            $firstRun = $this->createStub(FirstRunLockMiddleware::class);
            $firstRun->method('needsSetup')->willReturn(false);
            $middleware = new TenantDomainMiddleware(
                $this->resolver('https://portal.example.test', ['id' => 73]),
                new TenantDomainPolicy(),
                new ResponseFactory(),
                $firstRun,
                $appUrl,
            );

            $response = $middleware->process(
                (new ServerRequestFactory())->createServerRequest(
                    $method,
                    'https://portal.example.test' . $path,
                ),
                $this->okHandler(),
            );

            self::assertSame($expectedStatus, $response->getStatusCode(), "$method $path");
            self::assertSame(
                AppUrlConfiguration::REASON_HOSTNAME_CONFLICT,
                $appUrl->status()['reason_code'],
                "$method $path",
            );
        }
    }

    public function testDatabaseFailureDuringCanonicalCollisionLookupDoesNotMaskHealth(): void
    {
        $domains = $this->createStub(SupplierDomainRepository::class);
        $domains->method('findByHostname')->willThrowException(new \PDOException('unavailable'));
        $resolver = new TenantDomainResolver(
            new Config(['app' => ['url' => 'https://app.example.test']]),
            new HostnameNormalizer(),
            $domains,
        );
        $firstRun = $this->createStub(FirstRunLockMiddleware::class);
        $firstRun->method('needsSetup')->willReturn(false);
        $middleware = new TenantDomainMiddleware(
            $resolver,
            new TenantDomainPolicy(),
            new ResponseFactory(),
            $firstRun,
            $this->appUrl('https://app.example.test'),
        );

        $response = $middleware->process(
            (new ServerRequestFactory())->createServerRequest(
                'GET',
                'https://app.example.test/api/health',
            ),
            $this->okHandler(),
        );

        self::assertSame(204, $response->getStatusCode());
    }

    /** @param array<string,mixed>|null $canonicalDomain */
    private function resolver(string $canonicalUrl, ?array $canonicalDomain = null): TenantDomainResolver
    {
        $domains = $this->createStub(SupplierDomainRepository::class);
        $domains->method('findByHostname')->willReturn($canonicalDomain);
        return new TenantDomainResolver(
            new Config(['app' => ['url' => $canonicalUrl]]),
            new HostnameNormalizer(),
            $domains,
        );
    }

    private function appUrl(string $canonicalUrl): AppUrlConfiguration
    {
        return new AppUrlConfiguration(
            new Config(['app' => ['url' => $canonicalUrl]]),
            new HostnameNormalizer(),
            new NullLogger(),
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
