<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Garnishment;

interface PayrollGarnishmentPort
{
    public function calculate(
        EnforcementPersonMonthRequest $request,
    ): PayrollGarnishmentCalculation;
}
