<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Calculation;

use InvalidArgumentException;

final readonly class MonthlyEmployerSocialInsuranceEmployeeInput
{
    public function __construct(
        public int $assessmentBaseMinorUnits,
        public int $yearToDateAssessmentBaseBeforeMonthMinorUnits,
        public bool $participates,
        public bool $partTimeDiscountEligible = false,
    ) {
        if ($assessmentBaseMinorUnits < 0 || $yearToDateAssessmentBaseBeforeMonthMinorUnits < 0) {
            throw new InvalidArgumentException(
                'Employer social insurance assessment bases cannot be negative.',
            );
        }
        if (!$participates && $partTimeDiscountEligible) {
            throw new InvalidArgumentException(
                'A part-time discount requires social insurance participation.',
            );
        }
    }
}
