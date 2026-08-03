<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Garnishment;

use InvalidArgumentException;

final readonly class EnforcementTransitionContext
{
    public function __construct(
        public bool $evidenceComplete,
        public bool $recipientVerified,
        public int $outstandingMinorUnits,
        public bool $decisionVerified,
        public ?string $reason,
    ) {
        if ($outstandingMinorUnits < 0) {
            throw new InvalidArgumentException('Outstanding enforcement balance cannot be negative.');
        }
    }
}
