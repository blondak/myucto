<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\HealthInsurance;

use JsonSerializable;

final readonly class HealthParticipationDecision implements JsonSerializable
{
    /** @param non-empty-list<string> $reasonCodes */
    public function __construct(
        public string $relationshipId,
        public HealthParticipationStatus $status,
        public int $relationshipIncomeMinorUnits,
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
            'relationship_income_minor_units' => $this->relationshipIncomeMinorUnits,
            'group_income_minor_units' => $this->groupIncomeMinorUnits,
            'threshold_minor_units' => $this->thresholdMinorUnits,
            'reason_codes' => $this->reasonCodes,
        ];
    }
}
