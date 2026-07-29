<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Security;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\InvalidPermission;
use MyInvoice\Repository\PermissionNotAllowedForRoleType;
use MyInvoice\Repository\RoleRepository;
use MyInvoice\Security\PermissionCatalog;
use PHPUnit\Framework\TestCase;

final class RoleRepositoryValidationTest extends TestCase
{
    public function testUnknownPermissionIsRejected(): void
    {
        $repo = new RoleRepository($this->createStub(Connection::class), new PermissionCatalog());
        $method = new \ReflectionMethod($repo, 'validatePermissions');
        $this->expectException(InvalidPermission::class);
        $method->invoke($repo, 'staff', ['made.up' => 2]);
    }

    public function testStaffOnlyPermissionIsRejectedForClient(): void
    {
        $repo = new RoleRepository($this->createStub(Connection::class), new PermissionCatalog());
        $method = new \ReflectionMethod($repo, 'validatePermissions');
        $this->expectException(PermissionNotAllowedForRoleType::class);
        $method->invoke($repo, 'client', ['accounting' => 1]);
    }

    public function testClientSafePermissionIsAccepted(): void
    {
        $repo = new RoleRepository($this->createStub(Connection::class), new PermissionCatalog());
        $method = new \ReflectionMethod($repo, 'validatePermissions');
        self::assertSame(['invoices' => 2], $method->invoke($repo, 'client', ['invoices' => 'write']));
    }
}
