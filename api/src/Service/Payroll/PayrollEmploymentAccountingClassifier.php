<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll;

final class PayrollEmploymentAccountingClassifier
{
    /**
     * @param array<string,mixed>|null $configuredAccounts
     * @return array{
     *   gross_debit:string,
     *   gross_credit:string,
     *   employer_insurance_debit:string,
     *   employer_insurance_credit:string
     * }
     */
    public function __invoke(
        string $relationType,
        ?array $configuredAccounts = null,
    ): array
    {
        return PayrollAccountingDefaults::forRelation(
            $relationType,
            $configuredAccounts,
        );
    }
}
