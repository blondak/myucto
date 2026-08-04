<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission;

final readonly class PayrollDeadlineAssessment
{
    public function __construct(
        public string $phase,
        public int $daysToDue,
        public bool $isActionRequired,
        public bool $isOverdue,
    ) {}

    /**
     * @return array{
     *   phase:string,days_to_due:int,is_action_required:bool,is_overdue:bool
     * }
     */
    public function toArray(): array
    {
        return [
            'phase' => $this->phase,
            'days_to_due' => $this->daysToDue,
            'is_action_required' => $this->isActionRequired,
            'is_overdue' => $this->isOverdue,
        ];
    }
}
