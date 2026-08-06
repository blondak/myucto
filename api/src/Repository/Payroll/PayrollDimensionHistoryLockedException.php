<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

final class PayrollDimensionHistoryLockedException extends \DomainException
{
    public function __construct(string $message = 'Dimenze použitá v historii se nepřepisuje — typ, kód ani začátek účinnosti nelze změnit.')
    {
        parent::__construct($message);
    }
}
