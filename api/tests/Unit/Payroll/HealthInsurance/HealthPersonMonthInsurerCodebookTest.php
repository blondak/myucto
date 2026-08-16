<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\HealthInsurance;

use InvalidArgumentException;
use MyInvoice\Service\Payroll\HealthInsurance\HealthAssessmentComponent;
use MyInvoice\Service\Payroll\HealthInsurance\HealthComponentTreatment;
use MyInvoice\Service\Payroll\HealthInsurance\HealthCorrectionTreatment;
use MyInvoice\Service\Payroll\HealthInsurance\HealthEmploymentKind;
use MyInvoice\Service\Payroll\HealthInsurance\HealthIncomeAttribution;
use MyInvoice\Service\Payroll\HealthInsurance\HealthInsuranceRelationshipInput;
use MyInvoice\Service\Payroll\HealthInsurance\HealthInsurerSnapshotStatus;
use MyInvoice\Service\Payroll\HealthInsurance\HealthJurisdictionEvidence;
use MyInvoice\Service\Payroll\HealthInsurance\HealthMinimumTopUpEmployerSelection;
use MyInvoice\Service\Payroll\HealthInsurance\HealthMinimumTopUpResponsibility;
use MyInvoice\Service\Payroll\HealthInsurance\HealthPersonMonthInput;
use PHPUnit\Framework\TestCase;

/**
 * Ověřený snapshot pojišťovny musí nést kód ze skutečného číselníku — pouhý
 * tvar tří číslic pouštěl neexistující pojišťovnu (999) až do zákonného podání.
 */
final class HealthPersonMonthInsurerCodebookTest extends TestCase
{
    public function testAcceptsInsurerFromTheCodebook(): void
    {
        self::assertSame('207', $this->person('207')->insurerCode);
    }

    public function testRejectsInsurerOutsideTheCodebook(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('111 VZP');
        $this->person('999');
    }

    public function testRejectsInsurerWithWrongShape(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->person('11');
    }

    private function person(string $insurerCode): HealthPersonMonthInput
    {
        return new HealthPersonMonthInput(
            'person-codebook',
            HealthJurisdictionEvidence::CzechRegimeVerified,
            null,
            HealthInsurerSnapshotStatus::Verified,
            $insurerCode,
            'insurer:synthetic-snapshot',
            [new HealthInsuranceRelationshipInput(
                'relationship-1',
                HealthEmploymentKind::Employment,
                '2026-08-01',
                null,
                HealthIncomeAttribution::CurrentEmploymentMonth,
                [new HealthAssessmentComponent(
                    'wage',
                    1_000_000,
                    HealthComponentTreatment::Included,
                    HealthComponentTreatment::Included,
                    HealthCorrectionTreatment::CurrentMonth,
                )],
            )],
            [],
            [],
            HealthMinimumTopUpResponsibility::Employee,
            null,
            null,
            HealthMinimumTopUpEmployerSelection::Unverified,
        );
    }
}
