<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Ozuspoj;

final readonly class OzuspojEligibilityVerdict
{
    public function __construct(
        public OzuspojEligibilityOutcome $outcome,
        public string $message,
        public string $intentDeadlineOn,
        public string $claimDeadlineOn,
        public bool $transitionalQ12026,
    ) {}

    public function allowsDiscount(): bool
    {
        return $this->outcome->allowsDiscount();
    }

    /**
     * @return array{
     *   outcome:string,message:string,intent_deadline_on:string,
     *   claim_deadline_on:string,transitional_q1_2026:bool
     * }
     */
    public function toArray(): array
    {
        return [
            'outcome' => $this->outcome->value,
            'message' => $this->message,
            'intent_deadline_on' => $this->intentDeadlineOn,
            'claim_deadline_on' => $this->claimDeadlineOn,
            'transitional_q1_2026' => $this->transitionalQ12026,
        ];
    }
}
