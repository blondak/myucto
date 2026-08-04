<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Run;

final readonly class PayrollRunInputSnapshot
{
    /**
     * @param array<string,mixed> $data
     * @param list<PayrollRunValidation> $validations
     */
    public function __construct(
        public array $data,
        public string $json,
        public string $hash,
        public string $rulesetManifestHash,
        public array $validations,
    ) {
        if (preg_match('/^[0-9a-f]{64}$/D', $hash) !== 1
            || preg_match('/^[0-9a-f]{64}$/D', $rulesetManifestHash) !== 1
        ) {
            throw new \InvalidArgumentException('Snapshot musí mít SHA-256 otisky.');
        }
    }
}
