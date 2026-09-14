<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

use MyInvoice\Service\Submission\SubmissionOutboxIdentity;

/** Pevný kontrakt historického v1 klíče; nikdy libovolné spojování JSON hodnot. */
final class CompanyBackupSubmissionIdentity
{
    /** @return list<array{key:string,column:string}> */
    public static function projection(): array
    {
        return array_map(static fn (string $column): array =>
            ['key' => $column, 'column' => $column],
            ['agenda_code', 'artifact_id', 'artifact_kind', 'artifact_sha256',
                'channel', 'environment', 'recipient_id', 'supplier_id']);
    }

    /** @param array<string,mixed> $row */
    public static function key(array $row): string
    {
        foreach (['supplier_id', 'artifact_id'] as $column) {
            if (!is_int($row[$column] ?? null) || $row[$column] < 1) {
                throw self::invalid($column);
            }
        }
        if (!array_key_exists('recipient_id', $row)
            || ($row['recipient_id'] !== null && (!is_int($row['recipient_id']) || $row['recipient_id'] < 1))) {
            throw self::invalid('recipient_id');
        }
        foreach (['environment' => ['production', 'test'], 'channel' => ['isds', 'epo'],
            'artifact_kind' => ['payroll_submission', 'tax_submission', 'document']] as $column => $values) {
            if (!in_array($row[$column] ?? null, $values, true)) {
                throw self::invalid($column);
            }
        }
        foreach (['agenda_code' => '/\A[A-Z][A-Z0-9_]{1,47}\z/D',
            'artifact_sha256' => '/\A[0-9a-f]{64}\z/D'] as $column => $pattern) {
            if (!is_string($row[$column] ?? null) || preg_match($pattern, $row[$column]) !== 1) {
                throw self::invalid($column);
            }
        }
        return SubmissionOutboxIdentity::key($row['supplier_id'], $row['environment'],
            $row['channel'], $row['agenda_code'], $row['artifact_kind'], $row['artifact_id'],
            $row['artifact_sha256'], $row['recipient_id']);
    }

    private static function invalid(string $column): CompanyBackupDataSourceException
    {
        return new CompanyBackupDataSourceException('data_submission_identity_invalid', 'table:submission_outbox', $column);
    }
}
