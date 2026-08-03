<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Garnishment;

interface EnforcementCaseSource
{
    public function evidenceFor(
        int $supplierId,
        int $employeeId,
        string $period,
        string $paymentDate,
    ): EnforcementPersonMonthEvidence;
}
