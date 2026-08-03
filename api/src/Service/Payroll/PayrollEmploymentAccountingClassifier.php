<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll;

final class PayrollEmploymentAccountingClassifier
{
    /** @return array{gross_debit:string,gross_credit:string,employer_insurance_debit:string,employer_insurance_credit:string} */
    public function __invoke(string $relationType): array
    {
        [$grossDebit, $grossCredit] = match ($relationType) {
            'employment', 'dpp', 'dpc' => ['521', '331'],
            'partner_dependent' => ['522', '366'],
            'statutory_body' => ['523', '366'],
            default => throw new \InvalidArgumentException("Neznámý typ pracovního vztahu: {$relationType}."),
        };

        return [
            'gross_debit' => $grossDebit,
            'gross_credit' => $grossCredit,
            'employer_insurance_debit' => '524',
            'employer_insurance_credit' => '336',
        ];
    }
}
