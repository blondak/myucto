<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Security;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\PermissionCatalog;
use MyInvoice\Security\PermissionResolver;
use MyInvoice\Service\Tenant\SupplierAccess;
use MyInvoice\Service\Tenant\SupplierAccessResolver;
use PDO;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;

final class PermissionResolverTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec(
            'CREATE TABLE roles (
                id INTEGER PRIMARY KEY,
                system_key TEXT NULL,
                name TEXT NOT NULL,
                role_type TEXT NOT NULL,
                is_active INTEGER NOT NULL
            );
            CREATE TABLE role_permissions (
                role_id INTEGER NOT NULL,
                permission_key TEXT NOT NULL,
                access_level INTEGER NOT NULL
            )'
        );
    }

    public function testUsesDefaultRoleWhenMembershipHasNoOverride(): void
    {
        $this->insertRole(2, 'Účetní', 'staff', true, ['invoices' => AccessLevel::WRITE->value]);

        $role = $this->resolver(new SupplierAccess(11, false, null))->resolve($this->request(2, 'staff'));

        self::assertSame(2, $role->id);
        self::assertSame(AccessLevel::WRITE, $role->level('invoices'));
    }

    public function testSupplierOverrideReplacesDefaultRole(): void
    {
        $this->insertRole(2, 'Účetní', 'staff', true, ['invoices' => AccessLevel::WRITE->value]);
        $this->insertRole(7, 'Čtenář', 'staff', true, ['invoices' => AccessLevel::READ->value]);

        $role = $this->resolver(new SupplierAccess(11, false, 7))->resolve($this->request(2, 'staff'));

        self::assertSame(7, $role->id);
        self::assertSame(AccessLevel::READ, $role->level('invoices'));
    }

    public function testDeactivatedRoleGrantsNoPermission(): void
    {
        $this->insertRole(2, 'Neaktivní', 'staff', false, ['invoices' => AccessLevel::WRITE->value]);

        $role = $this->resolver(new SupplierAccess(11, false, null))->resolve($this->request(2, 'staff'));

        self::assertFalse($role->isActive);
        self::assertSame(AccessLevel::NONE, $role->level('invoices'));
    }

    public function testOverrideWithDifferentRoleTypeFailsClosed(): void
    {
        $this->insertRole(2, 'Účetní', 'staff', true, ['invoices' => AccessLevel::WRITE->value]);
        $this->insertRole(8, 'Klient', 'client', true, ['invoices' => AccessLevel::READ->value]);

        $role = $this->resolver(new SupplierAccess(11, false, 8))->resolve($this->request(2, 'staff'));

        self::assertSame(0, $role->id);
        self::assertFalse($role->isActive);
        self::assertSame(AccessLevel::NONE, $role->level('invoices'));
    }

    public function testMissingOverrideRoleFailsClosed(): void
    {
        $this->insertRole(2, 'Účetní', 'staff', true, ['invoices' => AccessLevel::WRITE->value]);

        $role = $this->resolver(new SupplierAccess(11, false, 999))->resolve($this->request(2, 'staff'));

        self::assertSame(0, $role->id);
        self::assertFalse($role->isActive);
        self::assertSame(AccessLevel::NONE, $role->level('invoices'));
    }

    /** @param array<string, int> $permissions */
    private function insertRole(int $id, string $name, string $type, bool $active, array $permissions): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO roles (id, system_key, name, role_type, is_active) VALUES (?, NULL, ?, ?, ?)'
        );
        $stmt->execute([$id, $name, $type, $active ? 1 : 0]);
        $stmt = $this->pdo->prepare(
            'INSERT INTO role_permissions (role_id, permission_key, access_level) VALUES (?, ?, ?)'
        );
        foreach ($permissions as $key => $level) {
            $stmt->execute([$id, $key, $level]);
        }
    }

    private function resolver(SupplierAccess $access): PermissionResolver
    {
        $db = $this->createStub(Connection::class);
        $db->method('pdo')->willReturn($this->pdo);
        $suppliers = $this->createStub(SupplierAccessResolver::class);
        $suppliers->method('resolve')->willReturn($access);
        return new PermissionResolver($db, $suppliers, new PermissionCatalog());
    }

    private function request(int $roleId, string $roleType): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest('GET', '/api/invoices')
            ->withAttribute(AuthMiddleware::ATTR_USER, [
                'id' => 10,
                'role_id' => $roleId,
                'role_summary' => ['id' => $roleId, 'type' => $roleType],
            ]);
    }
}
