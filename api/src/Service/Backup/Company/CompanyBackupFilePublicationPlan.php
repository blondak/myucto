<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

use MyInvoice\Service\Backup\CanonicalJson;
use MyInvoice\Service\Backup\Registry\TenantDataDefinition;
use MyInvoice\Service\Backup\Registry\TenantDataRegistry;
use MyInvoice\Service\Backup\Registry\TenantDataRegistrySnapshot;

/** Neměnný plán převodu inventáře na živé cesty nového tenanta. */
final readonly class CompanyBackupFilePublicationPlan
{
    /** @var list<CompanyBackupFilePublicationEntry> */
    public array $entries;

    /** @var list<string> */
    private array $archivePaths;

    /** @var list<CompanyBackupInvoiceAttachmentFileBinding> */
    public array $invoiceAttachmentBindings;

    /**
     * @param list<CompanyBackupFilePublicationEntry> $entries
     * @param list<string> $archivePaths
     * @param list<CompanyBackupInvoiceAttachmentFileBinding> $invoiceAttachmentBindings
     */
    private function __construct(
        public string $registryFingerprint,
        public int $sourceSupplierId,
        public int $targetSupplierId,
        array $entries,
        array $archivePaths,
        array $invoiceAttachmentBindings,
        public string $bindingSha256,
    ) {
        $this->entries = $entries;
        $this->archivePaths = $archivePaths;
        $this->invoiceAttachmentBindings = $invoiceAttachmentBindings;
    }

    /** @param array<array-key,mixed> $invoiceAttachmentBindings */
    public static function fromInventory(
        CompanyBackupFileInventory $inventory,
        TenantDataRegistrySnapshot $targetRegistry,
        int $sourceSupplierId,
        int $targetSupplierId,
        array $invoiceAttachmentBindings = [],
    ): self {
        if ($targetRegistry->profile !== TenantDataRegistry::COMPANY_BACKUP_PROFILE
            || !hash_equals(
                $inventory->registryFingerprint,
                $targetRegistry->fingerprint,
            )
            || $sourceSupplierId < 1
            || $targetSupplierId < 1
        ) {
            throw self::error('file_restore_publication_context_mismatch');
        }

        [$bindingsBySource, $invoiceAttachmentBindings] = self::validateAttachmentBindings(
            $inventory, $targetRegistry, $invoiceAttachmentBindings,
        );
        $consumedBindings = [];
        $entries = [];
        $archivePaths = [];
        $targetPaths = [];
        foreach ($inventory->areas as $inventoryArea) {
            $definition = $targetRegistry->registry->definition(
                $inventoryArea->registryKey,
            );
            if (!$definition instanceof TenantDataDefinition) {
                throw self::error(
                    'file_restore_area_contract_mismatch',
                    $inventoryArea->registryKey,
                );
            }
            try {
                $area = CompanyBackupFileAreaProjection::fromDefinition(
                    $definition,
                    $targetRegistry->registry,
                );
            } catch (CompanyBackupFileSourceException $e) {
                throw self::error(
                    'file_restore_registry_contract_invalid',
                    $inventoryArea->registryKey,
                    $e,
                );
            }
            foreach ($inventoryArea->entries as $entry) {
                $targetInvoiceId = null;
                if ($area->pathPolicy === CompanyBackupFilePathPolicy::SupplierInvoiceAttachment) {
                    try {
                        $sourceInvoiceId = CompanyBackupInvoiceAttachmentFilePath::parseInvoiceId(
                            $entry->sourcePath, $sourceSupplierId,
                        );
                    } catch (\InvalidArgumentException) {
                        throw self::error('file_restore_attachment_path_invalid', $area->registryKey);
                    }
                    foreach ($entry->owners as $owner) {
                        if (!self::isAttachmentOwner($owner)) {
                            throw self::error('file_restore_attachment_owner_invalid', $area->registryKey);
                        }
                        $sourceAttachmentId = CompanyBackupInvoiceAttachmentFileBinding::canonicalPositiveId(
                            $owner['primary_key']['id'],
                        );
                        $binding = $sourceAttachmentId === null
                            ? null : ($bindingsBySource[$sourceAttachmentId] ?? null);
                        if ($binding === null
                            || $binding->sourceInvoiceId !== $sourceInvoiceId
                            || ($targetInvoiceId !== null
                                && $targetInvoiceId !== $binding->targetInvoiceId)
                            || isset($consumedBindings[$sourceAttachmentId])
                        ) {
                            throw self::error('file_restore_attachment_binding_mismatch', $area->registryKey);
                        }
                        $consumedBindings[$sourceAttachmentId] = true;
                        $targetInvoiceId = $binding->targetInvoiceId;
                    }
                    if ($targetInvoiceId === null) {
                        throw self::error('file_restore_attachment_owner_invalid', $area->registryKey);
                    }
                }
                try {
                    $targetPath = $area->pathPolicy->restoreTargetPath(
                        $entry->sourcePath,
                        $sourceSupplierId,
                        $targetSupplierId,
                        $targetInvoiceId,
                    );
                } catch (\InvalidArgumentException $e) {
                    throw self::error(
                        'file_restore_target_path_invalid',
                        $area->registryKey,
                        $e,
                    );
                }
                $targetSignature = strtolower(
                    $area->storageSubdirectory . '/' . $targetPath,
                );
                if (isset($targetPaths[$targetSignature])) {
                    throw self::error(
                        'file_restore_target_path_duplicate',
                        $area->registryKey,
                    );
                }
                $targetPaths[$targetSignature] = true;
                try {
                    $publication = new CompanyBackupFilePublicationEntry(
                        $area->registryKey,
                        $area->storageSubdirectory,
                        $entry->sourcePath,
                        $targetPath,
                        $entry->state,
                        $entry->archivePath,
                        $entry->bytes,
                        $entry->sha256,
                    );
                } catch (\InvalidArgumentException $e) {
                    throw self::error(
                        'file_restore_publication_entry_invalid',
                        $area->registryKey,
                        $e,
                    );
                }
                $entries[] = $publication;
                if ($publication->state === CompanyBackupFileState::Present) {
                    $archivePath = $publication->archivePath;
                    if (!is_string($archivePath)) {
                        throw new \LogicException(
                            'Existující soubor publication plánu nemá ZIP cestu.',
                        );
                    }
                    $archivePaths[$archivePath] = true;
                }
            }
        }
        if (count($consumedBindings) !== count($invoiceAttachmentBindings)) {
            throw self::error('file_restore_attachment_binding_extra');
        }
        $archivePaths = array_keys($archivePaths);
        sort($archivePaths, SORT_STRING);
        $bindingValue = [
            'format' => 'myucto-company-file-publication-plan',
            'version' => 1,
            'registry_fingerprint' => $targetRegistry->fingerprint,
            'source_supplier_id' => $sourceSupplierId,
            'target_supplier_id' => $targetSupplierId,
            'entries' => array_map(
                static fn (CompanyBackupFilePublicationEntry $entry): array =>
                    $entry->bindingValue(),
                $entries,
            ),
            ...($invoiceAttachmentBindings === [] ? [] : [
                'invoice_attachment_bindings' => array_map(
                    static fn (CompanyBackupInvoiceAttachmentFileBinding $binding): array =>
                        $binding->bindingValue(),
                    $invoiceAttachmentBindings,
                ),
            ]),
        ];
        return new self(
            $targetRegistry->fingerprint,
            $sourceSupplierId,
            $targetSupplierId,
            $entries,
            $archivePaths,
            $invoiceAttachmentBindings,
            hash('sha256', CanonicalJson::encode($bindingValue)),
        );
    }

    public function presentEntryCount(): int
    {
        return count(array_filter(
            $this->entries,
            static fn (CompanyBackupFilePublicationEntry $entry): bool =>
                $entry->state === CompanyBackupFileState::Present,
        ));
    }

    public function missingEntryCount(): int
    {
        return count($this->entries) - $this->presentEntryCount();
    }

    /** @return list<string> */
    public function archivePaths(): array
    {
        return $this->archivePaths;
    }

    /**
     * @param array<array-key,mixed> $bindings
     * @return array{array<int,CompanyBackupInvoiceAttachmentFileBinding>,list<CompanyBackupInvoiceAttachmentFileBinding>}
     */
    private static function validateAttachmentBindings(
        CompanyBackupFileInventory $inventory,
        TenantDataRegistrySnapshot $targetRegistry,
        array $bindings,
    ): array {
        $ownerCount = 0;
        foreach ($inventory->areas as $area) {
            $definition = $targetRegistry->registry->definition($area->registryKey);
            if (!$definition instanceof TenantDataDefinition) {
                throw self::error('file_restore_area_contract_mismatch', $area->registryKey);
            }
            try {
                $projection = CompanyBackupFileAreaProjection::fromDefinition(
                    $definition, $targetRegistry->registry,
                );
            } catch (CompanyBackupFileSourceException) {
                throw self::error('file_restore_registry_contract_invalid', $area->registryKey);
            }
            if ($projection->pathPolicy !== CompanyBackupFilePathPolicy::SupplierInvoiceAttachment) {
                continue;
            }
            foreach ($area->entries as $entry) {
                foreach ($entry->owners as $owner) {
                    if (!self::isAttachmentOwner($owner)) {
                        throw self::error('file_restore_attachment_owner_invalid', $area->registryKey);
                    }
                    $ownerCount++;
                }
            }
        }
        if (!array_is_list($bindings) || count($bindings) > $ownerCount) {
            throw self::error('file_restore_attachment_binding_invalid');
        }
        $bySource = [];
        $targetAttachments = [];
        $sourceInvoices = [];
        $targetInvoices = [];
        $canonicalBindings = [];
        foreach ($bindings as $binding) {
            if (!$binding instanceof CompanyBackupInvoiceAttachmentFileBinding
                || isset($bySource[$binding->sourceAttachmentId])
                || isset($targetAttachments[$binding->targetAttachmentId])
                || (isset($sourceInvoices[$binding->sourceInvoiceId])
                    && $sourceInvoices[$binding->sourceInvoiceId] !== $binding->targetInvoiceId)
                || (isset($targetInvoices[$binding->targetInvoiceId])
                    && $targetInvoices[$binding->targetInvoiceId] !== $binding->sourceInvoiceId)
            ) {
                throw self::error('file_restore_attachment_binding_invalid');
            }
            $bySource[$binding->sourceAttachmentId] = $binding;
            $targetAttachments[$binding->targetAttachmentId] = true;
            $sourceInvoices[$binding->sourceInvoiceId] = $binding->targetInvoiceId;
            $targetInvoices[$binding->targetInvoiceId] = $binding->sourceInvoiceId;
            $canonicalBindings[] = $binding;
        }
        usort($canonicalBindings, static fn (
            CompanyBackupInvoiceAttachmentFileBinding $a,
            CompanyBackupInvoiceAttachmentFileBinding $b,
        ): int => $a->sourceAttachmentId <=> $b->sourceAttachmentId);
        return [$bySource, $canonicalBindings];
    }

    /** @param array<string,mixed> $owner */
    private static function isAttachmentOwner(array $owner): bool
    {
        return ($owner['registry_key'] ?? null) === 'table:invoice_attachments'
            && ($owner['column'] ?? null) === 'filename'
            && ($owner['path'] ?? null) === []
            && array_keys($owner['primary_key'] ?? []) === ['id'];
    }

    private static function error(
        string $errorCode,
        string $registryKey = 'file-area:inventory',
        ?\Throwable $previous = null,
    ): CompanyBackupFileRestoreException {
        return new CompanyBackupFileRestoreException(
            $errorCode,
            $registryKey,
            $previous,
        );
    }
}
