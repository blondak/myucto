<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

final class PayrollTimeLockedException extends \RuntimeException
{
    public function __construct(
        string $message = 'Měsíc je schválený. Nejdříve jej auditovaně znovu otevřete.',
    )
    {
        parent::__construct($message);
    }
}
