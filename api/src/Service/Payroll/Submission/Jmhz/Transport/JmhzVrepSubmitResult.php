<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz\Transport;

final readonly class JmhzVrepSubmitResult
{
    public function __construct(
        public int $httpStatus,
        public string $contentType,
        public string $body,
    ) {}

    public function sha256(): string
    {
        return hash('sha256', $this->body);
    }
}
