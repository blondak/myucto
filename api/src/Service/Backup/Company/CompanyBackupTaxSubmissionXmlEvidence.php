<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/** Kontroluje uložený důkazní XML snapshot po bajtech, bez parsování či přepočtu daně. */
final class CompanyBackupTaxSubmissionXmlEvidence
{
    public const REGISTRY_KEY = 'table:tax_submissions';

    /** @param array<string,mixed> $row Zdrojový nebo obnovený úplný řádek podání. */
    public static function assertRow(array $row): void
    {
        if (!is_string($row['xml_content'] ?? null)) {
            throw self::invalid('tax_submission_xml_content_invalid', 'xml_content');
        }
        if (!is_int($row['xml_size_bytes'] ?? null) || $row['xml_size_bytes'] < 0) {
            throw self::invalid('tax_submission_xml_size_invalid', 'xml_size_bytes');
        }
        if (!is_string($row['xml_sha256'] ?? null)
            || preg_match('/\A[a-f0-9]{64}\z/D', $row['xml_sha256']) !== 1) {
            throw self::invalid('tax_submission_xml_sha256_invalid', 'xml_sha256');
        }

        $xml = $row['xml_content'];
        if (strlen($xml) !== $row['xml_size_bytes']) {
            throw self::invalid('tax_submission_xml_size_mismatch', 'xml_size_bytes');
        }
        if (!hash_equals($row['xml_sha256'], hash('sha256', $xml))) {
            throw self::invalid('tax_submission_xml_sha256_mismatch', 'xml_sha256');
        }
    }

    private static function invalid(string $code, string $column): CompanyBackupPreflightException
    {
        return new CompanyBackupPreflightException($code, self::REGISTRY_KEY, $column);
    }
}
