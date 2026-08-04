<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\IncomeTax;

use InvalidArgumentException;
use MyInvoice\Service\Payroll\IncomeTax\TaxChildClaim;
use MyInvoice\Service\Payroll\IncomeTax\TaxEvidenceStatus;
use PHPUnit\Framework\TestCase;

final class IncomeTaxEvidenceValidationTest extends TestCase
{
    public function testVerifiedEvidenceRequiresReference(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('evidence reference');

        new TaxChildClaim(
            'synthetic-child',
            1,
            false,
            '2026-01-01',
            null,
            TaxEvidenceStatus::Verified,
            true,
            true,
        );
    }

    public function testEvidenceIntervalMustBeOrdered(): void
    {
        $this->expectException(InvalidArgumentException::class);
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
