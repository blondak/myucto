<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Calculation;

use InvalidArgumentException;

final readonly class MonthlyEmployerSocialInsuranceInput
{
    /** @var list<MonthlyEmployerSocialInsuranceEmployeeInput> */
    public array $employees;

    /**
     * @param array<mixed> $employees
     */
    public function __construct(array $employees)
    {
        if (!array_is_list($employees)) {
            throw new InvalidArgumentException('Employer social insurance employees must be a list.');
        }
        foreach ($employees as $employee) {
            if (!$employee instanceof MonthlyEmployerSocialInsuranceEmployeeInput) {
                throw new InvalidArgumentException(
                    'Employer social insurance employees must use the dedicated input type.',
                );
            }
        }
        $this->employees = $employees;
    }
}
