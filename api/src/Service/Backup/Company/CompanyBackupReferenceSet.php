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
            if (count($claims) < 2
                || self::isSharedTenantContext($column, $claims)
                || self::isSharedReferenceKeyCoordinate($column, $claims)
                || self::isConditionallyDisjoint($column, $claims)
            ) {
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
    private static function isConditionallyDisjoint(string $column, array $claims): bool
    {
        $first = $claims[0] ?? null;
        if ($first === null || $first->condition === null) {
            return false;
        }
        $columns = $first->columns;
        $conditionColumn = $first->condition->column;
        $values = [];
        foreach ($claims as $claim) {
            $condition = $claim->condition;
            if ($claim->columns !== $columns
                || !in_array($column, $columns, true)
                || $condition === null
                || $condition->column !== $conditionColumn
                || isset($values[$condition->equals])
            ) {
                return false;
            }
            $values[$condition->equals] = true;
        }
        return true;
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
                    CompanyBackupReferenceMapping::TenantIdOrZero,
                    CompanyBackupReferenceMapping::TenantReferenceKey,
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

    /** @param list<CompanyBackupReference> $claims */
    private static function isSharedReferenceKeyCoordinate(
        string $column,
        array $claims,
    ): bool {
        $hasReferenceKey = false;
        $hasNaturalKey = false;
        $hasTenantId = false;
        foreach ($claims as $reference) {
            $position = array_search($column, $reference->columns, true);
            if (!is_int($position)) {
                return false;
            }
            if ($reference->mapping
                === CompanyBackupReferenceMapping::TenantReferenceKey
            ) {
                if (($reference->targetColumns[$position] ?? null) !== $column) {
                    return false;
                }
                $hasReferenceKey = true;
                continue;
            }
            if ($reference->mapping
                === CompanyBackupReferenceMapping::TenantNaturalKey
            ) {
                if (($reference->targetColumns[$position] ?? null) !== $column) {
                    return false;
                }
                $hasNaturalKey = true;
                continue;
            }
            if ($reference->mapping !== CompanyBackupReferenceMapping::TenantId
                || $reference->columns !== ['supplier_id', $column]
                || $reference->targetColumns !== ['supplier_id', 'id']
            ) {
                return false;
            }
            $hasTenantId = true;
        }
        return $hasReferenceKey || ($hasNaturalKey && $hasTenantId);
    }

    /**
     * @param list<string> $dataColumns
     * @param list<string> $additionalClassifiedColumns
     */
    public function assertProjectionColumns(
        array $dataColumns,
        array $additionalClassifiedColumns = [],
    ): void
    {
        $exported = array_fill_keys($dataColumns, true);
        $additional = array_fill_keys($additionalClassifiedColumns, true);
        $classified = [];
        foreach ($this->references as $reference) {
            $conditionColumn = $reference->condition?->column;
            if ($conditionColumn !== null && !isset($exported[$conditionColumn])) {
                throw new CompanyBackupDataSourceException(
                    'data_reference_condition_source_not_exported',
                    $this->registryKey,
                    $conditionColumn,
                );
            }
            foreach ($reference->columns as $column) {
                if (!isset($exported[$column])) {
                    throw new CompanyBackupDataSourceException(
                        'data_reference_source_not_exported',
                        $this->registryKey,
                        $column,
                    );
                }
                if (isset($additional[$column])) {
                    throw new CompanyBackupDataSourceException(
                        'data_reference_column_classification_duplicate',
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
                && !isset($additional[$column])
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
            $referenceKeys = $reference->mapping
                === CompanyBackupReferenceMapping::TenantReferenceKey
                ? $this->targetReferenceKeys($target, $reference)
                : [];
            $tenantScopedPrimaryKey = $reference->mapping
                === CompanyBackupReferenceMapping::TenantId
                && $reference->columns[0] === 'supplier_id'
                && $reference->targetColumns === ['supplier_id', ...$primaryKey];
            $targetsExpectedKey = match ($reference->mapping) {
                CompanyBackupReferenceMapping::TenantNaturalKey =>
                    $naturalKey !== null && $reference->targetColumns === $naturalKey,
                CompanyBackupReferenceMapping::TenantId =>
                    $reference->targetColumns === $primaryKey || $tenantScopedPrimaryKey,
                CompanyBackupReferenceMapping::TenantIdOrZero,
                CompanyBackupReferenceMapping::GlobalNaturalKey,
                CompanyBackupReferenceMapping::Actor,
                CompanyBackupReferenceMapping::CredentialDecision =>
                    $reference->targetColumns === $primaryKey,
                CompanyBackupReferenceMapping::TenantReferenceKey => in_array(
                    $reference->targetColumns,
                    $referenceKeys,
                    true,
                ),
            };
            if (!$targetsExpectedKey) {
                throw $this->targetError($reference);
            }

            $valid = match ($reference->mapping) {
                CompanyBackupReferenceMapping::TenantId,
                CompanyBackupReferenceMapping::TenantIdOrZero,
                CompanyBackupReferenceMapping::TenantReferenceKey,
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
                CompanyBackupReferenceMapping::CredentialDecision =>
                    $target->policy === TenantDataPolicy::PersonalSecretAttachment,
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

    /**
     * @param array<string,mixed> $row
     * @param callable(CompanyBackupReference,list<int|string>):void $visitor
     */
    public function visitSourceRow(array $row, callable $visitor): void
    {
        /** @var array<string,CompanyBackupReference> $conditionalGroups */
        $conditionalGroups = [];
        $matchedConditionalGroups = [];
        foreach ($this->references as $reference) {
            $condition = $reference->condition;
            $conditionalGroup = $condition === null
                ? null
                : $condition->column . "\0" . implode("\0", $reference->columns);
            if ($conditionalGroup !== null) {
                $conditionalGroups[$conditionalGroup] = $reference;
            }
            if (!$this->conditionMatches($row, $reference)) {
                continue;
            }
            if ($conditionalGroup !== null) {
                $matchedConditionalGroups[$conditionalGroup] = true;
            }

            $values = [];
            $hasNull = false;
            foreach ($reference->columns as $index => $column) {
                if (!array_key_exists($column, $row)) {
                    throw $this->valueError($column);
                }
                $value = $row[$column];
                if ($value === null) {
                    if (!in_array($column, $reference->nullableColumns, true)) {
                        throw $this->valueError($column);
                    }
                    $hasNull = true;
                    continue;
                }
                if (!$this->validSourceValue(
                    $value,
                    $reference,
                    $reference->targetColumns[$index],
                )) {
                    throw $this->valueError($column);
                }
                $values[] = $value;
            }
            if ($hasNull
                || ($reference->mapping === CompanyBackupReferenceMapping::TenantIdOrZero
                    && $values === [0])
            ) {
                continue;
            }
            $visitor($reference, $values);
        }

        foreach ($conditionalGroups as $group => $reference) {
            if (isset($matchedConditionalGroups[$group])) {
                continue;
            }
            foreach ($reference->columns as $column) {
                if (!array_key_exists($column, $row)) {
                    throw $this->valueError($column);
                }
                if ($row[$column] !== null) {
                    throw $this->valueError($column);
                }
            }
        }
    }

    /**
     * Přemapuje všechny aktivní přímé reference nad původním řádkem a změny
     * aplikuje až po úplné validaci, aby sdílený sloupec nemohl záviset na pořadí.
     *
     * @param array<string,mixed> $row
     * @param callable(CompanyBackupReference,list<int|string>):mixed $mapper
     * @return array<string,mixed>
     */
    public function remap(array $row, callable $mapper): array
    {
        /** @var array<string,int|string|null> $mappedColumns */
        $mappedColumns = [];
        $this->visitSourceRow(
            $row,
            function (
                CompanyBackupReference $reference,
                array $values,
            ) use ($mapper, &$mappedColumns): void {
                $mapped = $mapper($reference, $values);
                if ($mapped === null) {
                    if ($reference->nullableColumns !== $reference->columns) {
                        throw $this->valueError($reference->firstColumn());
                    }
                    $mapped = array_fill(0, count($reference->columns), null);
                }
                if (!is_array($mapped)
                    || !array_is_list($mapped)
                    || count($mapped) !== count($reference->columns)
                ) {
                    throw $this->valueError($reference->firstColumn());
                }

                foreach ($reference->columns as $index => $column) {
                    $value = $mapped[$index];
                    if ($value === null) {
                        if (!in_array(
                            $column,
                            $reference->nullableColumns,
                            true,
                        )) {
                            throw $this->valueError($column);
                        }
                    } elseif (!$this->validSourceValue(
                        $value,
                        $reference,
                        $reference->targetColumns[$index],
                    )) {
                        throw $this->valueError($column);
                    }
                    if (array_key_exists($column, $mappedColumns)
                        && $mappedColumns[$column] !== $value
                    ) {
                        throw $this->valueError($column);
                    }
                    $mappedColumns[$column] = $value;
                }
            },
        );

        foreach ($mappedColumns as $column => $value) {
            $row[$column] = $value;
        }
        return $row;
    }

    /** @param array<string,mixed> $row */
    private function conditionMatches(
        array $row,
        CompanyBackupReference $reference,
    ): bool {
        $condition = $reference->condition;
        if ($condition === null) {
            return true;
        }
        if (!array_key_exists($condition->column, $row)
            || !is_string($row[$condition->column])
        ) {
            throw $this->valueError($condition->column);
        }
        return $row[$condition->column] === $condition->equals;
    }

    private function validSourceValue(
        mixed $value,
        CompanyBackupReference $reference,
        string $targetColumn,
    ): bool {
        if ($reference->mapping === CompanyBackupReferenceMapping::Actor
            || $reference->mapping === CompanyBackupReferenceMapping::CredentialDecision
        ) {
            return is_int($value) && $value > 0;
        }
        if ($reference->mapping === CompanyBackupReferenceMapping::TenantIdOrZero) {
            return is_int($value) && $value >= 0;
        }
        if (!is_int($value) && !is_string($value)) {
            return false;
        }
        if (is_string($value)) {
            return $value !== '';
        }
        return ($targetColumn !== 'id' && !str_ends_with($targetColumn, '_id'))
            || $value > 0;
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

    private function valueError(string $column): CompanyBackupDataSourceException
    {
        return new CompanyBackupDataSourceException(
            'data_reference_value_invalid',
            $this->registryKey,
            $column,
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

    /**
     * @return list<list<string>>
     */
    private function targetReferenceKeys(
        TenantDataDefinition $target,
        CompanyBackupReference $reference,
    ): array {
        $value = $target->details['reference_keys'] ?? null;
        if (!is_array($value) || !array_is_list($value) || $value === []) {
            throw $this->targetError($reference);
        }
        $keys = [];
        $signatures = [];
        foreach ($value as $item) {
            if (!is_array($item) || !array_is_list($item) || count($item) < 2) {
                throw $this->targetError($reference);
            }
            $key = [];
            $columns = [];
            foreach ($item as $column) {
                if (!is_string($column)
                    || preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $column) !== 1
                    || isset($columns[$column])
                ) {
                    throw $this->targetError($reference);
                }
                $columns[$column] = true;
                $key[] = $column;
            }
            if ($key[0] !== 'supplier_id') {
                throw $this->targetError($reference);
            }
            $signature = implode(',', $key);
            if (isset($signatures[$signature])) {
                throw $this->targetError($reference);
            }
            $signatures[$signature] = true;
            $keys[] = $key;
        }
        $ordered = $keys;
        usort(
            $ordered,
            static fn (array $left, array $right): int => strcmp(
                implode(',', $left),
                implode(',', $right),
            ),
        );
        if ($ordered !== $keys) {
            throw $this->targetError($reference);
        }
        return $keys;
    }
}
