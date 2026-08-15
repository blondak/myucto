<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

/** Odmítnutá odchylka od zákonné retenční lhůty — volající ji mapuje na 422. */
final class PayrollRetentionPolicyException extends \RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
    ) {
        parent::__construct($message);
    }
}
