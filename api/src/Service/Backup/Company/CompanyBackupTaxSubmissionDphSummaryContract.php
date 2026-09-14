<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/** Přesný současný tvar uloženého DPHDP3 souhrnu, bez daňového přepočtu. */
final class CompanyBackupTaxSubmissionDphSummaryContract
{
    private const REGISTRY_KEY = 'table:tax_submissions';

    private const KEYS = [
        'dapdph_forma', 'd_zjist', 'document_refs', 'is_amendment',
        'is_excess_deduction', 'last_known_tax', 'lines', 'period',
        'period_type', 'quarter', 'reference_submission_id',
        'submission_deadline', 'supplier_vat_period', 'tax_difference',
        'tax_due', 'total_vat_input', 'total_vat_output', 'typ_platce',
        'variant', 'vat_coefficient_percent', 'vat_reduced_applied',
        'vat_reduced_deduction', 'vat_settlement',
    ];

    private const LINE_KEYS = ['base', 'count', 'label', 'vat'];
    private const DOCUMENT_KEYS = [
        'document_kind', 'invoice_id', 'source', 'status',
        'tax_date', 'total', 'updated_at',
    ];
    private const SETTLEMENT_KEYS = [
        'denominator', 'final_percent', 'numerator', 'vypor_odp',
    ];

    public static function assertSummary(\stdClass $summary): void
    {
        if (!self::hasKeys($summary, self::KEYS)
            || !is_string($summary->period)
            || !in_array($summary->period_type, ['monthly', 'quarterly'], true)
            || !in_array($summary->typ_platce, ['I', 'P'], true)
            || !($summary->quarter === null || is_int($summary->quarter))
            || !self::lines($summary->lines)
            || !self::number($summary->total_vat_output)
            || !self::number($summary->total_vat_input)
            || !self::number($summary->tax_due)
            || !is_bool($summary->is_excess_deduction)
            || !is_string($summary->submission_deadline)
            || !in_array($summary->variant,
                ['radne', 'opravne', 'dodatecne', 'dodatecne_opravne'], true)
            || !in_array($summary->dapdph_forma, ['B', 'O', 'D', 'E'], true)
            || !is_bool($summary->is_amendment)
            || !($summary->d_zjist === null || is_string($summary->d_zjist))
            || !($summary->last_known_tax === null || self::number($summary->last_known_tax))
            || !($summary->tax_difference === null || self::number($summary->tax_difference))
            || !($summary->reference_submission_id === null
                || is_int($summary->reference_submission_id)
                    && $summary->reference_submission_id > 0)
            || !is_string($summary->supplier_vat_period)
            || !self::documents($summary->document_refs)
            || !self::number($summary->vat_reduced_deduction)
            || !($summary->vat_coefficient_percent === null
                || is_int($summary->vat_coefficient_percent))
            || !self::number($summary->vat_reduced_applied)
            || !self::settlement($summary->vat_settlement)
        ) {
            throw self::invalid();
        }
    }

    private static function lines(mixed $lines): bool
    {
        if ($lines instanceof \stdClass) {
            $rows = get_object_vars($lines);
            foreach ($rows as $code => $row) {
                // dphdp3_line je volný VARCHAR(10); builder i neznámý kód ponechá
                // v summary s warningem. Nesmí se vymýšlet whitelist lineMap.
                if (preg_match('/\A[^\p{C}]{0,10}\z/uD', (string) $code) !== 1
                    || !self::line($row)) {
                    return false;
                }
            }
            return true;
        }
        if (!is_array($lines) || !array_is_list($lines)) {
            return false;
        }
        // [] je běžný prázdný výkaz. Klíč "0" může z volného číselníku vytvořit
        // neprázdný PHP list a json_encode jej také zapíše jako pole.
        foreach ($lines as $row) {
            if (!self::line($row)) {
                return false;
            }
        }
        return true;
    }

    private static function line(mixed $row): bool
    {
        return $row instanceof \stdClass
            && self::hasKeys($row, self::LINE_KEYS)
            && self::number($row->base)
            && self::number($row->vat)
            && is_int($row->count) && $row->count >= 0
            && is_string($row->label);
    }

    private static function documents(mixed $documents): bool
    {
        if (!is_array($documents) || !array_is_list($documents)) {
            return false;
        }
        foreach ($documents as $document) {
            if (!$document instanceof \stdClass
                || !self::hasKeys($document, self::DOCUMENT_KEYS)
                || !in_array($document->source, ['sale', 'purchase', 'cash'], true)
                || !is_int($document->invoice_id) || $document->invoice_id < 1
                || !($document->document_kind === null || is_string($document->document_kind))
                || !is_string($document->status)
                || !($document->tax_date === null || is_string($document->tax_date))
                || !($document->updated_at === null || is_string($document->updated_at))
                || !self::number($document->total)
            ) {
                return false;
            }
        }
        return true;
    }

    private static function settlement(mixed $settlement): bool
    {
        return $settlement === null
            || $settlement instanceof \stdClass
                && self::hasKeys($settlement, self::SETTLEMENT_KEYS)
                && is_int($settlement->final_percent)
                && is_int($settlement->numerator)
                && is_int($settlement->denominator)
                && self::number($settlement->vypor_odp);
    }

    private static function number(mixed $value): bool
    {
        return is_int($value) || is_float($value) && is_finite($value);
    }

    /** @param list<string> $expected */
    private static function hasKeys(\stdClass $object, array $expected): bool
    {
        $actual = array_keys(get_object_vars($object));
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);
        return $actual === $expected;
    }

    private static function invalid(): CompanyBackupDataSourceException
    {
        return new CompanyBackupDataSourceException(
            'data_tax_submission_summary_invalid', self::REGISTRY_KEY, 'summary_json',
        );
    }
}
