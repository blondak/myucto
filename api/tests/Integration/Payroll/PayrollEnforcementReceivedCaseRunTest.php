<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Tests\Support\EnforcementRunFixtureTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Doručený, ale nepřevedený exekuční případ (stav `received`) se v běhu
 * nesráží — a běh o tom musel mlčet. Srážet se přitom má ode dne doručení
 * plátci mzdy (§ 282 odst. 3 o. s. ř.).
 */
#[Group('integration')]
final class PayrollEnforcementReceivedCaseRunTest extends TestCase
{
    use EnforcementRunFixtureTrait;

    protected function setUp(): void
    {
        $this->bootEnforcementRun();
    }

    protected function tearDown(): void
    {
        $this->tearDownEnforcementRun();
    }

    public function testReceivedCaseStopsApprovalWithLinkToTheCase(): void
    {
        $caseId = $this->seedRunCase('received');
        $this->seedRunClaim($caseId);

        $run = $this->calculateEnforcementRun();

        self::assertSame(0, $run['enforcement']['total_withheld_minor_units']);
        $received = array_values(array_filter(
            $run['validations'],
            static fn (array $row): bool => $row['code'] === 'enforcement_case_received',
        ));
        self::assertCount(1, $received, 'Běh o nesraženém doručeném případu mlčí.');
        self::assertSame('warning', $received[0]['severity']);
        self::assertTrue((bool) $received[0]['requires_override']);
        self::assertSame(
            "/payroll/enforcement?person={$this->employeeId}&case={$caseId}",
            $received[0]['remediation_path'],
        );
    }

    public function testCaseAlreadyWithheldRaisesNoReceivedWarning(): void
    {
        $caseId = $this->seedRunCase('withhold_and_hold');
        $this->seedRunClaim($caseId);
        $this->seedRunMonthEvidence();

        $run = $this->calculateEnforcementRun();

        self::assertGreaterThan(0, $run['enforcement']['total_withheld_minor_units']);
        self::assertNotContains('enforcement_case_received', $run['validation_codes']);
    }

    public function testReceivedCaseEffectiveAfterPaymentDateIsNotReported(): void
    {
        $caseId = $this->seedRunCase('received', effectiveFrom: '2026-08-01');
        $this->seedRunClaim($caseId, deliveredOn: '2026-08-01');

        $run = $this->calculateEnforcementRun();

        self::assertNotContains('enforcement_case_received', $run['validation_codes']);
    }
}
