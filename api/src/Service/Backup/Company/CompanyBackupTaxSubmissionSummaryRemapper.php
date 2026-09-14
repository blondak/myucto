<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/** Přepíše jen ID reference v SH souhrnu; souhrny bez ID ponechá doslova. */
final class CompanyBackupTaxSubmissionSummaryRemapper
{
    /** @param array<string,mixed> $mapped */
    public static function rewrite(string $source, array $mapped, string $formCode): string
    {
        if ($formCode !== 'dphshv') {
            if (!CompanyBackupTaxSubmissionScalarSummaryContract::supports($formCode)) {
                throw new CompanyBackupDataSourceException(
                    'data_tax_submission_summary_unsupported', 'table:tax_submissions', 'form_code',
                );
            }
            CompanyBackupTaxSubmissionSummaryContract::assertRow([
                'form_code' => $formCode, 'summary_json' => $source,
            ]);
            try {
                return CompanyBackupLosslessJson::rewriteIntegerTokens(
                    $source,
                    static fn (array $_path, string $_token): null => null,
                );
            } catch (\Throwable $e) {
                throw new CompanyBackupDataSourceException(
                    'data_tax_submission_summary_invalid', 'table:tax_submissions', 'summary_json', $e,
                );
            }
        }
        $target = $mapped['reference_submission_id'] ?? null;
        if ($target !== null && (!is_int($target) || $target < 1)) {
            throw self::invalid();
        }
        $seen = false;
        try {
            $result = CompanyBackupLosslessJson::rewriteIntegerTokens(
                $source,
                static function (array $path, string $token) use ($target, &$seen): int|CompanyBackupReferenceRemapDirective|null {
                    if ($path !== ['reference_submission_id']) {
                        return null;
                    }
                    $seen = true;
                    if ($token === 'null') {
                        if ($target !== null) {
                            throw self::invalid();
                        }
                        return null;
                    }
                    return $target ?? CompanyBackupReferenceRemapDirective::Defer;
                },
            );
        } catch (CompanyBackupDataSourceException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new CompanyBackupDataSourceException(
                'data_tax_submission_summary_invalid', 'table:tax_submissions', 'summary_json', $e,
            );
        }
        if (!$seen) {
            throw self::invalid();
        }
        return $result;
    }

    private static function invalid(): CompanyBackupDataSourceException
    {
        return new CompanyBackupDataSourceException(
            'data_tax_submission_summary_invalid', 'table:tax_submissions', 'summary_json',
        );
    }
}
