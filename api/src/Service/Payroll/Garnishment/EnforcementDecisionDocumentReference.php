<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Garnishment;

final readonly class EnforcementDecisionDocumentReference
{
    public function __construct(
        public int $documentId,
        public string $sha256,
    ) {
        if ($documentId <= 0) {
            throw new \InvalidArgumentException('Decision document ID must be positive.');
        }
        if (preg_match('/^[0-9a-f]{64}$/D', $sha256) !== 1) {
            throw new \InvalidArgumentException('Decision document hash is invalid.');
        }
    }
}
