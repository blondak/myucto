<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/** Známé souhrny podání bez vnitřních ID, které se při obnově nepřepisují. */
final class CompanyBackupTaxSubmissionScalarSummaryContract
{
    private const REGISTRY_KEY = 'table:tax_submissions';

    private const KH_KEYS = [
        'a1_count', 'a2_count', 'a4_count', 'a5_count_aggregated',
        'b1_count', 'b2_count', 'b3_count_aggregated', 'c_jed_vyzvy',
        'd_zjist', 'is_follow_up', 'khdph_forma', 'period',
        'submission_deadline', 'variant',
    ];

    private const KH_REPLY_KEYS = ['is_vyzva_odpoved', 'vyzva_odp'];

    private const DPFO_NUMBERS = [
        'balance_due', 'bonus_qualifying_income', 'child_bonus', 'child_credit',
        'final_tax', 'loss_applied', 'rounded_base', 's7_profit',
        'separate_base', 'separate_base_tax', 'spouse_credit', 'tax16',
        'tax_after_credits', 'total_base', 'uhrn_710', 'year_tax_loss',
    ];

    private const DPPO_NUMBERS = [
        'balance_due', 'base', 'credits', 'credits_entitlement',
        'disabled_employee_credit_amount', 'disabled_employee_severe_credit_amount',
        'disabled_employees_avg', 'disabled_employees_severe_avg',
        'donation_applied', 'education_applied', 'loss_applied', 'rate',
        'rnd_applied', 'rounded_base', 'tax_gross', 'total_tax', 'vh',
    ];

    public static function supports(string $form): bool
    {
        return in_array($form, ['dphkh1', 'dpfdp7', 'dppdp9', 'osvc25'], true);
    }

    public static function assertSummary(string $form, \stdClass $summary): void
    {
        if (!self::supports($form)) {
            throw new CompanyBackupDataSourceException(
                'data_tax_submission_summary_unsupported', self::REGISTRY_KEY, 'form_code',
            );
        }
        $valid = match ($form) {
            'dphkh1' => self::kh($summary),
            'dpfdp7' => self::income($summary, self::DPFO_NUMBERS),
            'dppdp9' => self::income($summary, self::DPPO_NUMBERS),
            'osvc25' => self::osvc($summary),
            default => false,
        };
        if (!$valid) {
            throw new CompanyBackupDataSourceException(
                'data_tax_submission_summary_invalid', self::REGISTRY_KEY, 'summary_json',
            );
        }
    }

    private static function kh(\stdClass $summary): bool
    {
        $variant = $summary->variant ?? null;
        $forms = [
            'radne' => 'B', 'opravne' => 'O',
            'nasledne' => 'N', 'nasledne_opravne' => 'E',
            'vyzva_nulove' => 'B', 'vyzva_potvrzeni' => 'N',
        ];
        if (!is_string($variant) || !isset($forms[$variant])) {
            return false;
        }
        $reply = str_starts_with($variant, 'vyzva_');
        if (!self::keys($summary, $reply ? [...self::KH_KEYS, ...self::KH_REPLY_KEYS] : self::KH_KEYS)
            || !self::period($summary->period)
            || !self::date($summary->submission_deadline)
            || $summary->khdph_forma !== $forms[$variant]
            || !is_bool($summary->is_follow_up)
            || $summary->is_follow_up !== in_array($variant,
                ['nasledne', 'nasledne_opravne', 'vyzva_potvrzeni'], true)
            || !($summary->d_zjist === null || self::date($summary->d_zjist))
            || !($summary->c_jed_vyzvy === null || is_string($summary->c_jed_vyzvy))
        ) {
            return false;
        }
        foreach (['a1_count', 'a2_count', 'a4_count', 'a5_count_aggregated',
            'b1_count', 'b2_count', 'b3_count_aggregated'] as $count) {
            if (!is_int($summary->$count) || $summary->$count < 0) {
                return false;
            }
        }
        if ($reply) {
            return $summary->is_vyzva_odpoved === true
                && $summary->vyzva_odp === ($variant === 'vyzva_nulove' ? 'B' : 'P')
                && $summary->d_zjist === null
                && is_string($summary->c_jed_vyzvy);
        }
        return true;
    }

    /** @param list<string> $numbers */
    private static function income(\stdClass $summary, array $numbers): bool
    {
        if (!self::keys($summary, [...$numbers, 'variant', 'warnings'])
            || !in_array($summary->variant, ['radne', 'opravne', 'dodatecne'], true)
            || !self::warnings($summary->warnings)
        ) {
            return false;
        }
        foreach ($numbers as $field) {
            if (!self::finiteNumber($summary->$field)) {
                return false;
            }
        }
        return true;
    }

    private static function osvc(\stdClass $summary): bool
    {
        return self::keys($summary, ['insurance', 'warnings'])
            && $summary->insurance === 'social'
            && self::warnings($summary->warnings);
    }

    /** @param list<string> $expected */
    private static function keys(\stdClass $summary, array $expected): bool
    {
        $actual = array_keys(get_object_vars($summary));
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);
        return $actual === $expected;
    }

    private static function warnings(mixed $value): bool
    {
        if (!is_array($value) || !array_is_list($value)) {
            return false;
        }
        foreach ($value as $warning) {
            if (!is_string($warning)) {
                return false;
            }
        }
        return true;
    }

    private static function period(mixed $value): bool
    {
        return is_string($value)
            && preg_match('/^\d{4}-(?:0[1-9]|1[0-2]|Q[1-4])$/D', $value) === 1;
    }

    private static function date(mixed $value): bool
    {
        if (!is_string($value) || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $value, $m) !== 1) {
            return false;
        }
        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    private static function finiteNumber(mixed $value): bool
    {
        return is_int($value) || is_float($value) && is_finite($value);
    }
}
