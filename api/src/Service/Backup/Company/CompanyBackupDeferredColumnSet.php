<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/**
 * Přesný allowlist fyzických sloupců druhého importního průchodu včetně
 * transitivních zdrojů a výstupů odvozených hashů.
 */
final readonly class CompanyBackupDeferredColumnSet
{
    /** @param list<string> $columns */
    private function __construct(public array $columns) {}

    public static function fromProjection(
        CompanyBackupTableProjection $projection,
        CompanyBackupImportDependencyPlan $plan,
    ): self {
        if (!$plan->containsInsertRegistryKey($projection->registryKey)) {
            throw self::error($projection);
        }

        /** @var array<string,list<string>> $columnsByDependency */
        $columnsByDependency = [];
        foreach ($projection->references->references as $reference) {
            self::register(
                $columnsByDependency,
                CompanyBackupImportDependencyKind::Column,
                $reference->target,
                $reference->signature(),
                $reference->columns,
                $projection,
            );
        }
        foreach ($projection->encodedReferences->references as $reference) {
            self::register(
                $columnsByDependency,
                CompanyBackupImportDependencyKind::Encoded,
                $reference->target,
                $reference->signature(),
                [$reference->column],
                $projection,
            );
        }
        foreach ($projection->embeddedReferences->references as $reference) {
            self::register(
                $columnsByDependency,
                CompanyBackupImportDependencyKind::Embedded,
                $reference->target,
                $reference->signature(),
                [$reference->column],
                $projection,
            );
        }
        foreach (
            $projection->embeddedHashReferences->references
            as $reference
        ) {
            self::register(
                $columnsByDependency,
                CompanyBackupImportDependencyKind::EmbeddedHash,
                $reference->target,
                $reference->signature(),
                [$reference->column],
                $projection,
            );
        }
        foreach ($projection->polymorphicReferences->references as $reference) {
            foreach ($reference->cases as $case) {
                if ($case->mapping
                    !== CompanyBackupPolymorphicReferenceMapping::TenantId
                    || $case->target === null
                ) {
                    continue;
                }
                self::register(
                    $columnsByDependency,
                    CompanyBackupImportDependencyKind::Polymorphic,
                    $case->target,
                    $reference->signature() . '/' . $case->signature(),
                    [$reference->column],
                    $projection,
                );
            }
        }

        $affected = [];
        foreach ($plan->deferredDependenciesFor($projection->registryKey) as $dependency) {
            $columns = $columnsByDependency[self::key(
                $dependency->kind,
                $dependency->targetRegistryKey,
                $dependency->signature,
            )] ?? null;
            if ($columns === null) {
                throw self::error($projection);
            }
            foreach ($columns as $column) {
                $affected[$column] = true;
            }
        }

        do {
            $changed = false;
            foreach ($projection->derivedHashes->hashes as $hash) {
                $dependsOnAffected = isset($affected[$hash->sourceColumn])
                    || isset($affected[$hash->hashColumn]);
                foreach ($hash->dependencies as $dependency) {
                    $dependsOnAffected = $dependsOnAffected
                        || isset($affected[$dependency->sourceHashColumn]);
                }
                if (!$dependsOnAffected) {
                    continue;
                }
                foreach ([$hash->sourceColumn, $hash->hashColumn] as $column) {
                    if (!isset($affected[$column])) {
                        $affected[$column] = true;
                        $changed = true;
                    }
                }
            }
        } while ($changed);

        foreach ($projection->primaryKey as $column) {
            if (isset($affected[$column])) {
                throw self::error($projection, $column);
            }
        }
        $columns = array_values(array_filter(
            $projection->dataColumns,
            static fn (string $column): bool => isset($affected[$column]),
        ));
        if (count($columns) !== count($affected)) {
            throw self::error($projection);
        }
        return new self($columns);
    }

    /**
     * @param array<string,list<string>> $columnsByDependency
     * @param list<string> $columns
     */
    private static function register(
        array &$columnsByDependency,
        CompanyBackupImportDependencyKind $kind,
        string $targetRegistryKey,
        string $signature,
        array $columns,
        CompanyBackupTableProjection $projection,
    ): void {
        $key = self::key($kind, $targetRegistryKey, $signature);
        if (isset($columnsByDependency[$key])) {
            throw self::error($projection);
        }
        $columnsByDependency[$key] = $columns;
    }

    private static function key(
        CompanyBackupImportDependencyKind $kind,
        string $targetRegistryKey,
        string $signature,
    ): string {
        return implode("\0", [$kind->value, $targetRegistryKey, $signature]);
    }

    private static function error(
        CompanyBackupTableProjection $projection,
        ?string $column = null,
    ): CompanyBackupImportWriteException {
        return new CompanyBackupImportWriteException(
            'import_deferred_column_plan_invalid',
            $projection->registryKey,
            $column,
        );
    }
}
