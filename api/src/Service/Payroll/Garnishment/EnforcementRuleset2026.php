<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Garnishment;

use LogicException;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;

final class EnforcementRuleset2026
{
    public const ID = 'cz-payroll-2026.enforcement-deductions.mz14.v1';
    public const EXPECTED_HASH = '8145e6f348fb171e8903eaf37ee9c8940187ef039a6975101835900008fa3cac';
    public const EFFECTIVE_FROM = '2026-01-01';
    public const EFFECTIVE_TO = '2026-12-31';
    public const LIFE_MINIMUM_MINOR_UNITS = 486_000;
    public const NORMATIVE_RENT_MINOR_UNITS = 943_000;
    public const ENERGY_FLAT_MINOR_UNITS = 230_000;
    public const PROTECTED_CALCULATION_BASE_MINOR_UNITS = 1_659_000;
    public const DEBTOR_SHARE_NUMERATOR = 85;
    public const DEBTOR_SHARE_DENOMINATOR = 100;
    public const PROTECTED_DEBTOR_BASE_MINOR_UNITS = 1_410_150;
    public const DEPENDANT_SHARE_NUMERATOR = 1;
    public const DEPENDANT_SHARE_DENOMINATOR = 4;
    public const FULLY_ATTACHABLE_THRESHOLD_MINOR_UNITS = 3_152_100;
    public const FULLY_ATTACHABLE_FACTOR_NUMERATOR = 19;
    public const FULLY_ATTACHABLE_FACTOR_DENOMINATOR = 10;
    public const FOUR_ENFORCEMENT_PENSION_EXCEPTION_LIMIT_MINOR_UNITS = 108_900;
    public const EMPLOYER_FLAT_FEE_MAX_MINOR_UNITS = 5_000;
    public const EMPLOYER_FLAT_FEE_ORDER_EFFECTIVE_FROM = '2022-01-01';

    /** @return array<string, mixed> */
    public static function snapshot(): array
    {
        return [
            'effective_from' => self::EFFECTIVE_FROM,
            'effective_to' => self::EFFECTIVE_TO,
            'id' => self::ID,
            'parameters' => [
                'dependant_share_denominator' => self::DEPENDANT_SHARE_DENOMINATOR,
                'dependant_share_numerator' => self::DEPENDANT_SHARE_NUMERATOR,
                'debtor_share_denominator' => self::DEBTOR_SHARE_DENOMINATOR,
                'debtor_share_numerator' => self::DEBTOR_SHARE_NUMERATOR,
                'energy_flat_minor_units' => self::ENERGY_FLAT_MINOR_UNITS,
                'employer_flat_fee_max_minor_units' => self::EMPLOYER_FLAT_FEE_MAX_MINOR_UNITS,
                'employer_flat_fee_order_effective_from' => self::EMPLOYER_FLAT_FEE_ORDER_EFFECTIVE_FROM,
                'four_enforcement_pension_exception_limit_minor_units' =>
                    self::FOUR_ENFORCEMENT_PENSION_EXCEPTION_LIMIT_MINOR_UNITS,
                'fully_attachable_factor_denominator' => self::FULLY_ATTACHABLE_FACTOR_DENOMINATOR,
                'fully_attachable_factor_numerator' => self::FULLY_ATTACHABLE_FACTOR_NUMERATOR,
                'fully_attachable_threshold_minor_units' => self::FULLY_ATTACHABLE_THRESHOLD_MINOR_UNITS,
                'life_minimum_minor_units' => self::LIFE_MINIMUM_MINOR_UNITS,
                'normative_rent_minor_units' => self::NORMATIVE_RENT_MINOR_UNITS,
                'protected_calculation_base_minor_units' =>
                    self::PROTECTED_CALCULATION_BASE_MINOR_UNITS,
                'protected_debtor_base_minor_units' => self::PROTECTED_DEBTOR_BASE_MINOR_UNITS,
                'rounding' => [
                    'protected_total' => 'ceil_to_whole_czk_after_sum',
                    'thirds_base' => 'floor_to_whole_czk_divisible_by_three',
                    'proportional_allocation' => 'floor_minor_units_then_largest_remainder',
                ],
            ],
            'retrieved_on' => '2026-08-03',
            'sources' => [
                [
                    'id' => 'justice-enforcement-calculator-2026',
                    'url' => 'https://exekuce.justice.cz/vypocet-srazek-ze-mzdy/',
                ],
                [
                    'id' => 'justice-enforcement-income',
                    'url' => 'https://exekuce.justice.cz/srazky-ze-mzdy-a-jinych-prijmu/',
                ],
                [
                    'id' => 'justice-insolvency-debt-relief',
                    'url' => 'https://insolvence.justice.cz/jak-ven-z-dluhove-pasti/oddluzeni/',
                ],
                [
                    'id' => 'mpsv-labour-code-deductions',
                    'url' => 'https://ppropo.mpsv.cz/pdf/XXI4Srazkyzprijmuzpracovnepravni.pdf',
                ],
            ],
        ];
    }

    public static function canonicalHash(): string
    {
        $hash = hash('sha256', CanonicalJson::encode(self::snapshot()));
        if (!hash_equals(self::EXPECTED_HASH, $hash)) {
            throw new LogicException('Enforcement ruleset 2026 canonical checksum mismatch.');
        }

        return $hash;
    }
}
