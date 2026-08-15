<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

/** Odmítnutý krok návrhu výmazu — volající mapuje `not_found` na 404, ostatní na 409. */
final class PayrollErasureException extends \RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
    ) {
        parent::__construct($message);
    }
}
