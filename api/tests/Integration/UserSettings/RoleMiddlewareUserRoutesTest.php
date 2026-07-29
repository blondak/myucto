<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\UserSettings;

use MyInvoice\Security\EffectiveRole;
use MyInvoice\Security\PermissionCatalog;
use MyInvoice\Security\PermissionChecker;
use MyInvoice\Security\RoutePermissionMap;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/** Historical filename; verifies the replacement route-permission pipeline. */
#[Group('integration')]
final class RoleMiddlewareUserRoutesTest extends TestCase
{
    private RoutePermissionMap $routes;
    private PermissionChecker $checker;

    protected function setUp(): void
    {
        $this->routes = new RoutePermissionMap();
        $this->checker = new PermissionChecker(new PermissionCatalog());
    }

    public function testProfileWriteAllowsUserFiltersAndPreferences(): void
    {
        $role = new EffectiveRole(3, 'Pouze pro čtení', 'staff', true, ['profile' => 2]);
        self::assertTrue($this->allows('POST', '/api/user/filters', $role));
        self::assertTrue($this->allows('PUT', '/api/user/preferences/table.invoices', $role));
    }

    public function testAccountingImportRequiresAccountingWrite(): void
    {
        $reader = new EffectiveRole(3, 'Pouze pro čtení', 'staff', true, ['accounting' => 1]);
        $accountant = new EffectiveRole(2, 'Účetní', 'staff', true, ['accounting' => 2]);
        self::assertFalse($this->allows('POST', '/api/accounting/accounts/import', $reader));
        self::assertTrue($this->allows('POST', '/api/accounting/accounts/import', $accountant));
    }

    private function allows(string $method, string $path, EffectiveRole $role): bool
    {
        $route = $this->routes->match($method, $path);
        self::assertNotNull($route, "$method $path");
        return $route->key !== null && $this->checker->allows($role, $route->key, $route->minimum);
    }
}
