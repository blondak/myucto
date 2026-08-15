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
        // Protiúčet zápočtu čisté mzdy na účet společníka (331/366 MD / 365 D).
        // Firemní default; konkrétní analytiku (365.100…) drží výplatní pravidlo
        // osoby, viz PayrollPartnerSettlement.
        'partner_settlement_credit' => ['code' => '365', 'type' => 'liability'],
    ];

    /** @return array<string,string> */
    public static function codes(): array
    {
        return array_map(
            static fn (array $definition): string => $definition['code'],
            self::ACCOUNTS,
        );
    }

    /**
     * @param array<string,mixed>|null $configuredAccounts
     * @return array{
     *   gross_debit:string,
     *   gross_credit:string,
     *   employer_insurance_debit:string,
     *   employer_insurance_credit:string
     * }
     */
    public static function forRelation(
        string $relationType,
        ?array $configuredAccounts = null,
    ): array
    {
        $accounts = $configuredAccounts ?? self::codes();
        $keys = self::relationAccountKeys($relationType);
        return [
            'gross_debit' => self::account($accounts, $keys['gross_debit']),
            'gross_credit' => self::account($accounts, $keys['gross_credit']),
            'employer_insurance_debit' => self::account(
                $accounts,
                $keys['employer_insurance_debit'],
            ),
            'employer_insurance_credit' => self::account(
                $accounts,
                $keys['employer_insurance_credit'],
            ),
        ];
    }

    /**
     * @return array{
     *   gross_debit:string,
     *   gross_credit:string,
     *   employer_insurance_debit:string,
     *   employer_insurance_credit:string
     * }
     */
    public static function relationAccountKeys(string $relationType): array
    {
        [$grossDebit, $grossCredit] = match ($relationType) {
            'employment', 'small_scale_employment', 'dpp', 'dpc' => [
                'employment_gross_debit',
                'employment_gross_credit',
            ],
            'partner_dependent' => [
                'partner_gross_debit',
                'partner_gross_credit',
            ],
            'statutory_body' => [
                'statutory_gross_debit',
                'statutory_gross_credit',
            ],
            default => throw new \InvalidArgumentException(
                "Neznámý typ pracovního vztahu: {$relationType}.",
            ),
        };

        return [
            'gross_debit' => $grossDebit,
            'gross_credit' => $grossCredit,
            'employer_insurance_debit' => 'employer_insurance_debit',
            'employer_insurance_credit' => 'social_insurance_credit',
        ];
    }

    /** @param array<string,mixed> $accounts */
    private static function account(array $accounts, string $key): string
    {
        $account = $accounts[$key] ?? null;
        if (!is_string($account) || trim($account) === '') {
            throw new \InvalidArgumentException(
                "Chybí účetní předkontace {$key}.",
            );
        }

        return $account;
    }
}
