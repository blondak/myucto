<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll;

/**
 * VS ČSSZ mzdové účtárny neprošel kontrolou
 * {@see \MyInvoice\Service\Payroll\Submission\CsszEmployerVariableSymbol::invalidReason()}.
 * Nese účtárnu a pole, aby ho UI ukázalo přímo u vstupu.
 */
final class PayrollOfficeVariableSymbolInvalidException extends \InvalidArgumentException
{
    public function __construct(
        public readonly string $officeCode,
        public readonly string $field,
        public readonly string $reason,
        string $message,
    ) {
        parent::__construct($message);
    }
}
