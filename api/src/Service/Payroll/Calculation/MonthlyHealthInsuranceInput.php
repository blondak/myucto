<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Calculation;

use InvalidArgumentException;

final readonly class MonthlyHealthInsuranceInput
{
    public function __construct(
        public int $assessmentBaseMinorUnits,
        public bool $participates,
        public ?int $minimumAssessmentBaseMinorUnits = null,
        public ?HealthMinimumTopUpPayer $minimumTopUpPayer = null,
    ) {
        if ($assessmentBaseMinorUnits < 0) {
            throw new InvalidArgumentException('Health insurance assessment base cannot be negative.');
        }
        if ($minimumAssessmentBaseMinorUnits !== null && $minimumAssessmentBaseMinorUnits < 0) {
            throw new InvalidArgumentException('Health insurance minimum base cannot be negative.');
        }
        if (($minimumAssessmentBaseMinorUnits === null) !== ($minimumTopUpPayer === null)) {
            throw new InvalidArgumentException(
                'Health insurance minimum base and top-up payer must be provided together.',
            );
        }
        if (!$participates && $minimumAssessmentBaseMinorUnits !== null) {
            throw new InvalidArgumentException(
                'Health insurance minimum cannot be applied without insurance participation.',
            );
        }
    }
}
