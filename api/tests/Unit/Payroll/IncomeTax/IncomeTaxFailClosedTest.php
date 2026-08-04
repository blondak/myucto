<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\IncomeTax;

use InvalidArgumentException;
use MyInvoice\Service\Payroll\IncomeTax\EmploymentRelationshipKind;
use MyInvoice\Service\Payroll\IncomeTax\EmploymentRelationshipTaxInput;
use MyInvoice\Service\Payroll\IncomeTax\IncomeTaxComponent;
use MyInvoice\Service\Payroll\IncomeTax\IncomeTaxComponentTreatment;
use MyInvoice\Service\Payroll\IncomeTax\MonthlyEmploymentIncomeTaxInput;
use MyInvoice\Service\Payroll\IncomeTax\TaxEvidenceStatus;
use MyInvoice\Service\Payroll\IncomeTax\TaxResidence;
use MyInvoice\Service\Payroll\IncomeTax\TaxResidenceEvidence;
use OverflowException;
use PHPUnit\Framework\TestCase;

final class IncomeTaxFailClosedTest extends TestCase
{
    public function testMixedPayerRelationshipsAreRejected(): void
    {
        $first = new EmploymentRelationshipTaxInput(
            relationshipReference: 'first',
            payerReference: 'payer-a',
            kind: EmploymentRelationshipKind::Employment,
            components: [new IncomeTaxComponent('salary', 1_000_000)],
        );
        $second = new EmploymentRelationshipTaxInput(
            relationshipReference: 'second',
            payerReference: 'payer-b',
            kind: EmploymentRelationshipKind::Employment,
            components: [new IncomeTaxComponent('salary', 1_000_000)],
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('input payer');
        new MonthlyEmploymentIncomeTaxInput(
            calculationDate: '2026-08-31',
            employeeReference: 'synthetic-employee',
            relationships: [$first, $second],
            declarations: [],
            residence: new TaxResidenceEvidence(TaxResidence::Unverified),
        );
    }

    public function testUnverifiedExemptionCannotSilentlyReduceTaxBase(): void
    {
        $component = new IncomeTaxComponent(
            code: 'benefit',
            amountMinorUnits: 100_000,
            treatment: IncomeTaxComponentTreatment::Exempt,
        );

        self::assertFalse($component->hasVerifiedTreatmentEvidence('2026-08-31'));
    }

    public function testResidenceEvidenceMustCoverCalculationMonth(): void
    {
        $residence = new TaxResidenceEvidence(
            TaxResidence::CzechResident,
            '2025-01-01',
            '2025-12-31',
            'synthetic-residence-evidence',
        );

        self::assertFalse($residence->isEffective('2026-08-31'));
    }

    public function testIncomeAggregationRejectsIntegerOverflow(): void
    {
        $relationship = new EmploymentRelationshipTaxInput(
            relationshipReference: 'employment',
            payerReference: 'synthetic-payer',
            kind: EmploymentRelationshipKind::Employment,
            components: [
                new IncomeTaxComponent('first', PHP_INT_MAX),
                new IncomeTaxComponent('second', 1),
            ],
        );

        $this->expectException(OverflowException::class);
        $relationship->includedBaseMinorUnits();
    }

    public function testVerifiedExemptionRequiresEffectiveEvidence(): void
    {
        $component = new IncomeTaxComponent(
            code: 'benefit',
            amountMinorUnits: 100_000,
            treatment: IncomeTaxComponentTreatment::Exempt,
            treatmentEvidenceStatus: TaxEvidenceStatus::Verified,
            treatmentEvidenceFrom: '2026-01-01',
            treatmentEvidenceTo: null,
            treatmentEvidenceReference: 'synthetic-exemption-evidence',
        );

        self::assertTrue($component->hasVerifiedTreatmentEvidence('2026-08-31'));
    }
}
