<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz;

final readonly class JmhzInteractionDefinition
{
    public function __construct(
        public string $key,
        public string $conditionRaw,
        public ?string $portalText,
        public ?string $noteRaw,
        public JmhzInteractionTriggerKind $triggerKind,
        public string $rowHash,
    ) {}
}
