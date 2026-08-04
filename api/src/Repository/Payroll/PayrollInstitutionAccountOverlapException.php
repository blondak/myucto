<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

final class PayrollInstitutionAccountOverlapException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Účet instituce má pro stejnou měnu překrývající se období platnosti.');
    }
}
