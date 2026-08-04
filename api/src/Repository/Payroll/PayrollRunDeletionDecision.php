<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

final readonly class PayrollRunDeletionDecision
{
    private function __construct(
        public bool $canDelete,
        public ?string $blockerCode,
        public ?string $blockerMessage,
        public ?int $createdEventId,
        public ?int $cancelEventId,
        public ?int $cancelCommandId,
        public bool $ownsPeriod,
        public ?int $replacementOwnerRunId,
    ) {}

    public static function allowed(
        int $createdEventId,
        ?int $cancelEventId,
        ?int $cancelCommandId,
        bool $ownsPeriod,
        ?int $replacementOwnerRunId,
    ): self {
        return new self(
            true,
            null,
            null,
            $createdEventId,
            $cancelEventId,
            $cancelCommandId,
            $ownsPeriod,
            $replacementOwnerRunId,
        );
    }

    public static function blocked(string $code, string $message): self
    {
        return new self(
            false,
            $code,
            $message,
            null,
            null,
            null,
            false,
            null,
        );
    }
}
