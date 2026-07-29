<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Middleware;

use MyInvoice\Middleware\ApiScopeMiddleware;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\PermissionMiddleware;
use MyInvoice\Security\EffectiveRole;
use MyInvoice\Security\PermissionCatalog;
use MyInvoice\Security\PermissionChecker;
use MyInvoice\Security\PermissionResolver;
use MyInvoice\Security\RoutePermissionMap;
use MyInvoice\Service\Tenant\SupplierAccess;
use MyInvoice\Service\Tenant\SupplierAccessResolver;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

final class ApiTokenRoleIntersectionTest extends TestCase
{
    public function testReadTokenCannotWriteEvenWhenRoleAllowsWrite(): void
    {
        $response = $this->pipeline(new EffectiveRole(2, 'Zapisovatel', 'staff', true, ['clients.create' => 2]))
            ->handle($this->request('read'));

        self::assertSame(403, $response->getStatusCode());
        self::assertStringContainsString('insufficient_scope', (string) $response->getBody());
    }

    public function testReadWriteTokenCannotWriteWhenRoleAllowsOnlyRead(): void
    {
        $response = $this->pipeline(new EffectiveRole(2, 'Čtenář', 'staff', true, ['clients.create' => 1]))
            ->handle($this->request('read_write'));

        self::assertSame(403, $response->getStatusCode());
        self::assertStringContainsString('forbidden_permission', (string) $response->getBody());
    }

    public function testWritePassesOnlyWhenTokenAndRoleBothAllowIt(): void
    {
        $response = $this->pipeline(new EffectiveRole(2, 'Zapisovatel', 'staff', true, ['clients.create' => 2]))
            ->handle($this->request('read_write'));

        self::assertSame(204, $response->getStatusCode());
    }

    private function pipeline(EffectiveRole $role): RequestHandlerInterface
    {
        $roles = $this->createStub(PermissionResolver::class);
        $roles->method('resolve')->willReturn($role);
        $suppliers = $this->createStub(SupplierAccessResolver::class);
        $suppliers->method('resolve')->willReturn(new SupplierAccess(1, false, null));
        $catalog = new PermissionCatalog();
        $permission = new PermissionMiddleware(
            new ResponseFactory(),
            new RoutePermissionMap(),
            $roles,
            new PermissionChecker($catalog),
            $suppliers,
        );
        $endpoint = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return (new ResponseFactory())->createResponse(204);
            }
        };
        $permissionHandler = new class($permission, $endpoint) implements RequestHandlerInterface {
            public function __construct(
                private readonly PermissionMiddleware $middleware,
                private readonly RequestHandlerInterface $next,
            ) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return $this->middleware->process($request, $this->next);
            }
        };
        $scope = new ApiScopeMiddleware(new ResponseFactory());
        return new class($scope, $permissionHandler) implements RequestHandlerInterface {
            public function __construct(
                private readonly ApiScopeMiddleware $middleware,
                private readonly RequestHandlerInterface $next,
            ) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return $this->middleware->process($request, $this->next);
            }
        };
    }

    private function request(string $scope): ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest('POST', '/api/clients')
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'bearer')
            ->withAttribute(AuthMiddleware::ATTR_API_TOKEN, ['scope' => $scope])
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => 5, 'role_id' => 2]);
    }
}
