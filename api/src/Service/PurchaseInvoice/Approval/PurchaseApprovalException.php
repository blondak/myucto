<?php

declare(strict_types=1);

namespace MyInvoice\Service\PurchaseInvoice\Approval;

/** Odmítnutí operace schvalování s kódem a HTTP stavem pro API. */
final class PurchaseApprovalException extends \RuntimeException
{
    /** @param array<string,mixed> $details */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $httpStatus = 422,
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }
}
