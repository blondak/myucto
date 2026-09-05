<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

use MyInvoice\Service\Backup\Registry\TenantDataDefinition;
use PDO;

/**
 * Dvouprůchodová, read-only kontrola úplného zdrojového grafu JSONL.
 * První průchod naplní dočasný SQL index, druhý ověří každou referenci.
 */
final readonly class CompanyBackupDataPreflight
{
    public function __construct(
        private CompanyBackupArchiveLimits $limits = new CompanyBackupArchiveLimits(),
    ) {}

    public function inspect(
        string $archivePath,
        #[\SensitiveParameter] string $password,
        CompanyBackupTechnicalValidation $validation,
        PDO $database,
    ): CompanyBackupDataPreflightResult {
        $contexts = $this->contexts($validation);
        $source = new CompanyBackupImportArchiveSource(
            $archivePath,
            $password,
            $validation,
            $this->limits,
        );

        $index = null;
        $result = null;
        $failure = null;
        try {
            $index = new CompanyBackupSqlSourceIdentityIndex($database, $this->limits);
            $rowCount = 0;
            foreach ($validation->inspection->dataInventory->objects as $object) {
                $context = $contexts[$object->registryKey];
                $source->consumeRows(
                    $object->registryKey,
                    function (array $row) use (
                        $index,
                        $context,
                        &$rowCount,
                    ): void {
                        $index->add($context['identity']->identityForRow($row));
                        $rowCount++;
                    },
                );
            }
            $index->seal();

            $collector = new CompanyBackupExternalReferenceCollector($this->limits);
            $integrity = new CompanyBackupReferenceIntegrityValidator(
                $index,
                $this->limits,
            );
            $referenceOccurrenceCount = 0;
            foreach ($validation->inspection->dataInventory->objects as $object) {
                $source->consumeRows(
                    $object->registryKey,
                    static function (array $row): void {},
                    function (CompanyBackupReferenceOccurrence $occurrence) use (
                        $collector,
                        $integrity,
                        &$referenceOccurrenceCount,
                    ): void {
                        $referenceOccurrenceCount++;
                        if ($referenceOccurrenceCount
                            > $this->limits->maxReferenceOccurrences
                        ) {
                            throw new CompanyBackupPreflightException(
                                'source_reference_occurrence_limit_exceeded',
                                $occurrence->sourceRegistryKey,
                                $occurrence->sourceColumn,
                            );
                        }
                        $collector->accept($integrity->normalize($occurrence));
                    },
                );
            }

            $result = new CompanyBackupDataPreflightResult(
                $collector->finish(),
                $rowCount,
                $index->identityCount(),
                $index->entryCount(),
                $index->indexedBytes(),
                $referenceOccurrenceCount,
                $validation->targetRegistryFingerprint,
                $validation->bindingSha256,
            );
        } catch (\Throwable $e) {
            $failure = $e;
        }

        if ($index instanceof CompanyBackupSourceIdentityIndex) {
            try {
                $index->close();
            } catch (\Throwable $e) {
                $failure ??= $e;
            }
        }
        try {
            $source->close();
        } catch (\Throwable $e) {
            $failure ??= $e;
        }

        if ($failure instanceof \Throwable) {
            throw $failure;
        }
        if (!$result instanceof CompanyBackupDataPreflightResult) {
            throw new \LogicException('Datový preflight nevytvořil výsledek.');
        }

        return $result;
    }

    /**
     * @return array<string,array{
     *   definition:TenantDataDefinition,
     *   identity:CompanyBackupSourceIdentityProjection
     * }>
     */
    private function contexts(
        CompanyBackupTechnicalValidation $validation,
    ): array {
        $contexts = [];
        $expectedRows = 0;
        $sourceRegistry = $validation->inspection->sourceRegistry->registry;
        foreach ($validation->inspection->dataInventory->objects as $object) {
            $definition = $sourceRegistry->definition($object->registryKey);
            if (!$definition instanceof TenantDataDefinition) {
                throw new CompanyBackupPreflightException(
                    'source_registry_object_missing',
                    $object->registryKey,
                );
            }
            if ($object->rows
                > $this->limits->maxSourceIdentities - $expectedRows
            ) {
                throw new CompanyBackupPreflightException(
                    'source_identity_limit_exceeded',
                    $object->registryKey,
                );
            }
            try {
                $projection = CompanyBackupTableProjection::fromDefinition($definition);
                $projection->assertRegistryTargets($sourceRegistry);
            } catch (CompanyBackupDataSourceException $e) {
                throw new CompanyBackupPreflightException(
                    $e->errorCode,
                    $e->registryKey,
                    $e->column,
                    $e,
                );
            }
            $contexts[$object->registryKey] = [
                'definition' => $definition,
                'identity' => CompanyBackupSourceIdentityProjection::fromDefinition(
                    $definition,
                    $this->limits,
                ),
            ];
            $expectedRows += $object->rows;
        }
        return $contexts;
    }
}
