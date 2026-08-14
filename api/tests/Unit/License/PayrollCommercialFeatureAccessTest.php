<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\License;

use MyInvoice\Service\License\CommercialFeatureAccess;
use PHPUnit\Framework\TestCase;

final class PayrollCommercialFeatureAccessTest extends TestCase
{
    /**
     * Mzdy jsou komerční modul — po vypršení trialu se nesmí obsloužit ani
     * capabilities nebo nastavení, jinak by frontend dál nabízel sekci, ze
     * které každý další požadavek skončí na 403.
     */
    public function testPayrollApiRequiresCommercialLicence(): void
    {
        self::assertTrue(CommercialFeatureAccess::restrictsApiPath('/api/payroll/capabilities'));
        self::assertTrue(CommercialFeatureAccess::restrictsApiPath('/api/payroll/settings/activation'));
        self::assertTrue(CommercialFeatureAccess::restrictsApiPath('/api/payroll/settings/account-options'));
        self::assertTrue(CommercialFeatureAccess::restrictsApiPath('/api/accounting/payroll/preview'));
    }

    /** Prefix se nesmí přelít na cizí cesty (např. budoucí /api/payrolls-export). */
    public function testUnrelatedPathsStayFree(): void
    {
        self::assertFalse(CommercialFeatureAccess::restrictsApiPath('/api/payrollish'));
        self::assertFalse(CommercialFeatureAccess::restrictsApiPath('/api/invoices'));
    }
}
