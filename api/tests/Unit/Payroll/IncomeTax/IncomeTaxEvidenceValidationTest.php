<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\IncomeTax;

use MyInvoice\Service\Payroll\IncomeTax\TaxChildClaim;
use MyInvoice\Service\Payroll\IncomeTax\TaxEvidenceStatus;
use PHPUnit\Framework\TestCase;

final class IncomeTaxEvidenceValidationTest extends TestCase
{
    public function testVerifiedEvidenceDoesNotRequireReference(): void
    {
        $claim = new TaxChildClaim(
            'synthetic-child',
            1,
            false,
            '2026-01-01',
            null,
            TaxEvidenceStatus::Verified,
            true,
            true,
        );

        self::assertNull($claim->evidenceReference);
    }

    public function testEvidenceIntervalMustBeOrdered(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('effective interval');

        new TaxChildClaim(
            'synthetic-child',
            1,
            false,
            '2026-08-31',
            '2026-01-01',
            TaxEvidenceStatus::Verified,
            true,
            true,
            'synthetic-evidence',
        );
    }
}
