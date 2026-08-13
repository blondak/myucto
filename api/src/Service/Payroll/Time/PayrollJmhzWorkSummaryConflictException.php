<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Time;

final class PayrollJmhzWorkSummaryConflictException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct(
            'Zdrojový náhled pracovního souhrnu se změnil; načtěte měsíc znovu.',
        );
    }
}
