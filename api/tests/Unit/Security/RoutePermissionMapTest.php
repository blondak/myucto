<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Security;

use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\PermissionCatalog;
use MyInvoice\Security\RoutePermissionMap;
use PHPUnit\Framework\TestCase;

final class RoutePermissionMapTest extends TestCase
{
    public function testSpecificActionsPrecedeModuleFallbacks(): void
    {
        $map = new RoutePermissionMap();
        $cases = [
            ['POST', '/api/invoices/7/issue', 'invoices.issue', AccessLevel::WRITE],
            ['POST', '/api/invoices/7/send', 'invoices.send', AccessLevel::WRITE],
            ['POST', '/api/invoices/bulk-reminder', 'invoices.reminder', AccessLevel::WRITE],
            ['POST', '/api/invoices/bulk-reissue', 'invoices.clone', AccessLevel::WRITE],
            ['DELETE', '/api/invoices/7', 'invoices.delete', AccessLevel::WRITE],
            ['GET', '/api/invoices/7', 'invoices', AccessLevel::READ],
            ['GET', '/api/purchase-invoices/payment-orders/history', 'purchase_invoices.payment_orders', AccessLevel::READ],
            ['POST', '/api/purchase-invoices/scan-inbox', 'purchase_invoices.scan', AccessLevel::WRITE],
            ['DELETE', '/api/purchase-invoices/7/link-advance', 'purchase_invoices', AccessLevel::WRITE],
            ['DELETE', '/api/purchase-invoices/7/advance-suggestion', 'purchase_invoices', AccessLevel::WRITE],
            ['DELETE', '/api/purchase-invoices/7/pdf', 'purchase_invoices', AccessLevel::WRITE],
            ['GET', '/api/clients/7/work-report-link', 'clients.public_links', AccessLevel::READ],
            ['POST', '/api/clients/7/work-report-link/send', 'clients.public_links', AccessLevel::WRITE],
            ['GET', '/api/reports/monthly-export/preview', 'reports.export', AccessLevel::READ],
            ['POST', '/api/reports/monthly-export/start', 'reports.export', AccessLevel::WRITE],
            ['POST', '/api/reports/monthly-export/jobs/7/cancel', 'reports.export', AccessLevel::WRITE],
            ['DELETE', '/api/reports/monthly-export/jobs/7', 'reports.export', AccessLevel::WRITE],
            ['GET', '/api/reports/submissions/settings', 'reports.submit', AccessLevel::WRITE],
            ['GET', '/api/reports/submissions/7/artifacts/9/download', 'reports.export', AccessLevel::READ],
            ['POST', '/api/accounting/periods/4/close', 'accounting.periods.close', AccessLevel::WRITE],
            ['POST', '/api/accounting/assets/4/dispose', 'assets.dispose', AccessLevel::WRITE],
            ['POST', '/api/accounting/cash-documents', 'cash.document.write', AccessLevel::WRITE],
            ['GET', '/api/accounting/bank-accounts', 'accounting', AccessLevel::READ],
            ['PATCH', '/api/accounting/bank-accounts/7', 'accounting', AccessLevel::WRITE],
            ['GET', '/api/accounting/bank-posting-unposted', 'bank.rules', AccessLevel::READ],
            ['GET', '/api/accounting/bank-posting-unposted/count', 'bank.rules', AccessLevel::READ],
            // §DM — pravidla klasifikace výdaje spadají pod fallback `accounting`; role
            // „client" ho nemá, takže na ně (včetně GETů bez self-checku v Action) nedosáhne.
            ['GET', '/api/accounting/expense-rules', 'accounting', AccessLevel::READ],
            ['POST', '/api/accounting/expense-rules', 'accounting', AccessLevel::WRITE],
            ['PUT', '/api/accounting/expense-rules/7', 'accounting', AccessLevel::WRITE],
            ['DELETE', '/api/accounting/expense-rules/7', 'accounting', AccessLevel::WRITE],
            ['GET', '/api/accounting/purchase-invoices/7/expense-suggestions', 'accounting', AccessLevel::READ],
            ['POST', '/api/stock/takes/4/close', 'stock.close', AccessLevel::WRITE],
            ['GET', '/api/settings/currencies', 'settings.bank_accounts', AccessLevel::READ],
            ['POST', '/api/settings/currencies', 'settings.bank_accounts', AccessLevel::WRITE],
            ['GET', '/api/settings/email-branding/preview', 'settings.branding', AccessLevel::READ],
            ['POST', '/api/settings/email-branding/logo', 'settings.branding', AccessLevel::WRITE],
            ['GET', '/api/settings/accounting-activation/status', 'settings.company', AccessLevel::READ],
            ['POST', '/api/settings/accounting-activation/start', 'accounting.periods.manage', AccessLevel::WRITE],
            ['GET', '/api/price-list-items', 'invoices', AccessLevel::READ],
            ['GET', '/api/price-list-items/7/resolve', 'invoices', AccessLevel::READ],
            ['GET', '/api/bank-statements/7/match-suggestions', 'bank', AccessLevel::READ],
            ['POST', '/api/bank-match-suggestions/7/accept', 'bank.match', AccessLevel::WRITE],
            ['POST', '/api/bank-match-suggestions/7/reject', 'bank.match', AccessLevel::WRITE],
            ['GET', '/api/payroll/capabilities', 'payroll', AccessLevel::READ],
            ['GET', '/api/payroll/people', 'payroll', AccessLevel::READ],
            ['GET', '/api/payroll/people/42', 'payroll', AccessLevel::READ],
            ['GET', '/api/payroll/settings/activation', 'payroll.settings', AccessLevel::READ],
            ['PUT', '/api/payroll/settings/activation', 'payroll.settings', AccessLevel::WRITE],
        ];
        foreach ($cases as [$method, $path, $key, $level]) {
            $match = $map->match($method, $path);
            self::assertNotNull($match, "$method $path");
            self::assertSame($key, $match->key, "$method $path");
            self::assertSame($level, $match->minimum, "$method $path");
        }
    }

    public function testEveryMappedPermissionExistsInCatalog(): void
    {
        $catalog = new PermissionCatalog();
        $map = new RoutePermissionMap();
        foreach ([
            ['GET', '/api/dashboard/summary'], ['GET', '/api/clients'], ['POST', '/api/projects'],
            ['GET', '/api/documents'], ['PUT', '/api/settings/supplier'], ['GET', '/api/tax-return/dpfo/2026'],
            ['POST', '/api/stock/takes'], ['DELETE', '/api/logbook/trips/2'], ['POST', '/api/eshop/categories'],
        ] as [$method, $path]) {
            $match = $map->match($method, $path);
            self::assertNotNull($match, "$method $path");
            self::assertTrue($catalog->has((string) $match->key), (string) $match->key);
        }
    }

    public function testUnknownProtectedRouteIsNotMatched(): void
    {
        self::assertNull((new RoutePermissionMap())->match('GET', '/api/future-dangerous-feature'));
    }

    public function testAdminAndSelfServiceAreFixedClasses(): void
    {
        $map = new RoutePermissionMap();
        self::assertSame(RoutePermissionMap::SUPERADMIN, $map->match('GET', '/api/admin/users')?->kind);
        self::assertSame(RoutePermissionMap::SUPERADMIN, $map->match('POST', '/api/price-list-items')?->kind);
        self::assertSame(RoutePermissionMap::SUPERADMIN, $map->match('DELETE', '/api/price-list-items/7')?->kind);
        self::assertSame(RoutePermissionMap::SELF_SERVICE, $map->match('GET', '/api/auth/me')?->kind);
        self::assertSame(RoutePermissionMap::PUBLIC, $map->match('POST', '/api/auth/login')?->kind);
    }
}
