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
    private const STATUTORY_ROOT =
        CompanyBackupPayrollStatutoryResultSetAssembler::ROOT_REGISTRY_KEY;
    private const STATUTORY_PERSON =
        CompanyBackupPayrollStatutoryResultSetAssembler::PERSON_REGISTRY_KEY;
    private const STATUTORY_RELATIONSHIP =
        CompanyBackupPayrollStatutoryResultSetAssembler::RELATIONSHIP_REGISTRY_KEY;

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
        $aggregateIndex = null;
        $result = null;
        $failure = null;
        try {
            $index = new CompanyBackupSqlSourceIdentityIndex($database, $this->limits);
            $aggregateIndex = $this->usesStatutoryResultAggregate($validation)
                ? new CompanyBackupPayrollStatutoryResultSetSourceIndex(
                    $database,
                    $this->limits,
                )
                : null;
            $aggregateRootCount = 0;
            $rowCount = 0;
            $bankAccountCollision = false;
            foreach ($validation->inspection->dataInventory->objects as $object) {
                $context = $contexts[$object->registryKey];
                $source->consumeRows(
                    $object->registryKey,
                    function (array $row) use (
                        $index,
                        $context,
                        $object,
                        $aggregateIndex,
                        &$aggregateRootCount,
                        &$rowCount,
                        &$bankAccountCollision,
                        $database,
                    ): void {
                        $index->add($context['identity']->identityForRow($row));
                        CompanyBackupSubmissionCorrelationGuard::assertAvailable($database, $object->registryKey, $row);
                        if (!$bankAccountCollision && $object->registryKey === 'table:currencies') {
                            $account = $row['account_number'] ?? null;
                            $iban = $row['iban'] ?? null;
                            $bankAccountCollision = \MyInvoice\Repository\BankStatementOwnershipResolver::accountClaimedByOtherSupplierIn(
                                $database, 0, is_string($account) ? $account : null,
                                is_string($iban) ? $iban : null,
                            );
                        }
                        if ($aggregateIndex !== null) {
                            if ($object->registryKey === self::STATUTORY_PERSON) {
                                $aggregateIndex->addPerson($row);
                            } elseif ($object->registryKey
                                === self::STATUTORY_RELATIONSHIP
                            ) {
                                $aggregateIndex->addRelationship($row);
                            } elseif ($object->registryKey === self::STATUTORY_ROOT) {
                                $aggregateRootCount++;
                            }
                        }
                        $rowCount++;
                    },
                );
            }
            $index->seal();
            $aggregateIndex?->seal();

            $collector = new CompanyBackupExternalReferenceCollector($this->limits);
            $integrity = new CompanyBackupReferenceIntegrityValidator(
                $index,
                $this->limits,
            );
            $referenceOccurrenceCount = 0;
            $skippedCounters = [];
            foreach ($validation->inspection->dataInventory->objects as $object) {
                if ($object->registryKey === CompanyBackupInvoiceCounterKey::REGISTRY_KEY) {
                    $definition = $validation->inspection->sourceRegistry->registry->definition($object->registryKey);
                    if ($definition === null) {
                        throw new CompanyBackupPreflightException('counter_skip_contract_mismatch', $object->registryKey);
                    }
                    CompanyBackupSkippedInvoiceCounters::assertDefinition($definition);
                    $projection = CompanyBackupTableProjection::fromDefinition($definition);
                    $source->consumeRows($object->registryKey, function (array $row) use (
                        $index, $integrity, $collector, $projection, &$skippedCounters, &$referenceOccurrenceCount,
                    ): void {
                        $orphan = CompanyBackupSkippedInvoiceCounters::isOrphan($row, $index);
                        // Kořen firmy se ověřuje i u vynechané řady; výjimka se týká jen dvou os.
                        $projection->visitSourceReferences($row, function (CompanyBackupReferenceOccurrence $occurrence) use (
                            $orphan, $integrity, $collector, &$referenceOccurrenceCount,
                        ): void {
                            if ($orphan && $occurrence->sourceColumn !== 'supplier_id') {
                                return;
                            }
                            if (++$referenceOccurrenceCount > $this->limits->maxReferenceOccurrences) {
                                throw new CompanyBackupPreflightException('source_reference_occurrence_limit_exceeded', $occurrence->sourceRegistryKey);
                            }
                            $collector->accept($integrity->normalize($occurrence));
                        });
                        if ($orphan) {
                            if (count($skippedCounters) >= CompanyBackupSkippedInvoiceCounters::MAX_ROWS) {
                                throw new CompanyBackupPreflightException('counter_skip_limit_exceeded', CompanyBackupInvoiceCounterKey::REGISTRY_KEY);
                            }
                            $skippedCounters[] = CompanyBackupSourceKey::fromRow(CompanyBackupInvoiceCounterKey::REGISTRY_KEY,
                                CompanyBackupInvoiceCounterKey::COLUMNS, $row);
                        }
                    });
                    continue;
                }
                $source->consumeRows(
                    $object->registryKey,
                    static function (array $row) use (
                        $object,
                        $aggregateIndex,
                    ): void {
                        if ($aggregateIndex !== null
                            && $object->registryKey === self::STATUTORY_ROOT
                        ) {
                            $aggregateIndex->assertSourceHeader($row);
                        }
                    },
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
            $aggregateIndex?->finish($aggregateRootCount);

            $result = new CompanyBackupDataPreflightResult(
                $collector->finish(),
                $rowCount,
                $index->identityCount(),
                $index->entryCount(),
                $index->indexedBytes(),
                $referenceOccurrenceCount,
                $validation->targetRegistryFingerprint,
                $validation->bindingSha256,
                $bankAccountCollision,
                new CompanyBackupSkippedInvoiceCounters($skippedCounters),
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
        if ($aggregateIndex
            instanceof CompanyBackupPayrollStatutoryResultSetSourceIndex
        ) {
            try {
                $aggregateIndex->close();
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

    private function usesStatutoryResultAggregate(
        CompanyBackupTechnicalValidation $validation,
    ): bool {
        $inventory = $validation->inspection->dataInventory;
        foreach ([
            self::STATUTORY_ROOT,
            self::STATUTORY_PERSON,
            self::STATUTORY_RELATIONSHIP,
        ] as $registryKey) {
            if ($inventory->object($registryKey) !== null) {
                return true;
            }
        }
        return false;
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
