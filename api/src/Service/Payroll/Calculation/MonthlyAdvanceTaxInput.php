<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Calculation;

use InvalidArgumentException;

final readonly class MonthlyAdvanceTaxInput
{
    public function __construct(
        public int $taxableIncomeMinorUnits,
        public bool $signedDeclaration,
        public bool $claimTaxpayerCredit,
        public int $otherNonRefundableCreditsMinorUnits = 0,
        public int $childCreditMinorUnits = 0,
    ) {
        foreach ([
            'taxable income' => $taxableIncomeMinorUnits,
            'other non-refundable credits' => $otherNonRefundableCreditsMinorUnits,
            'child credit' => $childCreditMinorUnits,
        ] as $label => $amount) {
            if ($amount < 0) {
                throw new InvalidArgumentException("Monthly {$label} cannot be negative.");
            }
        }

        if (!$signedDeclaration && (
            $claimTaxpayerCredit
            || $otherNonRefundableCreditsMinorUnits !== 0
            || $childCreditMinorUnits !== 0
        )) {
            throw new InvalidArgumentException(
                'Monthly tax credits require a signed taxpayer declaration.',
            );
        }
    }
}
