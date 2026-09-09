<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

use MyInvoice\Service\Backup\Registry\TenantDataDefinition;
use MyInvoice\Service\Backup\Registry\TenantDataPolicy;
use MyInvoice\Service\Backup\Registry\TenantDataRegistry;
use MyInvoice\Service\Backup\Registry\TenantDataRegistrySnapshot;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveData;
use PDO;

/**
 * Provede oba databázové průchody obnovy uvnitř již otevřené transakce.
 * Commit i rollback zůstává vyšší vrstvě, která později připojí soubory.
 */
final readonly class CompanyBackupDatabaseImporter implements CompanyBackupDatabaseImport
{
    public function __construct(
        private PDO $database,
        private CompanyBackupImportSchemaSource $schemas =
            new CompanyBackupTableSchemaReader(),
        private CompanyBackupArchiveLimits $limits =
            new CompanyBackupArchiveLimits(),
    ) {}

    public function restore(
        CompanyBackupImportSource $source,
        CompanyBackupDataPreflightResult $preflight,
        CompanyBackupReferenceDecisionPlan $decisions,
        PayrollSensitiveData $sensitiveData,
    ): CompanyBackupDatabaseImportResult {
        $this->assertTransaction('import_transaction_required');
        $sourceRegistry = $source->sourceRegistry();
        $targetRegistry = $source->targetRegistry();
        $inventory = $source->dataInventory();
        $fileInventory = $source->fileInventory();
        if ($sourceRegistry->profile !== TenantDataRegistry::COMPANY_BACKUP_PROFILE
            || $targetRegistry->profile
                !== TenantDataRegistry::COMPANY_BACKUP_PROFILE
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
                $targetRegistry->fingerprint,
            )
            || !hash_equals(
                $source->technicalValidationBindingSha256(),
                $preflight->technicalValidationBindingSha256,
            )
            || !hash_equals(
                $targetRegistry->fingerprint,
                $preflight->targetRegistryFingerprint,
            )
            || !hash_equals(
                $preflight->bindingSha256,
                $decisions->dataPreflightBindingSha256,
            )
            || !hash_equals(
                $targetRegistry->fingerprint,
                $decisions->targetRegistryFingerprint,
            )
        ) {
            throw self::error('import_context_mismatch');
        }

        $plan = CompanyBackupImportDependencyPlan::fromRegistry(
            $targetRegistry,
            $inventory,
        );
        $contexts = $this->contexts($targetRegistry, $inventory, $plan);
        if ($contexts['row_count'] !== $preflight->rowCount) {
            throw self::error('import_row_count_mismatch');
        }
        $payload = $source->secretPayload();
        if ($contexts['requires_secret_payload'] && $payload === null) {
            throw self::error('import_protected_secret_payload_missing');
        }
        $secrets = $payload === null
            ? null
            : new CompanyBackupProtectedSecretRestoreMaterializer(
                $payload,
                $targetRegistry,
                $sensitiveData,
                $this->limits,
            );

        $resolutions = (new CompanyBackupReferenceTargetResolver(
            $this->database,
            $this->limits,
            lockTargets: true,
        ))->resolve($decisions, $preflight, $targetRegistry);

        $manualConfiguration = CompanyBackupManualConfiguration::collect($source, $this->limits);
        $manualRows = $manualConfiguration->rowCount();
        $manualKeys = $manualConfiguration->sourceKeyCount;
        $identities = null;
        $hashes = null;
        $filePaths = null;
        $statutoryResults = null;
        $result = null;
        $failure = null;
        try {
            $identities = new CompanyBackupSqlTargetIdentityMap(
                $this->database,
                $this->limits,
            );
            $hashes = new CompanyBackupSqlTargetHashMap(
                $this->database,
                $this->limits,
            );
            $filePaths = new CompanyBackupSqlFilePathMap(
                $this->database,
                $fileInventory,
                $sourceRegistry,
                $targetRegistry,
                $this->limits,
            );
            $hashMapper = static fn (
                CompanyBackupEmbeddedHashReference $reference,
                string $hash,
            ): string => $hashes->resolve($reference, $hash);
            $hashReferenceMapper = static fn (
                CompanyBackupHashReference $reference,
                string $hash,
            ): string => $hashes->resolveHash($reference, $hash);

            $mappedGlobalRows = $this->mapGlobals(
                $source,
                $inventory,
                $targetRegistry,
                $plan,
                $resolutions,
                $identities,
            );
            $preallocatedRows = $this->preallocateIdentities(
                $source,
                $inventory,
                $contexts['tables'],
                $plan,
                $resolutions,
                $identities,
            );
            if ($identities->identityCount() + $manualRows !== $preflight->identityCount
                || $identities->entryCount() + $manualKeys !== $preflight->sourceKeyCount
                || $mappedGlobalRows + $preallocatedRows + $manualRows
                    !== $preflight->rowCount
            ) {
                throw self::error('import_identity_count_mismatch');
            }
            $identities->seal();
            $statutoryResults = $this->prepareStatutoryResults(
                $source,
                $inventory,
                $identities,
                $resolutions,
                $plan,
                $hashes,
                $hashMapper,
                $hashReferenceMapper,
            );
            [$insertedRows, $supplierId] = $this->insertRows(
                $source,
                $inventory,
                $contexts['tables'],
                $plan,
                $resolutions,
                $identities,
                $hashes,
                $hashMapper,
                $hashReferenceMapper,
                $secrets,
                $filePaths,
                $statutoryResults,
            );
            if ($insertedRows !== $preallocatedRows
                || $mappedGlobalRows + $insertedRows + $manualRows !== $preflight->rowCount
            ) {
                throw self::error('import_row_count_mismatch');
            }
            $statutoryResults?->finish();
            $hashes->seal();

            [$deferredRows, $updatedRows] = $this->updateDeferredRows(
                $source,
                $inventory,
                $contexts['tables'],
                $plan,
                $resolutions,
                $identities,
                $hashMapper,
                $hashReferenceMapper,
                $filePaths,
            );
            $filePaths->finish();
            foreach ($plan->dependencies() as $dependency) {
                if (CompanyBackupSupplierCurrencyCycle::matches($dependency)) {
                    CompanyBackupSupplierCurrencyCycle::assertComplete($this->database, $supplierId,
                        (new CompanyBackupTableSchemaReader())->readReferences($this->database,
                            $contexts['tables']['table:supplier']['projection']));
                    break;
                }
            }
            $protectedSecretCount = $secrets?->consumedValueCount() ?? 0;
            $secrets?->finish();
            $result = new CompanyBackupDatabaseImportResult(
                $supplierId,
                $mappedGlobalRows,
                $insertedRows,
                $deferredRows,
                $updatedRows,
                $identities->identityCount() + $manualRows,
                $identities->entryCount() + $manualKeys,
                $hashes->mappingCount(),
                $protectedSecretCount,
                $filePaths->publicationPlan(),
                $manualRows > 0 ? $manualConfiguration : null,
            );
        } catch (\Throwable $e) {
            $failure = $e;
        }

        if ($statutoryResults
            instanceof CompanyBackupPayrollStatutoryResultSetImportPreparer
        ) {
            try {
                $statutoryResults->close();
            } catch (\Throwable $e) {
                $failure ??= $e;
            }
        }
        if ($filePaths instanceof CompanyBackupSqlFilePathMap) {
            try {
                $filePaths->close();
            } catch (\Throwable $e) {
                $failure ??= $e;
            }
        }
        if ($hashes instanceof CompanyBackupSqlTargetHashMap) {
            try {
                $hashes->close();
            } catch (\Throwable $e) {
                $failure ??= $e;
            }
        }
        if ($identities instanceof CompanyBackupTargetIdentityMap) {
            try {
                $identities->close();
            } catch (\Throwable $e) {
                $failure ??= $e;
            }
        }
        if ($failure instanceof \Throwable) {
            throw $failure;
        }
        $this->assertTransaction('import_transaction_lost');
        if (!$result instanceof CompanyBackupDatabaseImportResult) {
            throw new \LogicException('Databázový import nevytvořil výsledek.');
        }
        return $result;
    }

    /**
     * @return array{
     *   tables:array<string,array{
     *     definition:TenantDataDefinition,
     *     projection:CompanyBackupTableProjection,
     *     deferred:CompanyBackupDeferredColumnSet
     *   }>,
     *   row_count:int,
     *   requires_secret_payload:bool
     * }
     */
    private function contexts(
        TenantDataRegistrySnapshot $registry,
        CompanyBackupDataInventory $inventory,
        CompanyBackupImportDependencyPlan $plan,
    ): array {
        $tables = [];
        $rowCount = 0;
        $requiresSecretPayload = false;
        foreach ($inventory->objects as $object) {
            $definition = $registry->registry->definition($object->registryKey);
            if (!$definition instanceof TenantDataDefinition) {
                throw self::error(
                    'import_registry_object_missing',
                    $object->registryKey,
                );
            }
            if ($object->rows > $this->limits->maxSourceIdentities - $rowCount) {
                throw self::error(
                    'import_row_count_limit_exceeded',
                    $object->registryKey,
                );
            }
            $rowCount += $object->rows;
            if (!$plan->containsInsertRegistryKey($object->registryKey)) {
                continue;
            }
            try {
                $projection = CompanyBackupTableProjection::fromDefinition(
                    $definition,
                );
                $projection->assertRegistryTargets($registry->registry);
                $deferred = CompanyBackupDeferredColumnSet::fromProjection(
                    $projection,
                    $plan,
                );
            } catch (CompanyBackupDataSourceException $e) {
                throw self::error(
                    'import_registry_contract_invalid',
                    $object->registryKey,
                    $e->column,
                    $e,
                );
            }
            $requiresSecretPayload = $requiresSecretPayload
                || $projection->requiredSecretEnvelopeColumn() !== null;
            $tables[$object->registryKey] = [
                'definition' => $definition,
                'projection' => $projection,
                'deferred' => $deferred,
            ];
        }
        $this->assertStableHashTargets($tables);
        return [
            'tables' => $tables,
            'row_count' => $rowCount,
            'requires_secret_payload' => $requiresSecretPayload,
        ];
    }

    /**
     * @param array<string,array{
     *   definition:TenantDataDefinition,
     *   projection:CompanyBackupTableProjection,
     *   deferred:CompanyBackupDeferredColumnSet
     * }> $tables
     */
    private function assertStableHashTargets(array $tables): void
    {
        foreach ($tables as $context) {
            foreach (
                [
                    ...$context['projection']->embeddedHashReferences->references,
                    ...$context['projection']->hashReferences->references,
                ]
                as $reference
            ) {
                $target = $tables[$reference->target] ?? null;
                if ($target === null
                    || in_array(
                        $reference->targetHashColumn,
                        $target['deferred']->columns,
                        true,
                    )
                ) {
                    throw self::error(
                        'import_hash_target_not_stable',
                        $reference->target,
                        $reference->targetHashColumn,
                    );
                }
            }
        }
    }

    private function mapGlobals(
        CompanyBackupImportSource $source,
        CompanyBackupDataInventory $inventory,
        TenantDataRegistrySnapshot $registry,
        CompanyBackupImportDependencyPlan $plan,
        CompanyBackupReferenceResolutionPlan $resolutions,
        CompanyBackupTargetIdentityMap $identities,
    ): int {
        $mapped = 0;
        foreach ($plan->globalRegistryKeys() as $registryKey) {
            $object = $inventory->object($registryKey);
            $definition = $registry->registry->definition($registryKey);
            if (!$object instanceof CompanyBackupDataObject
                || !$definition instanceof TenantDataDefinition
            ) {
                throw self::error('import_registry_object_missing', $registryKey);
            }
            $mapper = new CompanyBackupGlobalIdentityMapper(
                $definition,
                $identities,
                $resolutions,
                $plan,
                $this->limits,
            );
            $consumed = $source->consumeRows(
                $registryKey,
                static function (array $row) use ($mapper, &$mapped): void {
                    $mapper->map($row);
                    $mapped++;
                },
            );
            if ($consumed !== $object->rows) {
                throw self::error('import_row_count_mismatch', $registryKey);
            }
        }
        return $mapped;
    }

    /**
     * @param array<string,array{
     *   definition:TenantDataDefinition,
     *   projection:CompanyBackupTableProjection,
     *   deferred:CompanyBackupDeferredColumnSet
     * }> $tables
     */
    private function preallocateIdentities(
        CompanyBackupImportSource $source,
        CompanyBackupDataInventory $inventory,
        array $tables,
        CompanyBackupImportDependencyPlan $plan,
        CompanyBackupReferenceResolutionPlan $resolutions,
        CompanyBackupTargetIdentityMap $identities,
    ): int {
        $preallocated = 0;
        foreach ($plan->identityBatches() as $batch) {
            foreach ($batch as $registryKey) {
                $context = $tables[$registryKey] ?? null;
                $object = $inventory->object($registryKey);
                if ($context === null
                    || !$object instanceof CompanyBackupDataObject
                ) {
                    throw self::error(
                        'import_registry_object_missing',
                        $registryKey,
                    );
                }
                $definition = $context['definition'];
                $projection = $context['projection'];
                $metadata = $this->schemas->readImportMetadata(
                    $this->database,
                    $projection,
                );
                $reservation = $metadata->autoIncrement === null
                    ? null
                    : CompanyBackupSqlPrimaryKeyReservation::reserve(
                        $this->database,
                        $projection,
                        $metadata->autoIncrement,
                        $object->rows,
                        $this->limits,
                    );
                $preallocator = new CompanyBackupImportIdentityPreallocator(
                    $definition,
                    $metadata,
                    $reservation,
                    $identities,
                    $resolutions,
                    $plan,
                    $this->limits,
                );
                $consumed = $source->consumeRows(
                    $registryKey,
                    static function (array $row) use (
                        $preallocator,
                        &$preallocated,
                    ): void {
                        $preallocator->preallocate($row);
                        $preallocated++;
                    },
                );
                $preallocator->finish();
                if ($consumed !== $object->rows) {
                    throw self::error(
                        'import_row_count_mismatch',
                        $registryKey,
                    );
                }
            }
        }
        return $preallocated;
    }

    /**
     * @param callable(CompanyBackupEmbeddedHashReference,string):string $hashMapper
     * @param callable(CompanyBackupHashReference,string):string $hashReferenceMapper
     */
    private function prepareStatutoryResults(
        CompanyBackupImportSource $source,
        CompanyBackupDataInventory $inventory,
        CompanyBackupTargetIdentityMap $identities,
        CompanyBackupReferenceResolutionPlan $resolutions,
        CompanyBackupImportDependencyPlan $plan,
        CompanyBackupSqlTargetHashMap $hashes,
        callable $hashMapper,
        callable $hashReferenceMapper,
    ): ?CompanyBackupPayrollStatutoryResultSetImportPreparer {
        $present = 0;
        foreach ([
            CompanyBackupPayrollStatutoryResultSetAssembler::ROOT_REGISTRY_KEY,
            CompanyBackupPayrollStatutoryResultSetAssembler::PERSON_REGISTRY_KEY,
            CompanyBackupPayrollStatutoryResultSetAssembler::RELATIONSHIP_REGISTRY_KEY,
        ] as $registryKey) {
            if ($inventory->object($registryKey) !== null) {
                $present++;
            }
        }
        if ($present === 0) {
            return null;
        }
        if ($present !== 3) {
            throw self::error('import_statutory_result_set_context_invalid');
        }
        return CompanyBackupPayrollStatutoryResultSetImportPreparer::prepare(
            $this->database,
            $source,
            $identities,
            $resolutions,
            $plan,
            $hashes,
            $hashMapper,
            $hashReferenceMapper,
            $this->limits,
        );
    }

    /**
     * @param array<string,array{
     *   definition:TenantDataDefinition,
     *   projection:CompanyBackupTableProjection,
     *   deferred:CompanyBackupDeferredColumnSet
     * }> $tables
     * @param callable(CompanyBackupEmbeddedHashReference,string):string $hashMapper
     * @param callable(CompanyBackupHashReference,string):string $hashReferenceMapper
     * @return array{int,int}
     */
    private function insertRows(
        CompanyBackupImportSource $source,
        CompanyBackupDataInventory $inventory,
        array $tables,
        CompanyBackupImportDependencyPlan $plan,
        CompanyBackupReferenceResolutionPlan $resolutions,
        CompanyBackupTargetIdentityMap $identities,
        CompanyBackupSqlTargetHashMap $hashes,
        callable $hashMapper,
        callable $hashReferenceMapper,
        ?CompanyBackupProtectedSecretRestoreMaterializer $secrets,
        CompanyBackupSqlFilePathMap $filePaths,
        ?CompanyBackupPayrollStatutoryResultSetImportPreparer $statutoryResults,
    ): array {
        $insertedRows = 0;
        $supplierId = null;
        $supplierCurrencyCycle = false;
        foreach ($plan->dependencies() as $dependency) {
            if (CompanyBackupSupplierCurrencyCycle::matches($dependency)) {
                $supplierCurrencyCycle = true;
                break;
            }
        }
        foreach ($plan->insertBatches() as $batch) {
            foreach ($batch as $registryKey) {
                $context = $tables[$registryKey] ?? null;
                $object = $inventory->object($registryKey);
                if ($context === null
                    || !$object instanceof CompanyBackupDataObject
                ) {
                    throw self::error(
                        'import_registry_object_missing',
                        $registryKey,
                    );
                }
                $definition = $context['definition'];
                $projection = $context['projection'];
                $schema = $this->schemas->read($this->database, $projection);
                $preparer = new CompanyBackupPreallocatedImportRowPreparer(
                    $definition,
                    $identities,
                    $resolutions,
                    $plan,
                    $this->limits,
                    $filePaths,
                );
                $writer = new CompanyBackupSqlInsertWriter(
                    $this->database,
                    $definition,
                    $schema,
                    $object->rows,
                    $this->limits,
                );
                $consumed = $source->consumeRows(
                    $registryKey,
                    function (array $row) use (
                        $definition,
                        $projection,
                        $preparer,
                        $writer,
                        $supplierCurrencyCycle,
                        $hashes,
                        $hashMapper,
                        $hashReferenceMapper,
                        $secrets,
                        $statutoryResults,
                        &$insertedRows,
                        &$supplierId,
                    ): void {
                        $prepared = $preparer->prepare(
                            $row,
                            $hashMapper,
                            $hashReferenceMapper,
                        );
                        if ($statutoryResults !== null
                            && $definition->key
                                === CompanyBackupPayrollStatutoryResultSetAssembler::ROOT_REGISTRY_KEY
                        ) {
                            $prepared = $statutoryResults->resealRoot(
                                $row,
                                $prepared,
                            );
                        }
                        $protected = $secrets?->valuesFor(
                            $definition,
                            $row,
                            $prepared,
                        ) ?? [];
                        if ($supplierCurrencyCycle && $definition->key === 'table:supplier') {
                            CompanyBackupSupplierCurrencyCycle::insert($this->database,
                                static fn () => $writer->insert($prepared, $protected));
                        } else {
                            $writer->insert($prepared, $protected);
                        }
                        $hashes->addRow($projection, $row, $prepared->row);
                        if ($definition->policy === TenantDataPolicy::TenantRoot) {
                            $id = $prepared->targetIdentity->primaryKey->values['id']
                                ?? null;
                            if ($definition->key !== 'table:supplier'
                                || $projection->primaryKey !== ['id']
                                || !is_int($id)
                                || $id < 1
                                || $supplierId !== null
                            ) {
                                throw self::error(
                                    'import_supplier_identity_invalid',
                                    $definition->key,
                                );
                            }
                            $supplierId = $id;
                        }
                        $insertedRows++;
                    },
                );
                $writer->finish();
                if ($consumed !== $object->rows) {
                    throw self::error(
                        'import_row_count_mismatch',
                        $registryKey,
                    );
                }
            }
        }
        if (!is_int($supplierId)) {
            throw self::error(
                'import_supplier_identity_invalid',
                'table:supplier',
            );
        }
        return [$insertedRows, $supplierId];
    }

    /**
     * @param array<string,array{
     *   definition:TenantDataDefinition,
     *   projection:CompanyBackupTableProjection,
     *   deferred:CompanyBackupDeferredColumnSet
     * }> $tables
     * @param callable(CompanyBackupEmbeddedHashReference,string):string $hashMapper
     * @param callable(CompanyBackupHashReference,string):string $hashReferenceMapper
     * @return array{int,int}
     */
    private function updateDeferredRows(
        CompanyBackupImportSource $source,
        CompanyBackupDataInventory $inventory,
        array $tables,
        CompanyBackupImportDependencyPlan $plan,
        CompanyBackupReferenceResolutionPlan $resolutions,
        CompanyBackupTargetIdentityMap $identities,
        callable $hashMapper,
        callable $hashReferenceMapper,
        CompanyBackupSqlFilePathMap $filePaths,
    ): array {
        $processed = 0;
        $updated = 0;
        foreach ($plan->insertBatches() as $batch) {
            foreach ($batch as $registryKey) {
                $context = $tables[$registryKey] ?? null;
                $object = $inventory->object($registryKey);
                if ($context === null
                    || !$object instanceof CompanyBackupDataObject
                ) {
                    throw self::error(
                        'import_registry_object_missing',
                        $registryKey,
                    );
                }
                if ($context['deferred']->columns === []) {
                    continue;
                }
                $definition = $context['definition'];
                $schema = $this->schemas->read(
                    $this->database,
                    $context['projection'],
                );
                $preparer = new CompanyBackupDeferredRowPreparer(
                    $definition,
                    $identities,
                    $resolutions,
                    $plan,
                    $this->limits,
                    $filePaths,
                );
                $writer = new CompanyBackupSqlDeferredUpdateWriter(
                    $this->database,
                    $definition,
                    $schema,
                    $plan,
                    $object->rows,
                    $this->limits,
                );
                $consumed = $source->consumeRows(
                    $registryKey,
                    static function (array $row) use (
                        $preparer,
                        $writer,
                        $hashMapper,
                        $hashReferenceMapper,
                    ): void {
                        $writer->update($preparer->prepare(
                            $row,
                            $hashMapper,
                            $hashReferenceMapper,
                        ));
                    },
                );
                $writer->finish();
                if ($consumed !== $object->rows) {
                    throw self::error(
                        'import_row_count_mismatch',
                        $registryKey,
                    );
                }
                $processed += $writer->processedRows();
                $updated += $writer->updatedRows();
            }
        }
        return [$processed, $updated];
    }

    private function assertTransaction(string $errorCode): void
    {
        try {
            if ($this->database->inTransaction()) {
                return;
            }
        } catch (\Throwable $e) {
            throw self::error(
                'import_transaction_state_failed',
                previous: $e,
            );
        }
        throw self::error($errorCode);
    }

    private static function error(
        string $errorCode,
        string $registryKey = 'profile:company_backup',
        ?string $column = null,
        ?\Throwable $previous = null,
    ): CompanyBackupImportWriteException {
        return new CompanyBackupImportWriteException(
            $errorCode,
            $registryKey,
            $column,
            $previous,
        );
    }
}
