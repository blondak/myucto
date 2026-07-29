<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Stock;

use MyInvoice\Security\EffectiveRole;
use MyInvoice\Security\PermissionCatalog;
use MyInvoice\Security\PermissionChecker;
use MyInvoice\Security\RoutePermissionMap;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class StockClientAccessDeniedTest extends TestCase
{
    private RoutePermissionMap $routes;
    private PermissionChecker $checker;
    private EffectiveRole $client;

    protected function setUp(): void
    {
        $this->routes = new RoutePermissionMap();
        $this->checker = new PermissionChecker(new PermissionCatalog());
        $this->client = new EffectiveRole(4, 'Klient', 'client', true, [
            'invoices' => 2,
            'purchase_invoices' => 2,
            'stock' => 2,
        ]);
    }

    public function testClientCannotUseStockModuleRoutes(): void
    {
        foreach ([
            ['GET', '/api/stock/documents'],
            ['POST', '/api/stock/items'],
            ['GET', '/api/stock/warehouses'],
        ] as [$method, $path]) {
            self::assertFalse($this->allows($method, $path, $this->client), "$method $path");
        }
    }

    public function testNestedStockEntryPointsMapToStaffOnlyStockPermission(): void
    {
        foreach ([
            ['GET', '/api/invoices/1/stock-documents'],
            ['GET', '/api/purchase-invoices/1/stock-receipt'],
            ['POST', '/api/purchase-invoices/1/stock-receipt'],
        ] as [$method, $path]) {
            $route = $this->routes->match($method, $path);
            self::assertSame('stock', $route?->key, "$method $path");
            self::assertFalse($this->allows($method, $path, $this->client), "$method $path");
        }
    }

    public function testSuperadminBypassesStockMatrix(): void
    {
        $superadmin = new EffectiveRole(1, 'Superadmin', 'superadmin', true, [], 'superadmin');
        self::assertTrue($this->allows('GET', '/api/stock/documents', $superadmin));
    }

    private function allows(string $method, string $path, EffectiveRole $role): bool
    {
        $route = $this->routes->match($method, $path);
        self::assertNotNull($route, "$method $path");
        return $route->key !== null && $this->checker->allows($role, $route->key, $route->minimum);
    }
}
