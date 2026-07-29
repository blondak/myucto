<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Signing;

use MyInvoice\Service\Signing\SigningProfileAccess;
use PHPUnit\Framework\TestCase;

final class SigningProfileAccessTest extends TestCase
{
    public function testAdminCanManageAllProfiles(): void
    {
        $access = new SigningProfileAccess();

        self::assertTrue($access->canCreate(true, true, false));
        self::assertTrue($access->canManage(true, true, 10, null, false));
        self::assertTrue($access->canManage(true, true, 10, 99, false));
        self::assertTrue($access->canManageSupplierDefaults(true));
    }

    public function testAccountantCanOnlyManageOwnProfilesWhenEnabled(): void
    {
        $access = new SigningProfileAccess();

        self::assertTrue($access->canCreate(false, true, true));
        self::assertTrue($access->canManage(false, true, 10, 10, true));
        self::assertFalse($access->canManage(false, true, 10, 11, true));
        self::assertFalse($access->canManage(false, true, 10, null, true));
        self::assertFalse($access->canManage(false, true, 10, 10, false));
        self::assertFalse($access->canManageSupplierDefaults(false));
    }

    public function testReadonlyCannotMutateProfiles(): void
    {
        $access = new SigningProfileAccess();

        self::assertFalse($access->canCreate(false, false, true));
        self::assertFalse($access->canManage(false, false, 10, 10, true));
        self::assertFalse($access->canManageSupplierDefaults(false));
    }
}
