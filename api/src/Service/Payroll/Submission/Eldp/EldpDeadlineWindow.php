<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Eldp;

final readonly class EldpDeadlineWindow
{
    /**
     * @param 'annual'|'termination' $statementKind
     */
    public function __construct(
        public string $earliestSubmissionOn,
        public string $dueOn,
        public string $calendarBasis,
        public string $rulesetId,
        public string $rulesetHash,
        public string $statementKind,
        public string $legalBasis,
    ) {}
}
