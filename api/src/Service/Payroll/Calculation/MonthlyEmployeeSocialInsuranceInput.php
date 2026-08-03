<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Calculation;

use InvalidArgumentException;

final readonly class MonthlyEmployeeSocialInsuranceInput
{
    public function __construct(
        public int $assessmentBaseMinorUnits,
        public int $yearToDateAssessmentBaseBeforeMonthMinorUnits,
        public bool $participates,
        public bool $workingPensionerDiscount,
    ) {
        if ($assessmentBaseMinorUnits < 0 || $yearToDateAssessmentBaseBeforeMonthMinorUnits < 0) {
            throw new InvalidArgumentException('Social insurance assessment bases cannot be negative.');
        }
        if (!$participates && $workingPensionerDiscount) {
            throw new InvalidArgumentException(
                'A working pensioner discount requires social insurance participation.',
            );
        }
    }
}
