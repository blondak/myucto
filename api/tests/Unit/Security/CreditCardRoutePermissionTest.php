<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Security;

use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RoutePermissionMap;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Oprávnění stránky Kreditní karty: čtení jako banka, načtení výpisu jako import banky,
 * účtování (nastavení, analytika 231, převod účtu) jako zápis bankovního účtování,
 * evidence úvěrového účtu jako nastavení bankovních účtů.
 */
final class CreditCardRoutePermissionTest extends TestCase
{
    #[DataProvider('routes')]
    public function testCreditCardRoutesUseExpectedPermission(string $method, string $path, string $key, AccessLevel $level): void
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
        yield 'přehled' => ['GET', '/api/credit-cards', 'bank', AccessLevel::READ];
        yield 'detail' => ['GET', '/api/credit-cards/7', 'bank', AccessLevel::READ];
        yield 'nastavení čtení' => ['GET', '/api/credit-cards/settings', 'bank', AccessLevel::READ];
        yield 'import výpisu' => ['POST', '/api/credit-cards/import', 'bank.import', AccessLevel::WRITE];
        yield 'převod účtu' => ['POST', '/api/credit-cards/convert', 'bank.post', AccessLevel::WRITE];
        yield 'nastavení zápis' => ['PUT', '/api/credit-cards/settings', 'bank.post', AccessLevel::WRITE];
        yield 'analytika' => ['PUT', '/api/credit-cards/7/analytic', 'bank.post', AccessLevel::WRITE];
        yield 'úprava účtu' => ['PUT', '/api/credit-cards/7', 'settings.bank_accounts', AccessLevel::WRITE];
        yield 'archivace' => ['POST', '/api/credit-cards/7/archive', 'settings.bank_accounts', AccessLevel::WRITE];
    }
}
