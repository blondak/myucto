<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\HealthInsurance;

use MyInvoice\Service\Payroll\Calculation\HealthMinimumTopUpPayer;

final readonly class HealthMinimumAssessment
{
    /**
     * @param list<array{from:string,to:string,reason:string,evidence_reference:?string}> $reductionEvidence
     * @param list<string> $issues
     */
    public function __construct(
        public int $calendarDaysInMonth,
        public int $employmentCalendarDays,
        public int $excludedCalendarDays,
        public int $minimumApplicableCalendarDays,
        public int $statutoryMonthlyMinimumMinorUnits,
        public int $effectiveMinimumMinorUnits,
        public int $otherEmployerAssessmentBaseMinorUnits,
        public int $combinedAssessmentBaseMinorUnits,
        public int $minimumForThisEmployerMinorUnits,
        public ?HealthMinimumTopUpPayer $topUpPayer,
        public array $reductionEvidence,
        public array $issues,
    ) {}
}
