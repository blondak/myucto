<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Tenant;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\UserSupplierRepository;
use MyInvoice\Service\Tenant\SupplierAccessResolver;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;

final class SupplierAccessResolverDynamicRolesTest extends TestCase
{
    public function testNonSuperadminWithoutMembershipIsDenied(): void
    {
        $memberships = $this->createStub(UserSupplierRepository::class);
        $memberships->method('assignmentsForUser')->willReturn([]);
        $resolver = new SupplierAccessResolver($this->createStub(Connection::class), $memberships);
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/api/invoices')
            ->withAttribute(AuthMiddleware::ATTR_USER, [
                'id' => 10,
                'role_id' => 2,
                'role' => ['id' => 2, 'type' => 'staff'],
                'is_superadmin' => false,
            ]);
        $access = $resolver->resolve($request);
        self::assertTrue($access->denied);
        self::assertSame(0, $access->supplierId);
    }

    public function testBoundTokenOutsideMembershipIsDenied(): void
    {
        $memberships = $this->createStub(UserSupplierRepository::class);
        $memberships->method('assignmentsForUser')->willReturn([11 => null]);
        $resolver = new SupplierAccessResolver($this->createStub(Connection::class), $memberships);
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/api/invoices')
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => 10, 'role_id' => 2, 'role' => ['type' => 'staff']])
            ->withAttribute(AuthMiddleware::ATTR_API_TOKEN, ['supplier_id' => 12]);
        $access = $resolver->resolve($request);
        self::assertTrue($access->denied);
        self::assertSame(12, $access->supplierId);
    }

    public function testBoundTokenReturnsNumericRoleOverride(): void
    {
        $memberships = $this->createStub(UserSupplierRepository::class);
        $memberships->method('assignmentsForUser')->willReturn([11 => 7]);
        $resolver = new SupplierAccessResolver($this->createStub(Connection::class), $memberships);
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/api/invoices')
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => 10, 'role_id' => 2, 'role' => ['type' => 'staff']])
            ->withAttribute(AuthMiddleware::ATTR_API_TOKEN, ['supplier_id' => 11]);
        $access = $resolver->resolve($request);
        self::assertFalse($access->denied);
        self::assertSame(7, $access->roleIdOverride);
    }
}
