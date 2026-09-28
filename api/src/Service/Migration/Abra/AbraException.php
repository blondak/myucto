<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Abra;

final class AbraException extends \RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly array $context = [],
        public readonly int $httpStatus = 422,
    ) {
        parent::__construct($message);
    }
}
