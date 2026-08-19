<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Net;

use MyInvoice\Service\Payroll\HealthInsurance\HealthCalculationStatus;
use MyInvoice\Service\Payroll\HealthInsurance\HealthPersonMonthResult;
use MyInvoice\Service\Payroll\IncomeTax\MonthlyEmploymentIncomeTaxResult;
use MyInvoice\Service\Payroll\IncomeTax\TaxCalculationStatus;
use MyInvoice\Service\Payroll\SocialInsurance\SocialCalculationStatus;
use MyInvoice\Service\Payroll\SocialInsurance\SocialPersonMonthResult;

final class PayrollNetInputAssembler
{
    /**
     * @param list<NetRelationshipIncome> $relationships
     * @param list<PayrollDeductionRequest> $deductions
     */
    public function assemble(
        string $personReference,
        array $relationships,
        SocialPersonMonthResult $social,
        HealthPersonMonthResult $health,
        MonthlyEmploymentIncomeTaxResult $tax,
        int $correctionMinorUnits,
        int $voluntaryDeductionCapacityMinorUnits,
        array $deductions,
        int $annualSettlementMinorUnits = 0,
    ): PayrollNetInput {
        if ($social->personId !== $personReference
            || $health->personId !== $personReference
            || $tax->employeeReference !== $personReference
        ) {
            throw new \DomainException(
                'Výsledky pojistného a daně musí patřit stejné osobě.',
            );
        }
        if ($social->status !== SocialCalculationStatus::Calculated
            || $health->status !== HealthCalculationStatus::Calculated
            || $tax->status !== TaxCalculationStatus::Calculated
        ) {
            throw new \DomainException(
                'Čistou mzdu nelze sestavit z výsledku vyžadujícího ruční kontrolu.',
            );
        }
        if ($social->employeeContributionMinorUnits === null
            || $health->employeeContributionMinorUnits === null
        ) {
            throw new \DomainException('Výsledek pojistného nemá vypočtený odvod zaměstnance.');
        }
        $advanceTaxMinorUnits = $tax->advanceTax === null
            ? 0
            : $tax->advanceTax->taxAfterCreditsMinorUnits;
        $taxBonusMinorUnits = $tax->advanceTax === null
            ? 0
            : $tax->advanceTax->taxBonusMinorUnits;

        return new PayrollNetInput(
            $personReference,
            $relationships,
            $social->employeeContributionMinorUnits,
            $health->employeeContributionMinorUnits,
            $advanceTaxMinorUnits,
            $tax->withholdingTaxMinorUnits,
            $taxBonusMinorUnits,
            $correctionMinorUnits,
            $voluntaryDeductionCapacityMinorUnits,
            $deductions,
            $annualSettlementMinorUnits,
        );
    }
}
