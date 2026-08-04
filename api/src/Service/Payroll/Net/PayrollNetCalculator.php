<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Net;

use MyInvoice\Service\Payroll\Calculation\Money;

final class PayrollNetCalculator
{
    public function __construct(
        private readonly DeductionPriorityResolver $deductionResolver =
            new DeductionPriorityResolver(),
    ) {}

    public function calculate(PayrollNetInput $input): PayrollNetResult
    {
        $cash = new Money(0);
        $nonCash = new Money(0);
        foreach ($input->relationships as $relationship) {
            $cash = $cash->add(new Money($relationship->cashIncomeMinorUnits));
            $nonCash = $nonCash->add(new Money($relationship->nonCashIncomeMinorUnits));
        }
        $netBeforeDeductions = $cash
            ->add(new Money($input->correctionMinorUnits))
            ->subtract(new Money($input->employeeSocialMinorUnits))
            ->subtract(new Money($input->employeeHealthMinorUnits))
            ->subtract(new Money($input->advanceTaxMinorUnits))
            ->subtract(new Money($input->withholdingTaxMinorUnits))
            ->add(new Money($input->taxBonusMinorUnits));
        $deductions = $this->deductionResolver->resolve(
            $input->deductions,
            $input->voluntaryDeductionCapacityMinorUnits,
        );
        $deducted = new Money(0);
        foreach ($deductions as $deduction) {
            $deducted = $deducted->add(new Money($deduction->appliedMinorUnits));
        }
        $netPayable = $netBeforeDeductions->subtract($deducted);

        return new PayrollNetResult(
            $input->personReference,
            $input->relationships,
            $cash->minorUnits,
            $nonCash->minorUnits,
            $input->employeeSocialMinorUnits,
            $input->employeeHealthMinorUnits,
            $input->advanceTaxMinorUnits,
            $input->withholdingTaxMinorUnits,
            $input->taxBonusMinorUnits,
            $input->correctionMinorUnits,
            $netBeforeDeductions->minorUnits,
            $deducted->minorUnits,
            $netPayable->minorUnits,
            $deductions,
        );
    }
}
