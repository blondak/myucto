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

    /**
     * @param list<CompanyBackupFilePublicationEntry> $entries
     * @param list<string> $archivePaths
     */
    private function __construct(
        public string $registryFingerprint,
        public int $sourceSupplierId,
        public int $targetSupplierId,
        array $entries,
        array $archivePaths,
        public string $bindingSha256,
    ) {
        $this->entries = $entries;
        $this->archivePaths = $archivePaths;
    }

    public static function fromInventory(
        CompanyBackupFileInventory $inventory,
        TenantDataRegistrySnapshot $targetRegistry,
        int $sourceSupplierId,
        int $targetSupplierId,
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
                try {
                    $targetPath = $area->pathPolicy->restoreTargetPath(
                        $entry->sourcePath,
                        $sourceSupplierId,
                        $targetSupplierId,
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
        ];
        return new self(
            $targetRegistry->fingerprint,
            $sourceSupplierId,
            $targetSupplierId,
            $entries,
            $archivePaths,
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
