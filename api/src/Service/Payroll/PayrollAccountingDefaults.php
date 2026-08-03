<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll;

final class PayrollAccountingDefaults
{
    /** @var array<string,array{code:string,type:string}> */
    public const ACCOUNTS = [
        'employment_gross_debit' => ['code' => '521', 'type' => 'expense'],
        'employment_gross_credit' => ['code' => '331', 'type' => 'liability'],
        'partner_gross_debit' => ['code' => '522', 'type' => 'expense'],
        'partner_gross_credit' => ['code' => '366', 'type' => 'liability'],
        'statutory_gross_debit' => ['code' => '523', 'type' => 'expense'],
        'statutory_gross_credit' => ['code' => '366', 'type' => 'liability'],
        'employer_insurance_debit' => ['code' => '524', 'type' => 'expense'],
        'social_insurance_credit' => ['code' => '336', 'type' => 'liability'],
        'health_insurance_credit' => ['code' => '336', 'type' => 'liability'],
        'income_tax_credit' => ['code' => '342', 'type' => 'liability'],
        'other_deductions_credit' => ['code' => '379', 'type' => 'liability'],
    ];

    /** @return array<string,string> */
    public static function codes(): array
    {
        return array_map(
            static fn (array $definition): string => $definition['code'],
            self::ACCOUNTS,
        );
    }

    /** @return array{gross_debit:string,gross_credit:string,employer_insurance_debit:string,employer_insurance_credit:string} */
    public static function forRelation(string $relationType): array
    {
        [$grossDebit, $grossCredit] = match ($relationType) {
            'employment', 'small_scale_employment', 'dpp', 'dpc' => [
                self::ACCOUNTS['employment_gross_debit']['code'],
                self::ACCOUNTS['employment_gross_credit']['code'],
            ],
            'partner_dependent' => [
                self::ACCOUNTS['partner_gross_debit']['code'],
                self::ACCOUNTS['partner_gross_credit']['code'],
            ],
            'statutory_body' => [
                self::ACCOUNTS['statutory_gross_debit']['code'],
                self::ACCOUNTS['statutory_gross_credit']['code'],
            ],
            default => throw new \InvalidArgumentException("Neznámý typ pracovního vztahu: {$relationType}."),
        };

        return [
            'gross_debit' => $grossDebit,
            'gross_credit' => $grossCredit,
            'employer_insurance_debit' => self::ACCOUNTS['employer_insurance_debit']['code'],
            'employer_insurance_credit' => self::ACCOUNTS['social_insurance_credit']['code'],
        ];
    }
}
