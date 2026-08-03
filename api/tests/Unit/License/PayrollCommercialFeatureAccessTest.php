<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\License;

use MyInvoice\Service\License\CommercialFeatureAccess;
use PHPUnit\Framework\TestCase;

final class PayrollCommercialFeatureAccessTest extends TestCase
{
    public function testPayrollApiIsNotCommerciallyRestrictedYet(): void
    {
        self::assertFalse(CommercialFeatureAccess::restrictsApiPath('/api/payroll/capabilities'));
        self::assertFalse(CommercialFeatureAccess::restrictsApiPath('/api/payroll/settings/activation'));
        self::assertTrue(CommercialFeatureAccess::restrictsApiPath('/api/accounting/payroll/preview'));
    }
}
