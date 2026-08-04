<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\HealthInsurance;

use InvalidArgumentException;

final readonly class HealthAssessmentComponent
{
    public function __construct(
        public string $code,
        public int $amountMinorUnits,
        public HealthComponentTreatment $participationTreatment,
        public HealthComponentTreatment $assessmentBaseTreatment,
        public HealthCorrectionTreatment $correctionTreatment = HealthCorrectionTreatment::CurrentMonth,
    ) {
        if (preg_match('/^[a-z0-9][a-z0-9_.:-]*$/D', $code) !== 1) {
            throw new InvalidArgumentException('Health insurance component code is not canonical.');
        }
    }
}
