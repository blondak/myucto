<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank\Connector;

final class BankConnectorOperationException extends \RuntimeException
{
    /** @param array<string,mixed> $details */
    public function __construct(
        public readonly string $errorCode,
        public readonly array $details = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($errorCode, 0, $previous);
    }
}
