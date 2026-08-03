<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\SocialInsurance;

use JsonSerializable;

final readonly class SocialParticipationDecision implements JsonSerializable
{
    /** @param non-empty-list<string> $reasonCodes */
    public function __construct(
        public string $relationshipId,
        public SocialParticipationStatus $status,
        public int $participationIncomeMinorUnits,
        public int $groupIncomeMinorUnits,
        public ?int $thresholdMinorUnits,
        public array $reasonCodes,
    ) {}

    /** @return array<string,mixed> */
    public function jsonSerialize(): array
    {
        return [
            'relationship_id' => $this->relationshipId,
            'status' => $this->status->value,
            'participation_income_minor_units' => $this->participationIncomeMinorUnits,
            'group_income_minor_units' => $this->groupIncomeMinorUnits,
            'threshold_minor_units' => $this->thresholdMinorUnits,
            'reason_codes' => $this->reasonCodes,
        ];
    }
}
