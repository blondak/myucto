<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Garnishment;

final readonly class PayrollGarnishmentCalculation
{
    public function __construct(
        public int $supplierId,
        public int $employeeId,
        public GarnishmentInput $input,
        public GarnishmentResult $result,
    ) {
        if ($supplierId <= 0 || $employeeId <= 0) {
            throw new \InvalidArgumentException(
                'Supplier and employee IDs must be positive.',
            );
        }
        if ($input->period !== $result->period) {
            throw new \InvalidArgumentException(
                'Garnishment result does not match its input.',
            );
        }
    }

    /** @return array<string,mixed> */
    public function inputSnapshot(): array
    {
        return [
            'schema_version' => 'payroll-enforcement-input.v1',
            'supplier_id' => $this->supplierId,
            'employee_id' => $this->employeeId,
            ...$this->input->toCanonicalArray(),
        ];
    }
}
