<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz;

final readonly class JmhzOfficialExampleEvidence
{
    /** @param list<string> $blockingReasons */
    public function __construct(
        public string $key,
        public string $sha256,
        public string $agenda,
        public string $rootLocalName,
        public string $rootNamespace,
        public JmhzOfficialExampleValidationResult $validationResult,
        public JmhzOfficialExampleClassification $classification,
        public string $reasonCode,
        public array $blockingReasons,
    ) {}

    public function isFixtureEligible(): bool
    {
        return false;
    }
}
