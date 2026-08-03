<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Component;

use JsonSerializable;
use MyInvoice\Service\Payroll\Calculation\Money;

final readonly class PayrollComponentImpact implements JsonSerializable
{
    public function __construct(
        public Money $sourceAmount,
        public Money $cashPayable,
        public Money $taxBase,
        public Money $socialBase,
        public Money $healthBase,
        public Money $averageEarningBase,
        public Money $enforcementBase,
        public Money $jmhzAmount,
        public bool $statisticsIncluded,
        public bool $withholdingCandidate,
    ) {}

    /** @return array<string,mixed> */
    public function jsonSerialize(): array
    {
        return [
            'source_amount' => $this->sourceAmount,
            'cash_payable' => $this->cashPayable,
            'tax_base' => $this->taxBase,
            'social_base' => $this->socialBase,
            'health_base' => $this->healthBase,
            'average_earning_base' => $this->averageEarningBase,
            'enforcement_base' => $this->enforcementBase,
            'jmhz_amount' => $this->jmhzAmount,
            'statistics_included' => $this->statisticsIncluded,
            'withholding_candidate' => $this->withholdingCandidate,
        ];
    }
}
