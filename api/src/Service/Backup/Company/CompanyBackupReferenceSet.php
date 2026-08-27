<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

use MyInvoice\Service\Backup\Registry\TenantDataDefinition;
use MyInvoice\Service\Backup\Registry\TenantDataPolicy;
use MyInvoice\Service\Backup\Registry\TenantDataRegistry;

/** Úplná sada remap politik exportovaných referencí jedné tabulky. */
final readonly class CompanyBackupReferenceSet
{
    /** @var list<CompanyBackupReference> */
    public array $references;

    /** @param list<CompanyBackupReference> $references */
    private function __construct(
        public string $registryKey,
        array $references,
    ) {
        $this->references = $references;
    }

    public static function fromArray(mixed $value, string $registryKey): self
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new CompanyBackupDataSourceException(
                'data_reference_metadata_invalid',
                $registryKey,
            );
        }
        $references = [];
        $signatures = [];
        /** @var array<string,list<CompanyBackupReference>> $columnClaims */
        $columnClaims = [];
        foreach ($value as $item) {
            $reference = CompanyBackupReference::fromArray($item, $registryKey);
            $signature = $reference->signature();
            if (isset($signatures[$signature])) {
                throw new CompanyBackupDataSourceException(
                    'data_reference_duplicate',
                    $registryKey,
                    $reference->firstColumn(),
                );
            }
            $signatures[$signature] = true;
            $references[] = $reference;
            foreach ($reference->columns as $column) {
                $columnClaims[$column][] = $reference;
            }
        }
        foreach ($columnClaims as $column => $claims) {
            if (count($claims) < 2 || self::isSharedTenantContext($column, $claims)) {
                continue;
            }
            throw new CompanyBackupDataSourceException(
                'data_reference_duplicate',
                $registryKey,
                $column,
            );
        }
        $ordered = $references;
        usort(
            $ordered,
            static fn (CompanyBackupReference $left, CompanyBackupReference $right): int =>
                strcmp($left->signature(), $right->signature()),
        );
        if ($ordered !== $references) {
            throw new CompanyBackupDataSourceException(
                'data_reference_metadata_invalid',
                $registryKey,
            );
        }
        return new self($registryKey, $references);
    }

    /** @param list<CompanyBackupReference> $claims */
    private static function isSharedTenantContext(string $column, array $claims): bool
    {
        if ($column !== 'supplier_id') {
            return false;
        }
        foreach ($claims as $reference) {
            $supplierRoot = $reference->mapping === CompanyBackupReferenceMapping::TenantId
                && $reference->columns === ['supplier_id']
                && $reference->target === 'table:supplier'
                && $reference->targetColumns === ['id'];
            $tenantScoped = in_array(
                $reference->mapping,
                [
                    CompanyBackupReferenceMapping::TenantId,
                    CompanyBackupReferenceMapping::TenantNaturalKey,
                ],
                true,
            )
                && $reference->columns[0] === 'supplier_id'
                && $reference->targetColumns[0] === 'supplier_id';
            if (!$supplierRoot && !$tenantScoped) {
                return false;
            }
        }
        return true;
    }

    /** @param list<string> $dataColumns */
    public function assertProjectionColumns(array $dataColumns): void
    {
        $exported = array_fill_keys($dataColumns, true);
        $classified = [];
        foreach ($this->references as $reference) {
            foreach ($reference->columns as $column) {
                if (!isset($exported[$column])) {
                    throw new CompanyBackupDataSourceException(
                        'data_reference_source_not_exported',
                        $this->registryKey,
                        $column,
                    );
                }
                $classified[$column] = true;
            }
        }
        foreach ($dataColumns as $column) {
            if ($column !== 'id'
                && (str_ends_with($column, '_id') || str_ends_with($column, '_by'))
                && !isset($classified[$column])
            ) {
                throw new CompanyBackupDataSourceException(
                    'data_reference_column_unclassified',
                    $this->registryKey,
                    $column,
                );
            }
        }
    }

    public function assertRegistryTargets(TenantDataRegistry $registry): void
    {
        foreach ($this->references as $reference) {
            $target = $registry->definition($reference->target);
            if ($target === null
                || !$target->hasProfile(TenantDataRegistry::COMPANY_BACKUP_PROFILE)
                || $target->policy === TenantDataPolicy::Unsupported
            ) {
                throw $this->targetError($reference);
            }
            $primaryKey = $this->targetPrimaryKey($target, $reference);
            $naturalKey = $this->targetNaturalKey($target);
            $targetsExpectedKey = match ($reference->mapping) {
                CompanyBackupReferenceMapping::TenantNaturalKey =>
                    $naturalKey !== null && $reference->targetColumns === $naturalKey,
                CompanyBackupReferenceMapping::TenantId,
                CompanyBackupReferenceMapping::GlobalNaturalKey,
                CompanyBackupReferenceMapping::Actor =>
                    $reference->targetColumns === $primaryKey,
            };
            if (!$targetsExpectedKey) {
                throw $this->targetError($reference);
            }

            $valid = match ($reference->mapping) {
                CompanyBackupReferenceMapping::TenantId,
                CompanyBackupReferenceMapping::TenantNaturalKey => in_array(
                    $target->policy,
                    [
                        TenantDataPolicy::TenantRoot,
                        TenantDataPolicy::TenantOwned,
                        TenantDataPolicy::TenantOwnedIndirect,
                    ],
                    true,
                ),
                CompanyBackupReferenceMapping::Actor =>
                    $target->policy === TenantDataPolicy::InstanceOwned,
                CompanyBackupReferenceMapping::GlobalNaturalKey =>
                    $target->policy === TenantDataPolicy::GlobalReference
                    && $naturalKey !== null,
            };
            if (!$valid) {
                throw $this->targetError($reference);
            }
        }
    }

    public function assertRuntimeSchema(CompanyBackupTableReferenceSchema $schema): void
    {
        $runtimeNullable = array_fill_keys($schema->nullableColumns, true);
        $declared = [];
        foreach ($this->references as $reference) {
            $declared[$reference->signature()] = $reference;
            $nullable = array_fill_keys($reference->nullableColumns, true);
            foreach ($reference->columns as $column) {
                if (isset($runtimeNullable[$column]) !== isset($nullable[$column])) {
                    throw new CompanyBackupDataSourceException(
                        'data_reference_nullability_mismatch',
                        $this->registryKey,
                        $column,
                    );
                }
            }
        }

        $runtime = [];
        foreach ($schema->foreignKeys as $foreignKey) {
            $signature = $foreignKey->signature();
            $reference = $declared[$signature] ?? null;
            if ($reference === null) {
                throw new CompanyBackupDataSourceException(
                    'data_reference_foreign_key_unclassified',
                    $this->registryKey,
                    $foreignKey->columns[0],
                );
            }
            $runtime[$signature] = true;
        }
        foreach ($this->references as $reference) {
            if ($reference->constraint === CompanyBackupReferenceConstraint::Required
                && !isset($runtime[$reference->signature()])
            ) {
                throw new CompanyBackupDataSourceException(
                    'data_reference_constraint_missing',
                    $this->registryKey,
                    $reference->firstColumn(),
                );
            }
        }
    }

    private function targetError(
        CompanyBackupReference $reference,
    ): CompanyBackupDataSourceException {
        return new CompanyBackupDataSourceException(
            'data_reference_target_invalid',
            $this->registryKey,
            $reference->firstColumn(),
        );
    }

    /** @return list<string> */
    private function targetPrimaryKey(
        TenantDataDefinition $target,
        CompanyBackupReference $reference,
    ): array {
        $value = $target->details['primary_key'] ?? null;
        if (!is_array($value) || !array_is_list($value) || $value === []) {
            throw $this->targetError($reference);
        }
        $result = [];
        $seen = [];
        foreach ($value as $column) {
            if (!is_string($column)
                || preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $column) !== 1
                || isset($seen[$column])
            ) {
                throw $this->targetError($reference);
            }
            $seen[$column] = true;
            $result[] = $column;
        }
        return $result;
    }

    /** @return list<string>|null */
    private function targetNaturalKey(TenantDataDefinition $target): ?array
    {
        $value = $target->details['natural_key'] ?? null;
        if (!is_array($value) || !array_is_list($value) || $value === []) {
            return null;
        }
        $seen = [];
        $result = [];
        foreach ($value as $column) {
            if (!is_string($column)
                || preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $column) !== 1
                || isset($seen[$column])
            ) {
                return null;
            }
            $seen[$column] = true;
            $result[] = $column;
        }
        return $result;
    }
}
