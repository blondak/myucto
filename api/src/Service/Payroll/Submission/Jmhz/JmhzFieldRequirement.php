<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz;

final readonly class JmhzFieldRequirement
{
    public function __construct(
        public string $matrixKey,
        public string $attributeId,
        public JmhzFieldRequirementKind $requirement,
        public ?string $conditionNoteRaw,
        public JmhzFieldEffect $effect,
    ) {}
}
