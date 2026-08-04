<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\IncomeTax;

use JsonSerializable;

final readonly class AnnualTaxAccumulatorResult implements JsonSerializable
{
    /**
     * @param list<ExternalEmployerTaxCertificate> $externalCertificates
     */
    public function __construct(
        public int $year,
        public int $completedMonths,
        public int $advanceBaseMinorUnits,
        public int $withholdingBaseMinorUnits,
        public int $advanceTaxMinorUnits,
        public int $withholdingTaxMinorUnits,
        public int $appliedNonRefundableCreditsMinorUnits,
        public int $appliedChildCreditMinorUnits,
        public int $taxBonusMinorUnits,
        public int $bonusQualifyingIncomeMinorUnits,
        public bool $annualBonusIncomeThresholdMet,
        public array $externalCertificates,
        public bool $externalCertificatesIncluded,
        public bool $annualSettlementReady,
    ) {}

    /** @return array<string,mixed> */
    public function jsonSerialize(): array
    {
        return [
            'year' => $this->year,
            'completed_months' => $this->completedMonths,
            'advance_base_minor_units' => $this->advanceBaseMinorUnits,
            'withholding_base_minor_units' => $this->withholdingBaseMinorUnits,
            'advance_tax_minor_units' => $this->advanceTaxMinorUnits,
            'withholding_tax_minor_units' => $this->withholdingTaxMinorUnits,
            'applied_non_refundable_credits_minor_units' => $this->appliedNonRefundableCreditsMinorUnits,
            'applied_child_credit_minor_units' => $this->appliedChildCreditMinorUnits,
            'tax_bonus_minor_units' => $this->taxBonusMinorUnits,
            'bonus_qualifying_income_minor_units' => $this->bonusQualifyingIncomeMinorUnits,
            'annual_bonus_income_threshold_met' => $this->annualBonusIncomeThresholdMet,
            'external_certificates' => array_map(
                static fn (ExternalEmployerTaxCertificate $certificate): array => $certificate->jsonSerialize(),
                $this->externalCertificates,
            ),
            'external_certificates_included' => $this->externalCertificatesIncluded,
            'annual_settlement_ready' => $this->annualSettlementReady,
        ];
    }
}
