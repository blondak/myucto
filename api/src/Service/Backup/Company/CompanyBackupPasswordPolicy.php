<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/** Jediný vstupní kontrakt hesla pro frontu i AES-256 ZIP writer. */
final class CompanyBackupPasswordPolicy
{
    public const MIN_BYTES = 12;
    public const MAX_BYTES = 1_024;

    public static function assertValid(#[\SensitiveParameter] string $password): void
    {
        $bytes = strlen($password);
        if ($bytes < self::MIN_BYTES
            || $bytes > self::MAX_BYTES
            || str_contains($password, "\0")
        ) {
            throw new CompanyBackupArchiveWriteException('archive_password_weak');
        }
    }
}
