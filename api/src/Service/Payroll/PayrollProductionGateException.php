<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll;

final class PayrollProductionGateException extends \DomainException
{
    public const ERROR_CODE = 'payroll_setup_incomplete';

    public function __construct(
        string $message = 'Před ostrým mzdovým provozem dokončete základní nastavení firmy.',
    )
    {
        parent::__construct($message);
    }
}
