<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Security;

use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RoutePermissionMap;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Oprávnění stránky Shoptet: čtení e-shop, zakládání objednávek právo k prodejním
 * objednávkám, import dokladů právo zakládat faktury. Veřejný je jen feed.
 */
final class ShoptetRoutePermissionTest extends TestCase
{
    #[DataProvider('routes')]
    public function testShoptetRoutesUseExpectedPermission(string $method, string $path, string $key, AccessLevel $level): void
    {
        $match = (new RoutePermissionMap())->match($method, $path);

        self::assertNotNull($match, "$method $path");
        self::assertSame(RoutePermissionMap::PERMISSION, $match->kind, "$method $path");
        self::assertSame($key, $match->key, "$method $path");
        self::assertSame($level, $match->minimum, "$method $path");
    }

    /** @return iterable<string,array{string,string,string,AccessLevel}> */
    public static function routes(): iterable
    {
        yield 'nastavení čtení' => ['GET', '/api/eshop/shoptet/settings', 'eshop', AccessLevel::READ];
        yield 'dávky čtení' => ['GET', '/api/eshop/shoptet/batches', 'eshop', AccessLevel::READ];
        yield 'stažení feedu' => ['GET', '/api/eshop/shoptet/feed/download', 'eshop', AccessLevel::READ];
        yield 'nastavení zápis' => ['PUT', '/api/eshop/shoptet/settings', 'eshop.write', AccessLevel::WRITE];
        yield 'odkaz' => ['PUT', '/api/eshop/shoptet/order-url', 'eshop.write', AccessLevel::WRITE];
        yield 'token feedu' => ['POST', '/api/eshop/shoptet/feed/token', 'eshop.write', AccessLevel::WRITE];
        yield 'náhled objednávek' => ['POST', '/api/eshop/shoptet/orders/preview', 'stock.orders.write', AccessLevel::WRITE];
        yield 'zápis objednávek' => ['POST', '/api/eshop/shoptet/orders/batches/5/apply', 'stock.orders.write', AccessLevel::WRITE];
        yield 'import dokladů' => ['POST', '/api/eshop/shoptet/documents/import', 'invoices.create', AccessLevel::WRITE];
    }

    public function testFeedIsPublicOnlyUnderPublicPrefix(): void
    {
        $map = new RoutePermissionMap();

        self::assertSame(RoutePermissionMap::PUBLIC, $map->match('GET', '/api/public/shoptet/feed/' . str_repeat('a', 64))?->kind);
        self::assertNotSame(RoutePermissionMap::PUBLIC, $map->match('GET', '/api/eshop/shoptet/feed/download')?->kind);
    }
}
