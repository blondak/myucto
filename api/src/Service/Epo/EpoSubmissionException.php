<?php

declare(strict_types=1);

namespace MyInvoice\Service\Epo;

final class EpoSubmissionException extends \RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $httpStatus,
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }
}
