<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Security;

use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\EffectiveRole;
use MyInvoice\Security\PermissionCatalog;
use MyInvoice\Security\PermissionChecker;
use PHPUnit\Framework\TestCase;

final class PermissionCatalogTest extends TestCase
{
    public function testDefinitionsAreUniqueAndInternallyValid(): void
    {
        $catalog = new PermissionCatalog();
        $all = $catalog->all();
        self::assertNotEmpty($all);
        self::assertSame(array_keys($all), array_column($all, 'key'));
        foreach ($all as $key => $definition) {
            self::assertContains($definition['group'], $catalog->groups(), $key);
            self::assertNotEmpty($definition['role_types'], $key);
            self::assertEmpty(array_diff($definition['role_types'], ['staff', 'client']), $key);
        }
    }

    public function testCheckerIsFailClosedAndWriteImpliesRead(): void
    {
        $checker = new PermissionChecker(new PermissionCatalog());
        $role = new EffectiveRole(2, 'Účetní', 'staff', true, ['invoices' => 2]);
        self::assertTrue($checker->allows($role, 'invoices', AccessLevel::READ));
        self::assertTrue($checker->allows($role, 'invoices', AccessLevel::WRITE));
        self::assertFalse($checker->allows($role, 'invoices.delete', AccessLevel::READ));
        self::assertFalse($checker->allows($role, 'unknown.permission', AccessLevel::READ));
        self::assertFalse($checker->allows(new EffectiveRole(2, 'Off', 'staff', false, ['invoices' => 2]), 'invoices'));
    }

    public function testClientCannotUseStaffOnlyPermissionEvenWithCorruptMatrixRow(): void
    {
        $checker = new PermissionChecker(new PermissionCatalog());
        $client = new EffectiveRole(4, 'Klient', 'client', true, ['accounting' => 2, 'invoices' => 1]);
        self::assertFalse($checker->allows($client, 'accounting'));
        self::assertTrue($checker->allows($client, 'invoices'));
    }

    public function testDocumentSubmissionPermissionsAreRoleSeparated(): void
    {
        $catalog = new PermissionCatalog();
        $definitions = $catalog->all();
        self::assertSame(['staff'], $definitions['documents.inbox']['role_types']);
        self::assertSame(['client'], $definitions['documents.submit']['role_types']);

        $checker = new PermissionChecker($catalog);
        $client = new EffectiveRole(4, 'Klient', 'client', true, $catalog->legacyPreset('client'));
        $accountant = new EffectiveRole(2, 'Účetní', 'staff', true, $catalog->legacyPreset('accountant'));
        self::assertTrue($checker->allows($client, 'documents.submit', AccessLevel::WRITE));
        self::assertFalse($checker->allows($client, 'documents.inbox'));
        self::assertTrue($checker->allows($accountant, 'documents.inbox', AccessLevel::WRITE));
        self::assertFalse($checker->allows($accountant, 'documents.submit'));
    }

    public function testSuperadminBypassesMatrix(): void
    {
        $checker = new PermissionChecker(new PermissionCatalog());
        $superadmin = new EffectiveRole(1, 'Superadmin', 'superadmin', true, [], 'superadmin');
        self::assertTrue($checker->allows($superadmin, 'invoices.delete', AccessLevel::WRITE));
    }
}
