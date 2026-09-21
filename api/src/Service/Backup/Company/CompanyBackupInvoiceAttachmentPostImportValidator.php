<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

use MyInvoice\Service\Backup\Registry\TenantDataDefinition;
use MyInvoice\Service\Backup\Registry\TenantDataRegistry;
use MyInvoice\Service\Backup\Registry\TenantDataRegistrySnapshot;
use PDO;
use PDOStatement;

/** Kontroluje cílové DB přílohy podle neměnného publikačního plánu. */
final class CompanyBackupInvoiceAttachmentPostImportValidator
{
    public static function assertValid(
        PDO $database,
        CompanyBackupFileInventory $inventory,
        TenantDataRegistrySnapshot $registry,
        CompanyBackupFilePublicationPlan $publication,
        int $targetSupplierId,
    ): void {
        if (!self::transactionActive($database) || $targetSupplierId < 1
            || $publication->targetSupplierId !== $targetSupplierId
            || $registry->profile !== TenantDataRegistry::COMPANY_BACKUP_PROFILE
            || !hash_equals($registry->fingerprint, $inventory->registryFingerprint)
            || !hash_equals($registry->fingerprint, $publication->registryFingerprint)
        ) {
            throw self::error('post_import_attachment_context_invalid');
        }
        if ($publication->invoiceAttachmentBindings === []) {
            return;
        }
        $filenames = [];
        foreach ($inventory->areas as $area) {
            $definition = $registry->registry->definition($area->registryKey);
            if (!$definition instanceof TenantDataDefinition) {
                throw self::error('post_import_attachment_inventory_invalid');
            }
            try {
                $projection = CompanyBackupFileAreaProjection::fromDefinition(
                    $definition, $registry->registry,
                );
            } catch (CompanyBackupFileSourceException) {
                throw self::error('post_import_attachment_inventory_invalid');
            }
            if ($projection->pathPolicy !== CompanyBackupFilePathPolicy::SupplierInvoiceAttachment) {
                continue;
            }
            foreach ($area->entries as $entry) {
                try {
                    $filename = CompanyBackupInvoiceAttachmentFilePath::storedFilename(
                        $entry->sourcePath, $publication->sourceSupplierId,
                    );
                } catch (\InvalidArgumentException) {
                    throw self::error('post_import_attachment_inventory_invalid');
                }
                foreach ($entry->owners as $owner) {
                    $id = CompanyBackupInvoiceAttachmentFileBinding::canonicalPositiveId(
                        $owner['primary_key']['id'] ?? null,
                    );
                    if ($id === null || isset($filenames[$id])) {
                        throw self::error('post_import_attachment_inventory_invalid');
                    }
                    $filenames[$id] = $filename;
                }
            }
        }
        try {
            $statement = $database->prepare(
                'SELECT a.id, a.invoice_id, a.filename'
                . ' FROM invoice_attachments AS a'
                . ' JOIN invoices AS i ON i.id = a.invoice_id'
                . ' WHERE a.id = ? AND i.supplier_id = ?',
            );
            if (!$statement instanceof PDOStatement) {
                throw self::error('post_import_attachment_query_failed');
            }
            foreach ($publication->invoiceAttachmentBindings as $binding) {
                $expectedFilename = $filenames[$binding->sourceAttachmentId] ?? null;
                if (!is_string($expectedFilename)) {
                    throw self::error('post_import_attachment_inventory_invalid');
                }
                if (!$statement->execute([$binding->targetAttachmentId, $targetSupplierId])) {
                    throw self::error('post_import_attachment_query_failed');
                }
                $row = $statement->fetch(PDO::FETCH_ASSOC);
                if (!$statement->closeCursor()) {
                    throw self::error('post_import_attachment_query_failed');
                }
                if (!is_array($row)
                    || CompanyBackupInvoiceAttachmentFileBinding::canonicalPositiveId($row['id'] ?? null)
                        !== $binding->targetAttachmentId
                    || CompanyBackupInvoiceAttachmentFileBinding::canonicalPositiveId($row['invoice_id'] ?? null)
                        !== $binding->targetInvoiceId
                    || !is_string($row['filename'] ?? null)
                    || $row['filename'] !== $expectedFilename
                ) {
                    throw self::error('post_import_attachment_target_mismatch');
                }
            }
        } catch (CompanyBackupPostImportException $e) {
            throw $e;
        } catch (\Throwable) {
            // Driver message/errorInfo mohou obsahovat hodnoty; previous se nepřenáší.
            throw self::error('post_import_attachment_query_failed');
        }
        if (!self::transactionActive($database)) {
            throw self::error('post_import_attachment_transaction_lost');
        }
    }

    /** @phpstan-impure Volaný SQL může změnit stav volající transakce. */
    private static function transactionActive(PDO $database): bool
    {
        try {
            return $database->inTransaction();
        } catch (\Throwable) {
            throw self::error('post_import_attachment_transaction_state_failed');
        }
    }

    private static function error(string $code): CompanyBackupPostImportException
    {
        return new CompanyBackupPostImportException($code, 'table:invoice_attachments');
    }
}
