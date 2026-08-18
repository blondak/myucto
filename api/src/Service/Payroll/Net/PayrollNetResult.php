<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Net;

use JsonSerializable;

final readonly class PayrollNetResult implements JsonSerializable
{
    /**
     * @param list<NetRelationshipIncome> $relationships
     * @param list<PayrollDeductionResult> $deductions
     */
    public function __construct(
        public string $personReference,
        public array $relationships,
        public int $cashIncomeMinorUnits,
        public int $nonCashIncomeMinorUnits,
        public int $employeeSocialMinorUnits,
        public int $employeeHealthMinorUnits,
        public int $advanceTaxMinorUnits,
        public int $withholdingTaxMinorUnits,
        public int $taxBonusMinorUnits,
        public int $correctionMinorUnits,
        public int $netBeforeDeductionsMinorUnits,
        public int $deductedMinorUnits,
        public int $netPayableMinorUnits,
        public array $deductions,
        public int $annualSettlementMinorUnits = 0,
    ) {}

    /** @return array<string,mixed> */
    public function jsonSerialize(): array
    {
        return [
            'person_reference' => $this->personReference,
            'relationships' => array_map(
                static fn (NetRelationshipIncome $relationship): array =>
                    $relationship->jsonSerialize(),
                $this->relationships,
            ),
            'cash_income_minor_units' => $this->cashIncomeMinorUnits,
            'non_cash_income_minor_units' => $this->nonCashIncomeMinorUnits,
            'employee_social_minor_units' => $this->employeeSocialMinorUnits,
            'employee_health_minor_units' => $this->employeeHealthMinorUnits,
            'advance_tax_minor_units' => $this->advanceTaxMinorUnits,
            'withholding_tax_minor_units' => $this->withholdingTaxMinorUnits,
            'tax_bonus_minor_units' => $this->taxBonusMinorUnits,
            'correction_minor_units' => $this->correctionMinorUnits,
            'annual_settlement_minor_units' => $this->annualSettlementMinorUnits,
            'net_before_deductions_minor_units' => $this->netBeforeDeductionsMinorUnits,
            'deducted_minor_units' => $this->deductedMinorUnits,
            'net_payable_minor_units' => $this->netPayableMinorUnits,
            'deductions' => array_map(
                static fn (PayrollDeductionResult $deduction): array =>
                    $deduction->jsonSerialize(),
                $this->deductions,
            ),
        ];
    }
}
