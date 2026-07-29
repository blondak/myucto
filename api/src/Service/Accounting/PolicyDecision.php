<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting;

final class PolicyDecision
{
    public function __construct(
        public readonly string $decision,
        public readonly ?string $note = null,
    ) {}
}
