<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

final class PayrollDimensionInUseException extends \DomainException
{
    public function __construct(string $message = 'Dimenze použitá ve schválené mzdové revizi nejde smazat, jen ukončit její účinnost.')
    {
        parent::__construct($message);
    }
}
