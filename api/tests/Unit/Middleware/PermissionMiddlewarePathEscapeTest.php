<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Middleware;

use MyInvoice\Http\RequestPath;
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

/**
 * R1 — permission downgrade přes percent-encoding.
 *
 * PermissionMiddleware běží PŘED RoutingMiddleware a matchoval syrovou cestu,
 * kdežto router si před dispatchem volá rawurldecode(). Jeden `%XX` tedy stačil,
 * aby ACL minula konkrétní pravidlo a spadla na hrubší modulový catch-all —
 * router přitom doručil tutéž Action.
 *
 * Zrcadlo RateLimitDbFallbackTest::testPercentEncodedForgotPathIsLimited.
 */
final class PermissionMiddlewarePathEscapeTest extends TestCase
{
    /** `/api/purchase-invoices/%70ayment-orders` nesmí spadnout na modulové 'purchase_invoices'. */
    public function testEscapedPaymentOrdersPathKeepsSpecificPermission(): void
    {
        self::assertSame(
            'purchase_invoices.payment_orders',
            $this->keyFor('POST', '/api/purchase-invoices/%70ayment-orders'),
        );

        // Role s pouhým modulovým klíčem (na ten se escapovaná cesta degradovala) → 403.
        $module = new EffectiveRole(2, 'Faktury', 'staff', true, ['purchase_invoices' => 2]);
        self::assertSame(403, $this->statusFor($module, 'POST', '/api/purchase-invoices/%70ayment-orders'));
        self::assertSame(403, $this->statusFor($module, 'POST', '/api/purchase-invoices/payment-orders'));

        // Role s konkrétním klíčem projde na obou tvarech stejně.
        $specific = new EffectiveRole(3, 'Příkazy', 'staff', true, ['purchase_invoices.payment_orders' => 2]);
        self::assertSame(204, $this->statusFor($specific, 'POST', '/api/purchase-invoices/payment-orders'));
        self::assertSame(204, $this->statusFor($specific, 'POST', '/api/purchase-invoices/%70ayment-orders'));
    }

    /** `/api/invoices/%31/book` nesmí spadnout na modulové 'invoices'. */
    public function testEscapedBookPathKeepsJournalPostPermission(): void
    {
        self::assertSame('accounting.journal.post', $this->keyFor('POST', '/api/invoices/%31/book'));

        $module = new EffectiveRole(2, 'Faktury', 'staff', true, ['invoices' => 2]);
        self::assertSame(403, $this->statusFor($module, 'POST', '/api/invoices/%31/book'));
        self::assertSame(403, $this->statusFor($module, 'POST', '/api/invoices/1/book'));

        $poster = new EffectiveRole(3, 'Účetní', 'staff', true, ['accounting.journal.post' => 2]);
        self::assertSame(204, $this->statusFor($poster, 'POST', '/api/invoices/1/book'));
        self::assertSame(204, $this->statusFor($poster, 'POST', '/api/invoices/%31/book'));
    }

    /** Escapovaná admin cesta musí zůstat superadmin-only, ne spadnout na modulové právo. */
    public function testEscapedAdminPathStillRequiresSuperadmin(): void
    {
        $staff = new EffectiveRole(2, 'Staff', 'staff', true, ['profile' => 2, 'settings.company.write' => 2]);
        self::assertSame(403, $this->statusFor($staff, 'GET', '/api/%61dmin/users', new SupplierAccess(0, true, null)));

        $superadmin = new EffectiveRole(1, 'Superadmin', 'superadmin', true, [], 'superadmin');
        self::assertSame(204, $this->statusFor($superadmin, 'GET', '/api/%61dmin/users', new SupplierAccess(0, false, null)));
    }

    /** Klíč, na který ACL cestu namapuje po normalizaci (tj. to, co vidí middleware). */
    private function keyFor(string $method, string $path): ?string
    {
        return (new RoutePermissionMap())->match($method, RequestPath::normalize($path))?->key;
    }

    private function statusFor(EffectiveRole $role, string $method, string $path, ?SupplierAccess $access = null): int
    {
        $roles = $this->createStub(PermissionResolver::class);
        $roles->method('resolve')->willReturn($role);
        $suppliers = $this->createStub(SupplierAccessResolver::class);
        $suppliers->method('resolve')->willReturn($access ?? new SupplierAccess(1, false, null));

        $middleware = new PermissionMiddleware(
            new ResponseFactory(),
            new RoutePermissionMap(),
            $roles,
            new PermissionChecker(new PermissionCatalog()),
            $suppliers,
        );

        $request = (new ServerRequestFactory())->createServerRequest($method, 'http://localhost' . $path)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => 5, 'role_id' => 2]);

        return $middleware->process($request, $this->handler())->getStatusCode();
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
