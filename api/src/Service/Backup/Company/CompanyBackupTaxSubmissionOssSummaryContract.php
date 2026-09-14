<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/** Známý archivní souhrn OSSEI1 včetně verzovaného obrazu dokladů. */
final class CompanyBackupTaxSubmissionOssSummaryContract
{
    private const REGISTRY_KEY = 'table:tax_submissions';

    private const SUMMARY_KEYS = [
        'corrections_count', 'form_code', 'invoice_count', 'period',
        'return_currency', 'rows_count', 'scheme', 'snapshot',
        'submission_deadline', 'total_base', 'total_corrections',
        'total_payable', 'total_vat', 'warnings',
    ];

    private const SNAPSHOT_KEYS = [
        'corrections', 'documents', 'return_currency', 'rows', 'schema', 'totals',
    ];

    private const DOCUMENT_KEYS = [
        'adjusted_period', 'base', 'country', 'doc_number', 'invoice_id',
        'item_id', 'rate', 'status', 'tax_date', 'updated_at', 'vat',
    ];

    public static function assertSummary(\stdClass $summary): void
    {
        if (!self::keys($summary, self::SUMMARY_KEYS)
            || !is_string($summary->period)
            || $summary->form_code !== 'ossei1'
            || $summary->scheme !== 'eu'
            || !is_string($summary->return_currency)
            || !self::nonnegativeInt($summary->rows_count)
            || !self::nonnegativeInt($summary->corrections_count)
            || !self::nonnegativeInt($summary->invoice_count)
            || !self::number($summary->total_base)
            || !self::number($summary->total_vat)
            || !self::number($summary->total_corrections)
            || !self::number($summary->total_payable)
            || !($summary->submission_deadline === null || is_string($summary->submission_deadline))
            || !self::stringList($summary->warnings)
            || !self::snapshot($summary->snapshot)
        ) {
            throw new CompanyBackupDataSourceException(
                'data_tax_submission_summary_invalid', self::REGISTRY_KEY, 'summary_json',
            );
        }
    }

    private static function snapshot(mixed $snapshot): bool
    {
        if (!$snapshot instanceof \stdClass
            || !self::keys($snapshot, self::SNAPSHOT_KEYS)
            // Novější verze producenta musí nejdřív projít revizí kontraktu záloh.
            || $snapshot->schema !== 'oss-filing-snapshot.v1'
            || !is_string($snapshot->return_currency)
            || !$snapshot->totals instanceof \stdClass
            || !self::keys($snapshot->totals, ['base', 'corrections', 'payable', 'vat'])
        ) {
            return false;
        }
        foreach (get_object_vars($snapshot->totals) as $amount) {
            if (!self::number($amount)) {
                return false;
            }
        }
        if (!self::list($snapshot->rows) || !self::list($snapshot->corrections)
            || !self::list($snapshot->documents)) {
            return false;
        }
        foreach ($snapshot->rows as $row) {
            if (!$row instanceof \stdClass
                || !self::keys($row, ['base', 'country', 'rate', 'rate_type', 'supply_type', 'vat'])
                || !is_string($row->country)
                || !self::nullableString($row->supply_type)
                || !self::nullableString($row->rate_type)
                || !self::number($row->rate)
                || !self::number($row->base)
                || !self::number($row->vat)) {
                return false;
            }
        }
        foreach ($snapshot->corrections as $correction) {
            if (!$correction instanceof \stdClass
                || !self::keys($correction, ['amount', 'country', 'period'])
                || !is_string($correction->period)
                || !is_string($correction->country)
                || !self::number($correction->amount)) {
                return false;
            }
        }
        foreach ($snapshot->documents as $document) {
            if (!$document instanceof \stdClass
                || !self::keys($document, self::DOCUMENT_KEYS)
                || !is_int($document->invoice_id) || $document->invoice_id < 1
                || !is_int($document->item_id) || $document->item_id < 1
                || !self::nullableString($document->doc_number)
                || !is_string($document->country)
                || !self::nullableString($document->tax_date)
                || !self::number($document->rate)
                || !self::number($document->base)
                || !self::number($document->vat)
                || !self::nullableString($document->adjusted_period)
                || !self::nullableString($document->status)
                || !self::nullableString($document->updated_at)) {
                return false;
            }
        }
        return true;
    }

    /** @param list<string> $expected */
    private static function keys(\stdClass $value, array $expected): bool
    {
        $actual = array_keys(get_object_vars($value));
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);
        return $actual === $expected;
    }

    private static function list(mixed $value): bool
    {
        return is_array($value) && array_is_list($value);
    }

    private static function stringList(mixed $value): bool
    {
        if (!self::list($value)) {
            return false;
        }
        foreach ($value as $item) {
            if (!is_string($item)) {
                return false;
            }
        }
        return true;
    }

    private static function nullableString(mixed $value): bool
    {
        return $value === null || is_string($value);
    }

    private static function nonnegativeInt(mixed $value): bool
    {
        return is_int($value) && $value >= 0;
    }

    private static function number(mixed $value): bool
    {
        return is_int($value) || is_float($value) && is_finite($value);
    }
}
