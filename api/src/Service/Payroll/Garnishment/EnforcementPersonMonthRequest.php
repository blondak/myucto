<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Garnishment;

use InvalidArgumentException;

final readonly class EnforcementPersonMonthRequest
{
    /** @param list<GarnishableIncomeItem> $incomeItems */
    public function __construct(
        public int $supplierId,
        public int $employeeId,
        public string $period,
        public string $paymentDate,
        public array $incomeItems,
        public bool $incomeEvidenceComplete,
    ) {
        if ($supplierId <= 0 || $employeeId <= 0) {
            throw new InvalidArgumentException('Supplier and employee IDs must be positive.');
        }
    }
}
