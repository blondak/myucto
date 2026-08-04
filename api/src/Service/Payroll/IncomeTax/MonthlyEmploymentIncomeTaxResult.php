<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\IncomeTax;

use JsonSerializable;
use MyInvoice\Service\Payroll\Calculation\MonthlyAdvanceTaxResult;

final readonly class MonthlyEmploymentIncomeTaxResult implements JsonSerializable
{
    /**
     * @param list<RelationshipTaxResult> $relationships
     * @param list<WithholdingTaxGroupResult> $withholdingGroups
     * @param list<string> $issues
     */
    public function __construct(
        public TaxCalculationStatus $status,
        public string $calculationDate,
        public string $employeeReference,
        public string $payerReference,
        public array $relationships,
        public ?MonthlyAdvanceTaxResult $advanceTax,
        public array $withholdingGroups,
        public int $withholdingBaseMinorUnits,
        public int $withholdingTaxMinorUnits,
        public int $claimedNonRefundableCreditsMinorUnits,
        public int $appliedNonRefundableCreditsMinorUnits,
        public int $claimedChildCreditMinorUnits,
        public int $appliedChildCreditMinorUnits,
        public AnnualTaxAccumulatorResult $annualAccumulator,
        public array $issues,
        public string $policyId,
        public string $policyHash,
        public string $rulesetId,
        public string $rulesetHash,
    ) {}

    /** @return array<string,mixed> */
    public function jsonSerialize(): array
    {
        return [
            'status' => $this->status->value,
            'calculation_date' => $this->calculationDate,
            'employee_reference' => $this->employeeReference,
            'payer_reference' => $this->payerReference,
            'relationships' => array_map(
                static fn (RelationshipTaxResult $result): array => $result->jsonSerialize(),
                $this->relationships,
            ),
            'advance_tax' => $this->advanceTax?->jsonSerialize(),
            'withholding_groups' => array_map(
                static fn (WithholdingTaxGroupResult $result): array => $result->jsonSerialize(),
                $this->withholdingGroups,
            ),
            'withholding_base_minor_units' => $this->withholdingBaseMinorUnits,
            'withholding_tax_minor_units' => $this->withholdingTaxMinorUnits,
            'claimed_non_refundable_credits_minor_units' => $this->claimedNonRefundableCreditsMinorUnits,
            'applied_non_refundable_credits_minor_units' => $this->appliedNonRefundableCreditsMinorUnits,
            'claimed_child_credit_minor_units' => $this->claimedChildCreditMinorUnits,
            'applied_child_credit_minor_units' => $this->appliedChildCreditMinorUnits,
            'annual_accumulator' => $this->annualAccumulator->jsonSerialize(),
            'issues' => $this->issues,
            'policy_id' => $this->policyId,
            'policy_hash' => $this->policyHash,
            'ruleset_id' => $this->rulesetId,
            'ruleset_hash' => $this->rulesetHash,
        ];
    }
}
