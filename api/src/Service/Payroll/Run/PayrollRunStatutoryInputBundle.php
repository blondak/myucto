<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Run;

use MyInvoice\Service\Payroll\HealthInsurance\HealthInsuranceMonthInput;
use MyInvoice\Service\Payroll\IncomeTax\MonthlyEmploymentIncomeTaxInput;
use MyInvoice\Service\Payroll\SocialInsurance\SocialInsuranceMonthInput;

final readonly class PayrollRunStatutoryInputBundle
{
    /**
     * `$issues` nese VŠECHNY problémy vstupu, globální i osobní. Blokuje-li
     * některý z nich celý výpočet, vrací ho {@see globalIssues()}; osoby
     * vyřazené kvůli vlastním problémům jsou v `$blockedPeople` a v žádném ze
     * tří vstupů nejsou — čistá mzda potřebuje pojistné i daň současně.
     *
     * @param list<MonthlyEmploymentIncomeTaxInput> $incomeTax
     * @param list<PayrollRunStatutoryInputIssue> $issues
     * @param array<int,non-empty-list<PayrollRunStatutoryInputIssue>> $blockedPeople
     */
    public function __construct(
        public ?SocialInsuranceMonthInput $socialInsurance,
        public ?HealthInsuranceMonthInput $healthInsurance,
        public array $incomeTax,
        public array $issues,
        public array $blockedPeople = [],
    ) {
    }

    /** @return list<PayrollRunStatutoryInputIssue> */
    public function globalIssues(): array
    {
        return array_values(array_filter(
            $this->issues,
            static fn (PayrollRunStatutoryInputIssue $issue): bool => $issue->isGlobal(),
        ));
    }
}
