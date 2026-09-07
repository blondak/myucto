<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

use MyInvoice\Service\Backup\Registry\TenantDataDefinition;
use MyInvoice\Service\Backup\Registry\TenantDataObjectKind;
use MyInvoice\Service\Backup\Registry\TenantDataPolicy;
use MyInvoice\Service\Backup\Registry\TenantDataRegistry;
use PDO;

/**
 * Znovu přečte celý cílový tenant přes stejný registry-driven SQL zdroj jako
 * export. Tím před commitem ověří ownership, živé schéma, kodeky i pečetě.
 */
final readonly class CompanyBackupRegistryPostImportValidator implements
    CompanyBackupPostImportValidator
{
    private CompanyBackupPostImportInvariantRegistry $invariants;

    public function __construct(
        private CompanyBackupDataRowSource $rows = new CompanyBackupSqlRowSource(),
        ?CompanyBackupPostImportInvariantRegistry $invariants = null,
    ) {
        $this->invariants = $invariants
            ?? CompanyBackupPostImportInvariantRegistry::empty();
    }

    public function validate(
        PDO $database,
        CompanyBackupImportSource $source,
        CompanyBackupDataPreflightResult $preflight,
        CompanyBackupDatabaseImportResult $result,
    ): CompanyBackupPostImportValidationResult {
        self::assertTransaction($database);
        $sourceRegistry = $source->sourceRegistry();
        $registry = $source->targetRegistry();
        $inventory = $source->dataInventory();
        $fileInventory = $source->fileInventory();
        $publication = $result->filePublicationPlan;
        if ($sourceRegistry->profile
                !== TenantDataRegistry::COMPANY_BACKUP_PROFILE
            || $registry->profile !== TenantDataRegistry::COMPANY_BACKUP_PROFILE
            || !hash_equals(
                $sourceRegistry->fingerprint,
                $inventory->registryFingerprint,
            )
            || !hash_equals(
                $sourceRegistry->fingerprint,
                $fileInventory->registryFingerprint,
            )
            || !hash_equals(
                $sourceRegistry->fingerprint,
                $registry->fingerprint,
            )
            || !hash_equals(
                $registry->fingerprint,
                $inventory->registryFingerprint,
            )
            || !hash_equals(
                $registry->fingerprint,
                $fileInventory->registryFingerprint,
            )
            || !hash_equals(
                $registry->fingerprint,
                $preflight->targetRegistryFingerprint,
            )
            || !hash_equals(
                $registry->fingerprint,
                $publication->registryFingerprint,
            )
            || !hash_equals(
                $source->technicalValidationBindingSha256(),
                $preflight->technicalValidationBindingSha256,
            )
            || $publication->targetSupplierId !== $result->supplierId
            || $result->identityCount !== $preflight->identityCount
            || $result->sourceKeyCount !== $preflight->sourceKeyCount
        ) {
            throw self::error('post_import_context_mismatch');
        }
        try {
            $expectedPublication = CompanyBackupFilePublicationPlan::fromInventory(
                $fileInventory,
                $registry,
                $publication->sourceSupplierId,
                $result->supplierId,
            );
        } catch (CompanyBackupFileRestoreException $e) {
            throw self::error(
                'post_import_publication_plan_invalid',
                previous: $e,
            );
        }
        if (!hash_equals(
            $expectedPublication->bindingSha256,
            $publication->bindingSha256,
        )) {
            throw self::error('post_import_publication_plan_mismatch');
        }

        $checkedTables = 0;
        $checkedTenantRows = 0;
        $mappedGlobalRows = 0;
        foreach ($inventory->objects as $object) {
            $accountedRows = $checkedTenantRows + $mappedGlobalRows;
            if ($accountedRows > $preflight->rowCount
                || $object->rows > $preflight->rowCount - $accountedRows
            ) {
                throw self::error(
                    'post_import_row_count_mismatch',
                    $object->registryKey,
                );
            }
            $definition = $registry->registry->definition($object->registryKey);
            if (!$definition instanceof TenantDataDefinition
                || $definition->kind !== TenantDataObjectKind::Table
                || !$definition->policy->hasMachineDataPayload()
            ) {
                throw self::error(
                    'post_import_registry_contract_invalid',
                    $object->registryKey,
                );
            }
            if ($definition->policy === TenantDataPolicy::GlobalReference) {
                $mappedGlobalRows += $object->rows;
                continue;
            }
            if (!in_array($definition->policy, [
                TenantDataPolicy::TenantRoot,
                TenantDataPolicy::TenantOwned,
                TenantDataPolicy::TenantOwnedIndirect,
            ], true)) {
                throw self::error(
                    'post_import_registry_contract_invalid',
                    $object->registryKey,
                );
            }

            $actualRows = 0;
            try {
                foreach ($this->rows->rows(
                    $database,
                    $result->supplierId,
                    $definition,
                ) as $_row) {
                    $actualRows++;
                    if ($actualRows > $object->rows) {
                        throw self::error(
                            'post_import_row_count_mismatch',
                            $object->registryKey,
                        );
                    }
                }
            } catch (CompanyBackupPostImportException $e) {
                throw $e;
            } catch (CompanyBackupDataSourceException $e) {
                throw self::error(
                    'post_import_row_validation_failed',
                    $object->registryKey,
                    $e,
                );
            } catch (\Throwable $e) {
                throw self::error(
                    'post_import_row_read_failed',
                    $object->registryKey,
                    $e,
                );
            }
            self::assertTransaction($database, $object->registryKey);
            if ($actualRows !== $object->rows) {
                throw self::error(
                    'post_import_row_count_mismatch',
                    $object->registryKey,
                );
            }
            $checkedTables++;
            $checkedTenantRows += $actualRows;
        }

        $presentFiles = 0;
        $missingFiles = 0;
        foreach ($fileInventory->areas as $area) {
            foreach ($area->entries as $entry) {
                if ($entry->state === CompanyBackupFileState::Present) {
                    $presentFiles++;
                } else {
                    $missingFiles++;
                }
            }
        }
        if ($checkedTenantRows !== $result->insertedRows
            || $mappedGlobalRows !== $result->mappedGlobalRows
            || $checkedTenantRows + $mappedGlobalRows !== $preflight->rowCount
            || $presentFiles !== $publication->presentEntryCount()
            || $missingFiles !== $publication->missingEntryCount()
            || count($publication->entries) !== $presentFiles + $missingFiles
        ) {
            throw self::error('post_import_count_mismatch');
        }
        self::assertTransaction($database);
        $invariantReport = $this->invariants->validate(
            $database,
            $result->supplierId,
            $registry,
        );
        self::assertTransaction($database);
        return new CompanyBackupPostImportValidationResult(
            $result->supplierId,
            $registry->fingerprint,
            $preflight->bindingSha256,
            $publication->bindingSha256,
            $invariantReport,
            $checkedTables,
            $checkedTenantRows,
            $mappedGlobalRows,
            $presentFiles,
            $missingFiles,
        );
    }

    /** @phpstan-impure SQL reader ani validator nesmí ukončit transakci. */
    private static function assertTransaction(
        PDO $database,
        ?string $registryKey = null,
    ): void {
        if (!$database->inTransaction()) {
            throw self::error('post_import_transaction_lost', $registryKey);
        }
    }

    private static function error(
        string $errorCode,
        ?string $registryKey = null,
        ?\Throwable $previous = null,
    ): CompanyBackupPostImportException {
        return new CompanyBackupPostImportException(
            $errorCode,
            $registryKey,
            $previous,
        );
    }
}
