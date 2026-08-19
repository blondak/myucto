<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Middleware;

use MyInvoice\Middleware\ApiVersionRewriteMiddleware;
use MyInvoice\Service\Auth\WebAuthnOperationPolicy;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

final class ApiVersionRewriteMiddlewareTest extends TestCase
{
    public function testInternalAuthEndpointsAreNotPublishedUnderV1(): void
    {
        foreach ((new WebAuthnOperationPolicy())->inventory() as $name => $operation) {
            if ($operation['example_path'] === '/api/auth/login') continue;
            $path = '/api/v1/' . substr($operation['example_path'], 5);
            $response = (new ApiVersionRewriteMiddleware())->process(
                (new ServerRequestFactory())->createServerRequest($operation['method'], $path),
                $this->handler(),
            );
            self::assertSame(404, $response->getStatusCode(), "$name: $path");
        }
    }

    public function testMixedLoginAliasIsRewrittenBeforeDownstreamOriginPolicy(): void
    {
        $handler = new class implements RequestHandlerInterface {
            public ?string $seenPath = null;

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->seenPath = $request->getUri()->getPath();
                return (new ResponseFactory())->createResponse(204);
            }
        };
        $response = (new ApiVersionRewriteMiddleware())->process(
            (new ServerRequestFactory())->createServerRequest('POST', '/api/v1/auth/login'),
            $handler,
        );

        self::assertSame(204, $response->getStatusCode());
        self::assertSame('/api/auth/login', $handler->seenPath);
        self::assertTrue((new WebAuthnOperationPolicy())->requiresCanonicalOrigin(
            'POST',
            (string) $handler->seenPath,
        ));
    }

    private function handler(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return (new ResponseFactory())->createResponse(204);
            }
        };
    }
}
