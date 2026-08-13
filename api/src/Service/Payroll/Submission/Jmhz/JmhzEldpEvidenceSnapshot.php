<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz;

use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;

final readonly class JmhzEldpEvidenceSnapshot
{
    public const SCHEMA_REFERENCE = 'payroll-jmhz-eldp-evidence.v1';

    /** @param array<string,mixed> $payload */
    public function __construct(public array $payload) {}

    public function canonicalJson(): string
    {
        return CanonicalJson::encode($this->payload);
    }
}
