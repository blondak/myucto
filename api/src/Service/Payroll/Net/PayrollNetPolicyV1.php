<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Net;

use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;

final readonly class PayrollNetPolicyV1
{
    public const ID = 'cz-payroll-net.domain.v1';

    /**
     * @param non-empty-list<string> $calculationOrder
     */
    private function __construct(
        public string $id,
        public array $calculationOrder,
        public string $canonicalHash,
    ) {}

    public static function create(): self
    {
        $calculationOrder = [
            'gross_cash_income',
            'correction',
            'employee_social_insurance',
            'employee_health_insurance',
            'advance_income_tax',
            'withholding_income_tax',
            'income_tax_bonus',
            'ordered_deductions',
        ];
        $definition = [
            'calculation_order' => $calculationOrder,
            'formula' => [
                'net_before_deductions' =>
                    'gross_cash_income + correction - employee_social_insurance'
                    . ' - employee_health_insurance - advance_income_tax'
                    . ' - withholding_income_tax + income_tax_bonus',
                'net_payable' => 'net_before_deductions - ordered_deductions',
            ],
            'id' => self::ID,
            'rounding' => 'inputs_are_integer_minor_units',
        ];

        return new self(
            self::ID,
            $calculationOrder,
            hash('sha256', CanonicalJson::encode($definition)),
        );
    }
}
