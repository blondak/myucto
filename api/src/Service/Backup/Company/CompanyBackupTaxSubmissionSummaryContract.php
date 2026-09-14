<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/** Přesně známý tvar souhrnu SH; samotné JSON zůstává beze změny. */
final class CompanyBackupTaxSubmissionSummaryContract
{
    private const REGISTRY_KEY = 'table:tax_submissions';

    private const SH_KEYS = [
        'd_zjist', 'is_follow_up', 'period', 'reference_submission_id',
        'rows', 'rows_count', 'shvies_forma', 'storno_rows',
        'submission_deadline', 'total_amount', 'variant',
    ];

    private const SH_ROW_KEYS = [
        'amount', 'count', 'counterparty_name', 'country_iso2',
        'k_stat', 'sh_type', 'vat_id',
    ];

    /** @param array<string,mixed> $row */
    public static function assertRow(array $row): void
    {
        if (($row['form_code'] ?? null) !== 'dphshv') {
            throw new CompanyBackupDataSourceException(
                'data_tax_submission_summary_unsupported', self::REGISTRY_KEY, 'form_code',
            );
        }
        $json = $row['summary_json'] ?? null;
        if (!is_string($json)) {
            throw self::invalid();
        }
        // Volající před remapováním navíc ověřuje duplicitní klíče JSON lexerem.
        try {
            $summary = json_decode($json, false, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (\JsonException $e) {
            throw new CompanyBackupDataSourceException(
                'data_tax_submission_summary_invalid', self::REGISTRY_KEY, 'summary_json', $e,
            );
        }
        if (!$summary instanceof \stdClass || !self::hasKeys($summary, self::SH_KEYS)
            || !is_string($summary->period)
            || preg_match('/^\d{4}-(?:0[1-9]|1[0-2]|Q[1-4])$/D', $summary->period) !== 1
            || !self::date($summary->submission_deadline)
            || !($summary->d_zjist === null || self::date($summary->d_zjist))
            || !is_int($summary->rows_count) || $summary->rows_count < 0
            || !is_int($summary->storno_rows) || $summary->storno_rows < 0
            || !self::finiteNumber($summary->total_amount)
            || !is_bool($summary->is_follow_up)
            || !is_string($summary->variant)
            || !in_array($summary->variant, ['radne', 'nasledne'], true)
            || $summary->shvies_forma !== ($summary->variant === 'radne' ? 'R' : 'N')
            || $summary->is_follow_up !== ($summary->variant === 'nasledne')
            || !($summary->reference_submission_id === null
                || is_int($summary->reference_submission_id) && $summary->reference_submission_id > 0)
            || !is_array($summary->rows)
        ) {
            throw self::invalid();
        }
        foreach ($summary->rows as $detail) {
            if (!$detail instanceof \stdClass || !self::hasKeys($detail, self::SH_ROW_KEYS)
                || !is_string($detail->country_iso2)
                || !is_string($detail->k_stat)
                || !is_string($detail->vat_id)
                || !is_string($detail->sh_type)
                || !is_string($detail->counterparty_name)
                || !self::finiteNumber($detail->amount)
                || !is_int($detail->count) || $detail->count < 0
            ) {
                throw self::invalid();
            }
        }
    }

    /** @return list<array<string,mixed>> */
    public static function embeddedReferences(): array
    {
        return [[
            'column' => 'summary_json',
            'path' => ['reference_submission_id'],
            'target' => self::REGISTRY_KEY,
            'target_columns' => ['id'],
            'mapping' => CompanyBackupReferenceMapping::TenantId->value,
            'nullable' => true,
            'condition' => null,
            'fallbacks' => [],
        ]];
    }

    /** @param list<string> $expected */
    private static function hasKeys(\stdClass $object, array $expected): bool
    {
        $actual = array_keys(get_object_vars($object));
        sort($actual, SORT_STRING);
        return $actual === $expected;
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

    private static function invalid(): CompanyBackupDataSourceException
    {
        return new CompanyBackupDataSourceException(
            'data_tax_submission_summary_invalid', self::REGISTRY_KEY, 'summary_json',
        );
    }
}
