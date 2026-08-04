<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Run;

use MyInvoice\Service\Payroll\HealthInsurance\HealthInsuranceMonthInput;
use MyInvoice\Service\Payroll\IncomeTax\MonthlyEmploymentIncomeTaxInput;
use MyInvoice\Service\Payroll\SocialInsurance\SocialInsuranceMonthInput;

final readonly class PayrollRunStatutoryInputBundle
{
    /**
     * @param list<MonthlyEmploymentIncomeTaxInput> $incomeTax
     * @param list<PayrollRunStatutoryInputIssue> $issues
     */
    public function __construct(
        public ?SocialInsuranceMonthInput $socialInsurance,
        public ?HealthInsuranceMonthInput $healthInsurance,
        public array $incomeTax,
        public array $issues,
    ) {
    }
}
