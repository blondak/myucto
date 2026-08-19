<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\HealthInsurance;

final readonly class HealthNotificationDeadlineWindow
{
    public function __construct(
        public string $earliestSubmissionOn,
        public string $dueOn,
        public string $calendarBasis,
        public string $rulesetId,
        public string $rulesetHash,
        public string $source,
        public string $sourceStatus,
    ) {}

    /** @return array<string,string> */
    public function toArray(): array
    {
        return [
            'earliest_submission_on' => $this->earliestSubmissionOn,
            'due_on' => $this->dueOn,
            'calendar_basis' => $this->calendarBasis,
            'ruleset_id' => $this->rulesetId,
            'source' => $this->source,
            'source_status' => $this->sourceStatus,
        ];
    }
}
