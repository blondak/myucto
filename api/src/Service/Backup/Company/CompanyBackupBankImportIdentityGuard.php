<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/** Bez doložené externí identity nelze garantovat pokračování importu po obnově. */
final class CompanyBackupBankImportIdentityGuard
{
    /** @param array<string,mixed> $row */
    public static function assertRow(string $registryKey, array $row): void
    {
        if (!in_array($registryKey, ['table:bank_statements', 'table:bank_transactions'], true)) {
            return;
        }
        $source = $row['source'] ?? null;
        if (!in_array($source, ['email_notice', 'idoklad'], true)) {
            return;
        }
        $pattern = match ([$registryKey, $source]) {
            ['table:bank_transactions', 'email_notice'] => '/^email:[a-f0-9]{64}$/D',
            ['table:bank_transactions', 'idoklad'] => '/^idoklad:[1-9][0-9]*$/D',
            ['table:bank_statements', 'email_notice'] => '/^email-month:[a-f0-9]{64}$/D',
            default => '/^idoklad-month:[1-9][0-9]*:[0-9]{4}-(0[1-9]|1[0-2])$/D',
        };
        $identity = $row['external_identity'] ?? null;
        if (!is_string($identity) || preg_match($pattern, $identity) !== 1) {
            throw new CompanyBackupDataSourceException(
                'data_bank_external_identity_unresolved', $registryKey, 'external_identity',
            );
        }
    }
}
