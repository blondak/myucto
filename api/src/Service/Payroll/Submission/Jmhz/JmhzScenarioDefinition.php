<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz;

final readonly class JmhzScenarioDefinition
{
    public function __construct(
        public string $key,
        public string $selectorRawType,
        public string $selectorRaw,
        public string $name,
        public string $conditionRaw,
        public string $businessDescriptionRaw,
        public string $xsdEntrypoint,
        public JmhzScenarioSelectionKind $selectionKind,
    ) {}
}
