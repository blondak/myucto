<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Deadline;

final readonly class PayrollLevyDeadlineWindow
{
    public function __construct(
        public string $levy,
        public string $periodStart,
        public ?string $earliestPaymentOn,
        public string $statutoryDueOn,
        public string $dueOn,
        public bool $isShifted,
        public string $calendarBasis,
        public string $rulesetId,
        public string $rulesetHash,
        public string $rule,
        public string $source,
        public string $sourceStatus,
        public string $shiftSource,
        public string $shiftSourceStatus,
    ) {}

    /**
     * @return array{
     *   levy:string,period_start:string,earliest_payment_on:?string,
     *   statutory_due_on:string,due_on:string,is_shifted:bool,
     *   calendar_basis:string,ruleset_id:string,ruleset_hash:string,
     *   rule:string,source:string,source_status:string,
     *   shift_source:string,shift_source_status:string
     * }
     */
    public function toArray(): array
    {
        return [
            'levy' => $this->levy,
            'period_start' => $this->periodStart,
            'earliest_payment_on' => $this->earliestPaymentOn,
            'statutory_due_on' => $this->statutoryDueOn,
            'due_on' => $this->dueOn,
            'is_shifted' => $this->isShifted,
            'calendar_basis' => $this->calendarBasis,
            'ruleset_id' => $this->rulesetId,
            'ruleset_hash' => $this->rulesetHash,
            'rule' => $this->rule,
            'source' => $this->source,
            'source_status' => $this->sourceStatus,
            'shift_source' => $this->shiftSource,
            'shift_source_status' => $this->shiftSourceStatus,
        ];
    }
}
