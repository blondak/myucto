<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\HealthInsurance;

final readonly class HealthInsurerChannel
{
    public function __construct(
        public string $insurerCode,
        public string $insurerName,
        public HealthInsurerChannelKind $kind,
        public ?string $dataBoxId,
        public ?string $portalUrl,
        public bool $acceptsSharedDataMessage,
        public bool $automatedDispatchDocumented,
        public string $undocumentedReasonCode,
        public string $note,
    ) {}

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'insurer_code' => $this->insurerCode,
            'insurer_name' => $this->insurerName,
            'kind' => $this->kind->value,
            'data_box_id' => $this->dataBoxId,
            'portal_url' => $this->portalUrl,
            'accepts_shared_data_message' => $this->acceptsSharedDataMessage,
            'automated_dispatch_documented' =>
                $this->automatedDispatchDocumented,
            'undocumented_reason_code' => $this->undocumentedReasonCode,
            'note' => $this->note,
        ];
    }
}
