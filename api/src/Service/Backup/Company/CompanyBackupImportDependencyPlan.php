<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

use MyInvoice\Service\Backup\CanonicalJson;
use MyInvoice\Service\Backup\Registry\TenantDataDefinition;
use MyInvoice\Service\Backup\Registry\TenantDataObjectKind;
use MyInvoice\Service\Backup\Registry\TenantDataPolicy;
use MyInvoice\Service\Backup\Registry\TenantDataRegistry;
use MyInvoice\Service\Backup\Registry\TenantDataRegistrySnapshot;

/**
 * Kanonické pořadí payloadů a úplný seznam referencí, které lze bezpečně
 * materializovat až ve druhém průchodu importu.
 */
final readonly class CompanyBackupImportDependencyPlan
{
    public const FORMAT = 'myucto-company-import-dependency-plan';
    public const VERSION = 1;

    /** @var list<string> */
    private array $globalRegistryKeys;

    /** @var list<list<string>> */
    private array $insertBatches;

    /** @var list<CompanyBackupImportDependency> */
    private array $dependencies;

    public string $bindingSha256;

    /**
     * @param list<string> $globalRegistryKeys
     * @param list<list<string>> $insertBatches
     * @param list<CompanyBackupImportDependency> $dependencies
     */
    private function __construct(
        public string $registryFingerprint,
        public string $dataInventorySha256,
        array $globalRegistryKeys,
        array $insertBatches,
        array $dependencies,
    ) {
        $this->globalRegistryKeys = $globalRegistryKeys;
        $this->insertBatches = $insertBatches;
        $this->dependencies = $dependencies;
        $this->bindingSha256 = CanonicalJson::sha256($this->payload());
    }

    public static function fromRegistry(
        TenantDataRegistrySnapshot $snapshot,
        CompanyBackupDataInventory $inventory,
    ): self {
        if ($snapshot->profile !== TenantDataRegistry::COMPANY_BACKUP_PROFILE
            || !hash_equals(
                $snapshot->fingerprint,
                $inventory->registryFingerprint,
            )
        ) {
            throw self::error('import_plan_context_mismatch');
        }

        /** @var array<string,TenantDataDefinition> $insertDefinitions */
        $insertDefinitions = [];
        /** @var list<string> $globalRegistryKeys */
        $globalRegistryKeys = [];
        /** @var list<string> $tenantRoots */
        $tenantRoots = [];
        /** @var array<string,CompanyBackupTableProjection> $projections */
        $projections = [];

        foreach ($inventory->objects as $object) {
            $definition = $snapshot->registry->definition($object->registryKey);
            if (!$definition instanceof TenantDataDefinition
                || $definition->kind !== TenantDataObjectKind::Table
                || !$definition->policy->hasMachineDataPayload()
            ) {
                throw self::error(
                    'import_payload_contract_invalid',
                    $object->registryKey,
                );
            }
            try {
                $projection = CompanyBackupTableProjection::fromDefinition($definition);
                $projection->assertRegistryTargets($snapshot->registry);
            } catch (CompanyBackupDataSourceException $e) {
                throw self::error(
                    'import_payload_contract_invalid',
                    $object->registryKey,
                    previous: $e,
                );
            }
            $projections[$object->registryKey] = $projection;

            if ($definition->policy === TenantDataPolicy::GlobalReference) {
                $globalRegistryKeys[] = $object->registryKey;
                continue;
            }
            if (!in_array($definition->policy, [
                TenantDataPolicy::TenantRoot,
                TenantDataPolicy::TenantOwned,
                TenantDataPolicy::TenantOwnedIndirect,
            ], true)) {
                throw self::error(
                    'import_payload_policy_invalid',
                    $object->registryKey,
                );
            }
            $insertDefinitions[$object->registryKey] = $definition;
            if ($definition->policy === TenantDataPolicy::TenantRoot) {
                $tenantRoots[] = $object->registryKey;
            }
        }

        sort($globalRegistryKeys, SORT_STRING);
        sort($tenantRoots, SORT_STRING);
        ksort($insertDefinitions, SORT_STRING);
        $rootKey = $tenantRoots[0] ?? null;
        $rootObject = $rootKey === null ? null : $inventory->object($rootKey);
        if (count($tenantRoots) !== 1 || $rootObject?->rows !== 1) {
            throw self::error('import_tenant_root_invalid', $rootKey);
        }

        $dependencies = [];
        foreach ($insertDefinitions as $registryKey => $definition) {
            $projection = $projections[$registryKey];
            self::collectDependencies(
                $dependencies,
                $definition,
                $projection,
                $insertDefinitions,
            );
        }
        usort(
            $dependencies,
            static fn (
                CompanyBackupImportDependency $left,
                CompanyBackupImportDependency $right,
            ): int => self::compareDependencies($left, $right),
        );

        return new self(
            $snapshot->fingerprint,
            CanonicalJson::sha256($inventory->toArray()),
            $globalRegistryKeys,
            self::topologicalBatches(array_keys($insertDefinitions), $dependencies),
            $dependencies,
        );
    }

    /** @return list<string> */
    public function globalRegistryKeys(): array
    {
        return $this->globalRegistryKeys;
    }

    /** @return list<list<string>> */
    public function insertBatches(): array
    {
        return $this->insertBatches;
    }

    /** @return list<CompanyBackupImportDependency> */
    public function dependencies(): array
    {
        return $this->dependencies;
    }

    /** @return list<CompanyBackupImportDependency> */
    public function deferredDependencies(): array
    {
        return array_values(array_filter(
            $this->dependencies,
            static fn (CompanyBackupImportDependency $dependency): bool =>
                $dependency->deferred,
        ));
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            ...$this->payload(),
            'binding_sha256' => $this->bindingSha256,
        ];
    }

    /**
     * @param array<string,CompanyBackupImportDependency> $dependencies
     * @param array<string,TenantDataDefinition> $insertDefinitions
     */
    private static function collectDependencies(
        array &$dependencies,
        TenantDataDefinition $definition,
        CompanyBackupTableProjection $projection,
        array $insertDefinitions,
    ): void {
        foreach ($projection->references->references as $reference) {
            if (!self::isInternalMapping($reference->mapping)) {
                continue;
            }
            self::addDependency(
                $dependencies,
                $definition->key,
                $reference->target,
                CompanyBackupImportDependencyKind::Column,
                $reference->signature(),
                $reference->nullableColumns !== [],
                $insertDefinitions,
            );
        }
        foreach ($projection->encodedReferences->references as $reference) {
            self::addDependency(
                $dependencies,
                $definition->key,
                $reference->target,
                CompanyBackupImportDependencyKind::Encoded,
                $reference->signature(),
                $reference->nullable,
                $insertDefinitions,
            );
        }
        foreach ($projection->embeddedReferences->references as $reference) {
            if (!self::isInternalMapping($reference->mapping)) {
                continue;
            }
            self::addDependency(
                $dependencies,
                $definition->key,
                $reference->target,
                CompanyBackupImportDependencyKind::Embedded,
                $reference->signature(),
                $reference->nullable || $reference->documentNullable,
                $insertDefinitions,
            );
        }
        foreach ($projection->embeddedHashReferences->references as $reference) {
            self::addDependency(
                $dependencies,
                $definition->key,
                $reference->target,
                CompanyBackupImportDependencyKind::EmbeddedHash,
                $reference->signature(),
                $reference->nullable,
                $insertDefinitions,
            );
        }
        foreach ($projection->polymorphicReferences->references as $reference) {
            foreach ($reference->cases as $case) {
                if ($case->mapping
                    !== CompanyBackupPolymorphicReferenceMapping::TenantId
                ) {
                    continue;
                }
                $target = $case->target;
                if (!is_string($target)) {
                    throw self::error(
                        'import_dependency_target_invalid',
                        $definition->key,
                    );
                }
                self::addDependency(
                    $dependencies,
                    $definition->key,
                    $target,
                    CompanyBackupImportDependencyKind::Polymorphic,
                    $reference->column . '?' . $reference->discriminatorColumn
                        . ':' . $case->signature(),
                    $reference->nullable,
                    $insertDefinitions,
                );
            }
        }
    }

    private static function isInternalMapping(
        CompanyBackupReferenceMapping $mapping,
    ): bool {
        return in_array($mapping, [
            CompanyBackupReferenceMapping::TenantId,
            CompanyBackupReferenceMapping::TenantIdOrZero,
            CompanyBackupReferenceMapping::TenantReferenceKey,
            CompanyBackupReferenceMapping::TenantNaturalKey,
        ], true);
    }

    /**
     * @param array<string,CompanyBackupImportDependency> $dependencies
     * @param array<string,TenantDataDefinition> $insertDefinitions
     */
    private static function addDependency(
        array &$dependencies,
        string $sourceRegistryKey,
        string $targetRegistryKey,
        CompanyBackupImportDependencyKind $kind,
        string $signature,
        bool $deferred,
        array $insertDefinitions,
    ): void {
        if (!isset($insertDefinitions[$targetRegistryKey])) {
            throw self::error(
                'import_dependency_target_invalid',
                $sourceRegistryKey,
                $targetRegistryKey,
            );
        }
        $key = implode("\0", [
            $sourceRegistryKey,
            $targetRegistryKey,
            $kind->value,
            $signature,
        ]);
        if (isset($dependencies[$key])) {
            throw self::error(
                'import_dependency_duplicate',
                $sourceRegistryKey,
                $targetRegistryKey,
            );
        }
        try {
            $dependencies[$key] = new CompanyBackupImportDependency(
                $sourceRegistryKey,
                $targetRegistryKey,
                $kind,
                $signature,
                $deferred,
            );
        } catch (\InvalidArgumentException $e) {
            throw self::error(
                'import_dependency_invalid',
                $sourceRegistryKey,
                $targetRegistryKey,
                $e,
            );
        }
    }

    private static function compareDependencies(
        CompanyBackupImportDependency $left,
        CompanyBackupImportDependency $right,
    ): int {
        return [
            $left->sourceRegistryKey,
            $left->targetRegistryKey,
            $left->kind->value,
            $left->signature,
            $left->deferred ? 1 : 0,
        ] <=> [
            $right->sourceRegistryKey,
            $right->targetRegistryKey,
            $right->kind->value,
            $right->signature,
            $right->deferred ? 1 : 0,
        ];
    }

    /**
     * @param list<string> $registryKeys
     * @param list<CompanyBackupImportDependency> $dependencies
     * @return list<list<string>>
     */
    private static function topologicalBatches(
        array $registryKeys,
        array $dependencies,
    ): array {
        /** @var array<string,array<string,true>> $requires */
        $requires = [];
        foreach ($registryKeys as $registryKey) {
            $requires[$registryKey] = [];
        }
        foreach ($dependencies as $dependency) {
            if (!$dependency->deferred) {
                $requires[$dependency->sourceRegistryKey][
                    $dependency->targetRegistryKey
                ] = true;
            }
        }

        $batches = [];
        while ($requires !== []) {
            $ready = [];
            foreach ($requires as $registryKey => $targets) {
                if ($targets === []) {
                    $ready[] = $registryKey;
                }
            }
            sort($ready, SORT_STRING);
            if ($ready === []) {
                $cycleKeys = array_keys($requires);
                sort($cycleKeys, SORT_STRING);
                throw self::error(
                    'import_dependency_cycle',
                    $cycleKeys[0],
                );
            }
            $batches[] = $ready;
            foreach ($ready as $registryKey) {
                unset($requires[$registryKey]);
            }
            foreach ($requires as &$targets) {
                foreach ($ready as $registryKey) {
                    unset($targets[$registryKey]);
                }
            }
            unset($targets);
        }
        return $batches;
    }

    /** @return array<string,mixed> */
    private function payload(): array
    {
        return [
            'format' => self::FORMAT,
            'version' => self::VERSION,
            'registry_fingerprint' => $this->registryFingerprint,
            'data_inventory_sha256' => $this->dataInventorySha256,
            'global_registry_keys' => $this->globalRegistryKeys,
            'insert_batches' => $this->insertBatches,
            'dependencies' => array_map(
                static fn (CompanyBackupImportDependency $dependency): array =>
                    $dependency->toArray(),
                $this->dependencies,
            ),
        ];
    }

    private static function error(
        string $errorCode,
        ?string $registryKey = null,
        ?string $targetRegistryKey = null,
        ?\Throwable $previous = null,
    ): CompanyBackupImportPlanException {
        return new CompanyBackupImportPlanException(
            $errorCode,
            $registryKey,
            $targetRegistryKey,
            $previous,
        );
    }
}
