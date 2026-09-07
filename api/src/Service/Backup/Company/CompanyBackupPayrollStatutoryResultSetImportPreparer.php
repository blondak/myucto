<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

use MyInvoice\Service\Backup\Registry\TenantDataDefinition;
use PDO;

/**
 * Připraví cílové pečetě normalizovaných zákonných výsledků ještě před
 * vkládáním immutable payroll grafu.
 */
final class CompanyBackupPayrollStatutoryResultSetImportPreparer
{
    private readonly CompanyBackupPreallocatedImportRowPreparer $personPreparer;

    private readonly CompanyBackupPreallocatedImportRowPreparer $relationshipPreparer;

    private readonly CompanyBackupTableProjection $personProjection;

    /** @var \Closure(CompanyBackupEmbeddedHashReference,string):string */
    private readonly \Closure $hashMapper;

    /** @var \Closure(CompanyBackupHashReference,string):string */
    private readonly \Closure $hashReferenceMapper;

    private int $resealedRoots = 0;

    private bool $finished = false;

    private bool $closed = false;

    private function __construct(
        private readonly CompanyBackupPayrollStatutoryResultSetSourceIndex $sourceIndex,
        TenantDataDefinition $personDefinition,
        TenantDataDefinition $relationshipDefinition,
        CompanyBackupTargetIdentityMap $identities,
        CompanyBackupReferenceResolutionPlan $resolutions,
        CompanyBackupImportDependencyPlan $plan,
        private readonly CompanyBackupSqlTargetHashMap $hashes,
        callable $hashMapper,
        callable $hashReferenceMapper,
        private readonly int $expectedRootRows,
        private readonly int $expectedPersonRows,
        private readonly int $expectedRelationshipRows,
        CompanyBackupArchiveLimits $limits,
    ) {
        $this->personProjection = CompanyBackupTableProjection::fromDefinition(
            $personDefinition,
        );
        $this->personPreparer = new CompanyBackupPreallocatedImportRowPreparer(
            $personDefinition,
            $identities,
            $resolutions,
            $plan,
            $limits,
        );
        $this->relationshipPreparer =
            new CompanyBackupPreallocatedImportRowPreparer(
                $relationshipDefinition,
                $identities,
                $resolutions,
                $plan,
                $limits,
            );
        $this->hashMapper = \Closure::fromCallable($hashMapper);
        $this->hashReferenceMapper = \Closure::fromCallable(
            $hashReferenceMapper,
        );
    }

    /**
     * @param callable(CompanyBackupEmbeddedHashReference,string):string $hashMapper
     * @param callable(CompanyBackupHashReference,string):string $hashReferenceMapper
     */
    public static function prepare(
        PDO $database,
        CompanyBackupImportSource $source,
        CompanyBackupTargetIdentityMap $identities,
        CompanyBackupReferenceResolutionPlan $resolutions,
        CompanyBackupImportDependencyPlan $plan,
        CompanyBackupSqlTargetHashMap $hashes,
        callable $hashMapper,
        callable $hashReferenceMapper,
        CompanyBackupArchiveLimits $limits = new CompanyBackupArchiveLimits(),
    ): self {
        $registry = $source->targetRegistry()->registry;
        $inventory = $source->dataInventory();
        $rootDefinition = $registry->definition(
            CompanyBackupPayrollStatutoryResultSetAssembler::ROOT_REGISTRY_KEY,
        );
        $personDefinition = $registry->definition(
            CompanyBackupPayrollStatutoryResultSetAssembler::PERSON_REGISTRY_KEY,
        );
        $relationshipDefinition = $registry->definition(
            CompanyBackupPayrollStatutoryResultSetAssembler::RELATIONSHIP_REGISTRY_KEY,
        );
        $rootObject = $inventory->object(
            CompanyBackupPayrollStatutoryResultSetAssembler::ROOT_REGISTRY_KEY,
        );
        $personObject = $inventory->object(
            CompanyBackupPayrollStatutoryResultSetAssembler::PERSON_REGISTRY_KEY,
        );
        $relationshipObject = $inventory->object(
            CompanyBackupPayrollStatutoryResultSetAssembler::RELATIONSHIP_REGISTRY_KEY,
        );
        if (!$rootDefinition instanceof TenantDataDefinition
            || !$personDefinition instanceof TenantDataDefinition
            || !$relationshipDefinition instanceof TenantDataDefinition
            || !$rootObject instanceof CompanyBackupDataObject
            || !$personObject instanceof CompanyBackupDataObject
            || !$relationshipObject instanceof CompanyBackupDataObject
            || !$identities->isSealed()
            || $hashes->isSealed()
        ) {
            throw self::error('import_statutory_result_set_context_invalid');
        }
        foreach (
            [$rootDefinition, $personDefinition, $relationshipDefinition]
            as $definition
        ) {
            $projection = CompanyBackupTableProjection::fromDefinition(
                $definition,
            );
            if ($projection->allowsDeferredUpdates
                || !$plan->containsInsertRegistryKey($definition->key)
            ) {
                throw self::error(
                    'import_statutory_result_set_context_invalid',
                    $definition->key,
                );
            }
        }

        $index = new CompanyBackupPayrollStatutoryResultSetSourceIndex(
            $database,
            $limits,
        );
        try {
            $prepared = new self(
                $index,
                $personDefinition,
                $relationshipDefinition,
                $identities,
                $resolutions,
                $plan,
                $hashes,
                $hashMapper,
                $hashReferenceMapper,
                $rootObject->rows,
                $personObject->rows,
                $relationshipObject->rows,
                $limits,
            );
            $prepared->initialize($source);
            return $prepared;
        } catch (\Throwable $e) {
            try {
                $index->close();
            } catch (\Throwable) {
            }
            throw $e;
        }
    }

    /**
     * @param array<string,mixed> $sourceHeader
     */
    public function resealRoot(
        array $sourceHeader,
        CompanyBackupPreparedImportRow $preparedHeader,
    ): CompanyBackupPreparedImportRow {
        $this->assertOpen();
        if ($this->finished) {
            throw new \LogicException(
                'Příprava zákonných výsledků už byla dokončena.',
            );
        }
        $rootId = self::positiveInt($sourceHeader['id'] ?? null);
        $sourceRows = $this->sourceIndex->rowsFor($rootId);
        $preparedPeople = [];
        $preparedRelationships = [];
        $resealed = CompanyBackupPayrollStatutoryResultSetResealer::reseal(
            $sourceHeader,
            $preparedHeader->row,
            $sourceRows['people'],
            $sourceRows['relationships'],
            function (array $row) use (&$preparedPeople): array {
                $prepared = $this->personPreparer->prepare(
                    $row,
                    $this->hashMapper,
                    $this->hashReferenceMapper,
                )->row;
                $preparedPeople[] = $prepared;
                return $prepared;
            },
            function (array $row) use (&$preparedRelationships): array {
                $prepared = $this->relationshipPreparer->prepare(
                    $row,
                    $this->hashMapper,
                    $this->hashReferenceMapper,
                )->row;
                $preparedRelationships[] = $prepared;
                return $prepared;
            },
        );
        if ($resealed['people'] !== $preparedPeople
            || $resealed['relationships'] !== $preparedRelationships
        ) {
            throw self::error('import_statutory_result_set_scope_invalid');
        }
        $this->resealedRoots++;
        if ($this->resealedRoots > $this->expectedRootRows) {
            throw self::error('import_statutory_result_set_root_count_mismatch');
        }

        return new CompanyBackupPreparedImportRow(
            $resealed['header'],
            $preparedHeader->sourceIdentity,
            $preparedHeader->targetIdentity,
        );
    }

    public function finish(): void
    {
        $this->assertOpen();
        if ($this->finished) {
            throw new \LogicException(
                'Příprava zákonných výsledků už byla dokončena.',
            );
        }
        if ($this->resealedRoots !== $this->expectedRootRows) {
            throw self::error('import_statutory_result_set_root_count_mismatch');
        }
        $this->finished = true;
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        try {
            $this->sourceIndex->close();
        } catch (\Throwable $e) {
            $this->closed = true;
            throw self::error(
                'import_statutory_result_set_cleanup_failed',
                previous: $e,
            );
        }
        $this->closed = true;
    }

    public function __destruct()
    {
        if (!$this->closed) {
            try {
                $this->sourceIndex->close();
            } catch (\Throwable) {
            }
            $this->closed = true;
        }
    }

    private function initialize(CompanyBackupImportSource $source): void
    {
        $people = $source->consumeRows(
            CompanyBackupPayrollStatutoryResultSetAssembler::PERSON_REGISTRY_KEY,
            function (array $row): void {
                $this->sourceIndex->addPerson($row);
            },
        );
        $relationships = $source->consumeRows(
            CompanyBackupPayrollStatutoryResultSetAssembler::RELATIONSHIP_REGISTRY_KEY,
            function (array $row): void {
                $this->sourceIndex->addRelationship($row);
            },
        );
        if ($people !== $this->expectedPersonRows
            || $relationships !== $this->expectedRelationshipRows
        ) {
            throw self::error('import_statutory_result_set_row_count_mismatch');
        }
        $this->sourceIndex->seal();

        $roots = $source->consumeRows(
            CompanyBackupPayrollStatutoryResultSetAssembler::ROOT_REGISTRY_KEY,
            function (array $row): void {
                $this->sourceIndex->assertSourceHeader($row);
            },
        );
        if ($roots !== $this->expectedRootRows) {
            throw self::error('import_statutory_result_set_root_count_mismatch');
        }
        $this->sourceIndex->finish($this->expectedRootRows);

        $mappedPeople = $source->consumeRows(
            CompanyBackupPayrollStatutoryResultSetAssembler::PERSON_REGISTRY_KEY,
            function (array $row): void {
                $prepared = $this->personPreparer->prepare(
                    $row,
                    $this->hashMapper,
                    $this->hashReferenceMapper,
                );
                $this->hashes->addRow(
                    $this->personProjection,
                    $row,
                    $prepared->row,
                );
            },
        );
        if ($mappedPeople !== $this->expectedPersonRows) {
            throw self::error('import_statutory_result_set_row_count_mismatch');
        }
    }

    private function assertOpen(): void
    {
        if ($this->closed) {
            throw new \LogicException(
                'Příprava zákonných výsledků už je zavřená.',
            );
        }
    }

    private static function positiveInt(mixed $value): int
    {
        if (!is_int($value) || $value < 1) {
            throw self::error('import_statutory_result_set_root_invalid');
        }
        return $value;
    }

    private static function error(
        string $errorCode,
        string $registryKey =
            CompanyBackupPayrollStatutoryResultSetAssembler::ROOT_REGISTRY_KEY,
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
