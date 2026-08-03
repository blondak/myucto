<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\IncomeTax;

use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetVersion;
use UnexpectedValueException;

final readonly class EmploymentIncomeTaxPolicy2026
{
    public const ID = 'cz-employment-income-tax-2026.domain.v1';

    /**
     * @param array<string,int|string> $parameters
     * @param list<string> $sources
     */
    private function __construct(
        public array $parameters,
        public array $sources,
        public string $canonicalHash,
    ) {}

    public static function create(): self
    {
        $parameters = [
            'advance.high_rate' => '0.23',
            'advance.high_threshold.monthly' => 14_690_100,
            'advance.low_rate' => '0.15',
            'bonus.minimum_amount.monthly' => 5_000,
            'bonus.minimum_income.monthly' => 1_120_000,
            'bonus.minimum_income.yearly' => 13_440_000,
            'credit.child.first.monthly' => 126_700,
            'credit.child.second.monthly' => 186_000,
            'credit.child.third_and_next.monthly' => 232_000,
            'credit.disability.basic.monthly' => 21_000,
            'credit.disability.extended.monthly' => 42_000,
            'credit.taxpayer.monthly' => 257_000,
            'credit.ztp_p.monthly' => 134_500,
            'dpp.withholding.maximum' => 1_199_900,
            'other.withholding.maximum' => 449_900,
            'withholding.rate' => '0.15',
        ];
        $sources = [
            'https://financnisprava.gov.cz/cs/dane/dane/dan-z-prijmu/zamestnanci-zamestnavatele/obecne-informace',
        ];
        $hash = hash('sha256', CanonicalJson::encode([
            'id' => self::ID,
            'parameters' => $parameters,
            'sources' => $sources,
        ]));

        return new self($parameters, $sources, $hash);
    }

    public function money(string $key): int
    {
        $value = $this->parameters[$key] ?? null;
        if (!is_int($value)) {
            throw new UnexpectedValueException("Income tax policy parameter {$key} is not money.");
        }

        return $value;
    }

    public function rate(string $key): string
    {
        $value = $this->parameters[$key] ?? null;
        if (!is_string($value)) {
            throw new UnexpectedValueException("Income tax policy parameter {$key} is not a rate.");
        }

        return $value;
    }

    public function assertCompatibleRuleset(PayrollRulesetVersion $ruleset): void
    {
        foreach ([
            'advance.high_rate',
            'advance.high_threshold.monthly',
            'advance.low_rate',
            'bonus.minimum_amount.monthly',
            'bonus.minimum_income.monthly',
            'bonus.minimum_income.yearly',
            'credit.child.first.monthly',
            'credit.child.second.monthly',
            'credit.child.third_and_next.monthly',
            'credit.taxpayer.monthly',
            'dpp.withholding.maximum',
            'withholding.rate',
        ] as $key) {
            $actual = $ruleset->parameter($key)->value;
            if ($actual !== $this->parameters[$key]) {
                throw new UnexpectedValueException(
                    "Income tax ruleset parameter {$key} does not match the domain policy.",
                );
            }
        }
    }
}
