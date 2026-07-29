<?php

declare(strict_types=1);

namespace MyInvoice\Service\Epo;

final class EpoException extends \RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $httpStatus = 502,
        public readonly ?int $remoteHttpStatus = null,
    ) {
        parent::__construct($message);
    }
}
