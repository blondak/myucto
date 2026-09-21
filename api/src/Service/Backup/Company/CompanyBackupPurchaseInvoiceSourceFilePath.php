<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

use MyInvoice\Service\Import\PurchaseInvoiceSourceFormat;

/** Original source artifact below purchase-invoices/sources/. */
final class CompanyBackupPurchaseInvoiceSourceFilePath
{
    public static function accepts(string $path, int $supplierId): bool
    {
        if (!str_starts_with($path, 'sources/')) {
            return false;
        }
        $relative = substr($path, strlen('sources/'));
        $extension = pathinfo($relative, PATHINFO_EXTENSION);
        if (!in_array($extension, PurchaseInvoiceSourceFormat::extensions(), true)) {
            return false;
        }
        $pdfShaped = substr($relative, 0, -strlen($extension)) . 'pdf';
        return CompanyBackupImportedInvoicePdfFilePath::accepts($pdfShaped, $supplierId);
    }

    public static function sourcePath(string $storedRelativePath, int $supplierId): string
    {
        if (!self::accepts($storedRelativePath, $supplierId)) {
            throw self::invalid();
        }
        return $storedRelativePath;
    }

    public static function restoreTargetPath(string $sourcePath, int $sourceSupplierId, int $targetSupplierId): string
    {
        if (!self::accepts($sourcePath, $sourceSupplierId) || $targetSupplierId < 1) {
            throw self::invalid();
        }
        $prefix = 'sources/supplier-' . $sourceSupplierId . '/';
        $target = 'sources/supplier-' . $targetSupplierId . '/' . substr($sourcePath, strlen($prefix));
        if (!self::accepts($target, $targetSupplierId)) {
            throw self::invalid();
        }
        return $target;
    }

    private static function invalid(): \InvalidArgumentException
    {
        return new \InvalidArgumentException('Cesta zdrojového souboru přijaté faktury není platná.');
    }
}
