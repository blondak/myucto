<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Middleware;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\CsrfMiddleware;
use MyInvoice\Middleware\FirstRunLockMiddleware;
use MyInvoice\Middleware\TenantDomainMiddleware;
use MyInvoice\Service\Tenant\TenantDomainContext;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

final class CsrfTenantOriginTest extends TestCase
{
    private CsrfMiddleware $middleware;

    protected function setUp(): void
    {
        $firstRun = $this->createStub(FirstRunLockMiddleware::class);
        $firstRun->method('needsSetup')->willReturn(false);
        $this->middleware = new CsrfMiddleware(
            new Config(['app' => ['url' => 'https://app.example.test', 'env' => 'production']]),
            new ResponseFactory(),
            $firstRun,
        );
    }

    #[DataProvider('acceptedSources')]
    public function testCustomDomainAcceptsOnlyItsOwnOriginOrReferer(string $header, string $value): void
    {
        $response = $this->middleware->process($this->request()->withHeader($header, $value), $this->okHandler());

        self::assertSame(204, $response->getStatusCode());
    }

    /** @return iterable<string,array{string,string}> */
    public static function acceptedSources(): iterable
    {
        yield 'origin' => ['Origin', 'https://portal.example.test'];
        yield 'referer' => ['Referer', 'https://portal.example.test/settings/profile'];
        yield 'default https port' => ['Origin', 'https://portal.example.test:443'];
    }

    #[DataProvider('rejectedSources')]
    public function testCanonicalForeignAndLookalikeOriginsAreRejected(string $value): void
    {
        $response = $this->middleware->process($this->request()->withHeader('Origin', $value), $this->okHandler());
        $payload = json_decode((string) $response->getBody(), true);

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('origin_mismatch', $payload['error']['code'] ?? null);
    }

    /** @return iterable<string,array{string}> */
    public static function rejectedSources(): iterable
    {
        yield 'canonical' => ['https://app.example.test'];
        yield 'lookalike suffix' => ['https://portal.example.test.attacker.invalid'];
        yield 'wrong scheme' => ['http://portal.example.test'];
        yield 'wrong port' => ['https://portal.example.test:8443'];
        yield 'trailing dot is not the verified origin' => ['https://portal.example.test.'];
    }

    private function request(): ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest('POST', 'https://portal.example.test/api/settings/domains')
            ->withHeader('X-CSRF-Token', 'csrf-test-token')
            ->withAttribute(AuthMiddleware::ATTR_SESSION, ['csrf_token' => 'csrf-test-token'])
            ->withAttribute(TenantDomainMiddleware::ATTR_CONTEXT, new TenantDomainContext(
                TenantDomainContext::CUSTOM,
                'portal.example.test',
                'https://portal.example.test',
                8,
                21,
                'portal',
                'active',
            ));
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
