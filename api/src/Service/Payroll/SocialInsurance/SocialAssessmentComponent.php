<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\SocialInsurance;

use InvalidArgumentException;

final readonly class SocialAssessmentComponent
{
    public function __construct(
        public string $code,
        public int $amountMinorUnits,
        public SocialComponentTreatment $participationTreatment,
        public SocialComponentTreatment $assessmentBaseTreatment,
    ) {
        if (preg_match('/^[a-z0-9][a-z0-9_.-]*$/D', $code) !== 1) {
            throw new InvalidArgumentException(
                'Social insurance component codes must use canonical lowercase identifiers.',
            );
        }
    }
}
