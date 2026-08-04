<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Garnishment;

use MyInvoice\Service\Payroll\Garnishment\PayrollEnforcementStoredResultIntegrity;
use PHPUnit\Framework\TestCase;

final class PayrollEnforcementStoredResultIntegrityTest extends TestCase
{
    public function testAcceptsCompleteStoredResult(): void
    {
        PayrollEnforcementStoredResultIntegrity::assertConsistent(
            totalWithheldMinorUnits: 12_500,
            employerFeeMinorUnits: 500,
            allocatedMinorUnits: 12_000,
            withheldLedgerMinorUnits: 12_000,
            employerFeeLedgerMinorUnits: 500,
            heldLedgerMinorUnits: 8_000,
        );

        self::addToAssertionCount(1);
    }

    public function testRejectsIncompleteStoredResult(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('neodpovídají');

        PayrollEnforcementStoredResultIntegrity::assertConsistent(
            totalWithheldMinorUnits: 12_500,
            employerFeeMinorUnits: 500,
            allocatedMinorUnits: 12_000,
            withheldLedgerMinorUnits: 11_000,
            employerFeeLedgerMinorUnits: 500,
            heldLedgerMinorUnits: 8_000,
        );
    }

    public function testRejectsHeldAmountAboveWithheldAmount(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('deponovaná');

        PayrollEnforcementStoredResultIntegrity::assertConsistent(
            totalWithheldMinorUnits: 12_500,
            employerFeeMinorUnits: 500,
            allocatedMinorUnits: 12_000,
            withheldLedgerMinorUnits: 12_000,
            employerFeeLedgerMinorUnits: 500,
            heldLedgerMinorUnits: 12_001,
        );
    }
}
