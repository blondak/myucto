<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/** Path stored verbatim in invoices.imported_pdf_path. */
final class CompanyBackupImportedInvoicePdfFilePath
{
    public static function accepts(string $path, int $supplierId): bool
    {
        if ($supplierId < 1) {
            return false;
        }
        try {
            CompanyBackupFileEntry::normalizeSourcePath($path);
        } catch (\InvalidArgumentException) {
            return false;
        }
        $prefix = 'supplier-' . $supplierId . '/';
        if (!str_starts_with($path, $prefix)) {
            return false;
        }
        $relative = substr($path, strlen($prefix));
        if (preg_match('/^(?:([0-9a-f]{2})\/)?([0-9a-f]{16})\.pdf$/D', $relative, $matches) !== 1) {
            return false;
        }
        return $matches[1] === ''
            || hash_equals($matches[1], substr($matches[2], 0, 2));
    }

    public static function sourcePath(string $storedRelativePath, int $supplierId): string
    {
        if (!self::accepts($storedRelativePath, $supplierId)) {
            throw self::invalid();
        }
        return $storedRelativePath;
    }

    public static function restoreTargetPath(
        string $sourcePath,
        int $sourceSupplierId,
        int $targetSupplierId,
    ): string {
        if (!self::accepts($sourcePath, $sourceSupplierId) || $targetSupplierId < 1) {
            throw self::invalid();
        }
        $prefix = 'supplier-' . $sourceSupplierId . '/';
        $target = 'supplier-' . $targetSupplierId . '/' . substr($sourcePath, strlen($prefix));
        if (!self::accepts($target, $targetSupplierId)) {
            throw self::invalid();
        }
        return $target;
    }

    private static function invalid(): \InvalidArgumentException
    {
        return new \InvalidArgumentException('Cesta importovaného PDF faktury není platná.');
    }
}
