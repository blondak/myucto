<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

final class CompanyBackupFileBasename
{
    public static function validate(mixed $filename): string
    {
        if (!is_string($filename)
            || strlen($filename) > 255
            || str_contains($filename, '/')
            || str_contains($filename, '\\')
            || str_contains($filename, ':')
            || preg_match('/[\x00-\x1F\x7F"<>|*?]/', $filename) === 1
            || str_ends_with($filename, '.')
            || str_ends_with($filename, ' ')
            || preg_match('/\A(?:CON|PRN|AUX|NUL|CONIN\$|CONOUT\$|COM[1-9¹²³]|LPT[1-9¹²³])(?:\.|\z)/iuD', $filename) === 1
        ) {
            throw new \InvalidArgumentException('Název souboru není platný.');
        }
        CompanyBackupFileEntry::normalizeSourcePath($filename);
        return $filename;
    }
}
