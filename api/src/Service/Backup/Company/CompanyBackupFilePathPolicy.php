<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

use MyInvoice\Service\Backup\Registry\TenantDataDefinition;
use MyInvoice\Service\Backup\Registry\TenantDataObjectKind;
use MyInvoice\Service\Backup\Registry\TenantDataRegistry;
use MyInvoice\Service\Mail\SafeLogoPath;

/** Tenantově specifické omezení cesty uvnitř registrované souborové oblasti. */
enum CompanyBackupFilePathPolicy: string
{
    case Relative = 'relative';
    case SupplierContentHash = 'supplier_content_hash';
    case SupplierLogo = 'supplier_logo';
    case SupplierInvoiceAttachment = 'supplier_invoice_attachment';
    case SupplierInvoicePdf = 'supplier_invoice_pdf';
    case SupplierImportedInvoicePdf = 'supplier_imported_invoice_pdf';
    case SupplierPurchaseInvoicePdf = 'supplier_purchase_invoice_pdf';
    case SupplierPurchaseInvoiceSource = 'supplier_purchase_invoice_source';

    public static function fromDefinition(TenantDataDefinition $definition): self
    {
        $value = $definition->details['path_policy'] ?? null;
        $policy = is_string($value) ? self::tryFrom($value) : null;
        if ($definition->kind !== TenantDataObjectKind::FileArea
            || !$definition->hasProfile(TenantDataRegistry::COMPANY_BACKUP_PROFILE)
            || $policy === null
        ) {
            throw new \InvalidArgumentException(
                'Souborová oblast ' . $definition->key . ' nemá platnou path_policy.',
            );
        }
        return $policy;
    }

    public function accepts(string $sourcePath, int $supplierId): bool
    {
        return match ($this) {
            self::SupplierImportedInvoicePdf, self::SupplierPurchaseInvoicePdf => CompanyBackupImportedInvoicePdfFilePath::accepts($sourcePath, $supplierId),
            self::SupplierPurchaseInvoiceSource => CompanyBackupPurchaseInvoiceSourceFilePath::accepts($sourcePath, $supplierId),
            self::Relative => true,
            self::SupplierContentHash => self::contentHash(
                $sourcePath,
                $supplierId,
            ) !== null,
            self::SupplierLogo => SafeLogoPath::isAllowedSourcePath(
                $sourcePath,
                $supplierId,
            ),
            self::SupplierInvoiceAttachment => CompanyBackupInvoiceAttachmentFilePath::accepts(
                $sourcePath,
                $supplierId,
            ),
            self::SupplierInvoicePdf => CompanyBackupInvoicePdfFilePath::accepts(
                $sourcePath,
                $supplierId,
            ),
        };
    }

    public function sourcePath(
        string $storedRelativePath,
        int $supplierId,
        ?int $invoiceId = null,
    ): string {
        $storedRelativePath = CompanyBackupFileEntry::normalizeSourcePath(
            $storedRelativePath,
        );
        if ($this === self::SupplierImportedInvoicePdf || $this === self::SupplierPurchaseInvoicePdf) {
            return CompanyBackupImportedInvoicePdfFilePath::sourcePath($storedRelativePath, $supplierId);
        }
        if ($this === self::SupplierPurchaseInvoiceSource) {
            return CompanyBackupPurchaseInvoiceSourceFilePath::sourcePath($storedRelativePath, $supplierId);
        }
        if ($this === self::SupplierInvoiceAttachment) {
            if ($invoiceId === null) {
                throw new \InvalidArgumentException('ID faktury pro cestu přílohy chybí.');
            }
            return CompanyBackupInvoiceAttachmentFilePath::sourcePath(
                $storedRelativePath, $supplierId, $invoiceId,
            );
        }
        if ($this === self::SupplierInvoicePdf) {
            return CompanyBackupInvoicePdfFilePath::sourcePath(
                $storedRelativePath, $supplierId,
            );
        }
        if ($this === self::SupplierContentHash) {
            if ($supplierId < 1
                || preg_match('/^[0-9a-f]{64}$/D', $storedRelativePath) !== 1
            ) {
                throw new \InvalidArgumentException(
                    'Content-addressed klíč souboru není platný.',
                );
            }
            return 'sup-' . $supplierId . '/'
                . substr($storedRelativePath, 0, 2) . '/'
                . $storedRelativePath;
        }
        return $storedRelativePath;
    }

    public function storedRelativePath(
        string $sourcePath,
        int $supplierId,
    ): string {
        $sourcePath = CompanyBackupFileEntry::normalizeSourcePath($sourcePath);
        if ($supplierId < 1 || !$this->accepts($sourcePath, $supplierId)) {
            throw new \InvalidArgumentException(
                'Zdrojová cesta souboru neodpovídá obnovované firmě.',
            );
        }
        if ($this === self::SupplierInvoiceAttachment) {
            return CompanyBackupInvoiceAttachmentFilePath::storedFilename(
                $sourcePath, $supplierId,
            );
        }
        if ($this === self::SupplierInvoicePdf) {
            return CompanyBackupInvoicePdfFilePath::storedFilename(
                $sourcePath, $supplierId,
            );
        }
        if ($this !== self::SupplierContentHash) {
            return $sourcePath;
        }
        $hash = self::contentHash($sourcePath, $supplierId);
        if (!is_string($hash)) {
            throw new \InvalidArgumentException(
                'Content-addressed cesta souboru není platná.',
            );
        }
        return $hash;
    }

    public function expectedContentSha256(
        string $sourcePath,
        int $supplierId,
    ): ?string {
        return $this === self::SupplierContentHash
            ? $this->storedRelativePath($sourcePath, $supplierId)
            : null;
    }

    public function restoreTargetPath(
        string $sourcePath,
        int $sourceSupplierId,
        int $targetSupplierId,
        ?int $targetInvoiceId = null,
    ): string {
        $sourcePath = CompanyBackupFileEntry::normalizeSourcePath($sourcePath);
        if ($sourceSupplierId < 1
            || $targetSupplierId < 1
            || !$this->accepts($sourcePath, $sourceSupplierId)
        ) {
            throw new \InvalidArgumentException(
                'Zdrojová cesta souboru neodpovídá obnovované firmě.',
            );
        }
        if ($this === self::SupplierImportedInvoicePdf || $this === self::SupplierPurchaseInvoicePdf) {
            return CompanyBackupImportedInvoicePdfFilePath::restoreTargetPath(
                $sourcePath, $sourceSupplierId, $targetSupplierId,
            );
        }
        if ($this === self::SupplierPurchaseInvoiceSource) {
            return CompanyBackupPurchaseInvoiceSourceFilePath::restoreTargetPath(
                $sourcePath, $sourceSupplierId, $targetSupplierId,
            );
        }
        if ($this === self::SupplierInvoiceAttachment) {
            if ($targetInvoiceId === null) {
                throw new \InvalidArgumentException('Cílové ID faktury pro přílohu chybí.');
            }
            return CompanyBackupInvoiceAttachmentFilePath::restoreTargetPath(
                $sourcePath, $sourceSupplierId, $targetSupplierId, $targetInvoiceId,
            );
        }
        if ($this === self::SupplierInvoicePdf) {
            return CompanyBackupInvoicePdfFilePath::restoreTargetPath(
                $sourcePath, $sourceSupplierId, $targetSupplierId,
            );
        }
        if ($this === self::Relative) {
            return $sourcePath;
        }
        if ($this === self::SupplierContentHash) {
            return $this->sourcePath(
                $this->storedRelativePath($sourcePath, $sourceSupplierId),
                $targetSupplierId,
            );
        }

        $sourcePrefix = 'sup-' . $sourceSupplierId;
        if (!str_starts_with($sourcePath, $sourcePrefix)) {
            throw new \InvalidArgumentException(
                'Zdrojová cesta loga nemá tenantový prefix.',
            );
        }
        $targetPath = 'sup-' . $targetSupplierId
            . substr($sourcePath, strlen($sourcePrefix));
        if (!$this->accepts($targetPath, $targetSupplierId)) {
            throw new \InvalidArgumentException(
                'Cílovou cestu loga nelze bezpečně odvodit.',
            );
        }
        return $targetPath;
    }

    private static function contentHash(
        string $sourcePath,
        int $supplierId,
    ): ?string {
        if ($supplierId < 1) {
            return null;
        }
        $prefix = 'sup-' . $supplierId . '/';
        if (!str_starts_with($sourcePath, $prefix)) {
            return null;
        }
        $relative = substr($sourcePath, strlen($prefix));
        if (preg_match(
            '/^([0-9a-f]{2})\/([0-9a-f]{64})$/D',
            $relative,
            $matches,
        ) !== 1 || !hash_equals($matches[1], substr($matches[2], 0, 2))) {
            return null;
        }
        return $matches[2];
    }
}
