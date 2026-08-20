<?php

declare(strict_types=1);

namespace MyInvoice\Service\TenantTransfer\Registry;

use MyInvoice\Service\TenantTransfer\Fingerprint\TenantSchemaTableInventory;

/** Fail-closed kontrola úplnosti tabulek a politik citlivých sloupců. */
final class TenantDataRegistryCoverageValidator
{
    /** @param array<mixed> $inventory */
    public function assertComplete(
        TenantDataRegistry $registry,
        array $inventory,
        string $profile = TenantDataRegistry::TRANSFER_PROFILE,
    ): void {
        if (!$registry->isComplete($profile)) {
            throw new IncompleteTenantDataRegistry(
                'Coverage nelze ověřit pro neúplný tenantový profil.',
            );
        }

        $issues = $this->issues($registry, $inventory, $profile);
        if ($issues !== []) {
            throw new IncompleteTenantDataRegistryCoverage($issues);
        }
    }

    /**
     * Bezpečný report použitelný i během postupného sestavování draft registru.
     *
     * @param array<mixed> $inventory
     * @return list<string>
     */
    public function issues(
        TenantDataRegistry $registry,
        array $inventory,
        string $profile = TenantDataRegistry::TRANSFER_PROFILE,
    ): array {

        $tables = $this->validatedInventory($inventory);
        $definitions = [];
        foreach ($registry->definitionsFor($profile) as $definition) {
            if ($definition->kind !== TenantDataObjectKind::Table) {
                continue;
            }
            if (!str_starts_with($definition->key, 'table:')) {
                throw new \InvalidArgumentException(
                    'Tabulková definice tenantového registru nemá prefix table:.',
                );
            }
            $tableName = substr($definition->key, strlen('table:'));
            if (preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $tableName) !== 1) {
                throw new \InvalidArgumentException(
                    'Tenantový registr obsahuje neplatný název tabulky.',
                );
            }
            $definitions[$tableName] = $definition;
        }

        $issues = [];
        foreach ($tables as $tableName => $table) {
            $definition = $definitions[$tableName] ?? null;
            if ($definition === null) {
                $issues[] = 'unregistered_table:' . $tableName;
                continue;
            }
            array_push(
                $issues,
                ...$this->primaryKeyIssues($definition, $table),
                ...$this->policyCoverageIssues($definition, $table, $tables),
                ...$this->secretCoverageIssues($definition, $table),
            );
        }
        foreach (array_diff(array_keys($definitions), array_keys($tables)) as $tableName) {
            $issues[] = 'registered_table_missing:' . $tableName;
        }

        sort($issues, SORT_STRING);
        return $issues;
    }

    /** @return list<string> */
    private function primaryKeyIssues(
        TenantDataDefinition $definition,
        TenantSchemaTableInventory $table,
    ): array {
        $primaryKey = $definition->details['primary_key'] ?? null;
        if (!is_array($primaryKey)
            || !array_is_list($primaryKey)
            || $primaryKey !== $table->primaryKey
        ) {
            return ['primary_key_mismatch:' . $table->name];
        }
        return [];
    }

    /**
     * @param array<mixed> $inventory
     * @return array<string,TenantSchemaTableInventory>
     */
    private function validatedInventory(array $inventory): array
    {
        if (!array_is_list($inventory) || $inventory === []) {
            throw new \InvalidArgumentException('Inventura databázového schématu je prázdná nebo neplatná.');
        }
        $tables = [];
        foreach ($inventory as $table) {
            if (!$table instanceof TenantSchemaTableInventory) {
                throw new \InvalidArgumentException('Inventura databázového schématu obsahuje neplatnou tabulku.');
            }
            if (isset($tables[$table->name])) {
                throw new \InvalidArgumentException('Inventura databázového schématu obsahuje duplicitní tabulku.');
            }
            $tables[$table->name] = $table;
        }
        ksort($tables, SORT_STRING);
        return $tables;
    }

    /**
     * @param array<string,TenantSchemaTableInventory> $tables
     * @return list<string>
     */
    private function policyCoverageIssues(
        TenantDataDefinition $definition,
        TenantSchemaTableInventory $table,
        array $tables,
    ): array {
        return match ($definition->policy) {
            TenantDataPolicy::TenantRoot => $this->directOwnershipIssues(
                $definition,
                $table,
                'selected_supplier',
                'id',
            ),
            TenantDataPolicy::TenantOwned => $this->directOwnershipIssues(
                $definition,
                $table,
                'supplier_id',
                'supplier_id',
            ),
            TenantDataPolicy::TenantOwnedIndirect => $this->indirectOwnershipIssues(
                $definition,
                $table,
                $tables,
            ),
            TenantDataPolicy::TenantRelation => $this->directOwnershipIssues(
                $definition,
                $table,
                'supplier_relation',
                'supplier_id',
            ),
            TenantDataPolicy::GlobalReference => $this->globalReferenceIssues(
                $definition,
                $table,
            ),
            TenantDataPolicy::InstanceOwned,
            TenantDataPolicy::RuntimeDerived,
            TenantDataPolicy::Unsupported => $this->reasonIssues($definition, $table),
            TenantDataPolicy::PersonalSecretAttachment => $this->personalSecretIssues(
                $definition,
                $table,
            ),
        };
    }

    /** @return list<string> */
    private function directOwnershipIssues(
        TenantDataDefinition $definition,
        TenantSchemaTableInventory $table,
        string $expectedStrategy,
        string $expectedColumn,
    ): array {
        $ownership = $definition->details['ownership'] ?? null;
        if (!is_array($ownership)
            || ($ownership['strategy'] ?? null) !== $expectedStrategy
            || ($ownership['column'] ?? null) !== $expectedColumn
        ) {
            return ['invalid_ownership_policy:' . $table->name];
        }
        if (!in_array($expectedColumn, $table->columns, true)) {
            return ['ownership_column_missing:' . $table->name . '.' . $expectedColumn];
        }
        return [];
    }

    /**
     * @param array<string,TenantSchemaTableInventory> $tables
     * @return list<string>
     */
    private function indirectOwnershipIssues(
        TenantDataDefinition $definition,
        TenantSchemaTableInventory $table,
        array $tables,
    ): array {
        $ownership = $definition->details['ownership'] ?? null;
        $strategy = is_array($ownership) ? ($ownership['strategy'] ?? null) : null;
        if (!is_array($ownership)
            || !in_array(
                $strategy,
                ['foreign_key_path', 'soft_reference_path'],
                true,
            )
        ) {
            return ['invalid_ownership_policy:' . $table->name];
        }
        $path = $ownership['path'] ?? null;
        if (!is_array($path) || !array_is_list($path) || $path === []) {
            return ['invalid_ownership_path:' . $table->name];
        }

        $issues = [];
        $current = $table;
        foreach ($path as $index => $step) {
            if (!is_array($step) || array_is_list($step)) {
                $issues[] = 'invalid_ownership_path:' . $table->name;
                break;
            }
            $fromColumn = $step['from_column'] ?? null;
            $toTable = $step['to_table'] ?? null;
            $toColumn = $step['to_column'] ?? null;
            if (!is_string($fromColumn)
                || !is_string($toTable)
                || !is_string($toColumn)
                || preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $fromColumn) !== 1
                || preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $toTable) !== 1
                || preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $toColumn) !== 1
            ) {
                $issues[] = 'invalid_ownership_path:' . $table->name;
                break;
            }
            if (!in_array($fromColumn, $current->columns, true)) {
                $issues[] = 'ownership_path_column_missing:'
                    . $current->name . '.' . $fromColumn;
                break;
            }
            if ($strategy === 'foreign_key_path'
                && !$this->hasForeignKey(
                    $current,
                    $fromColumn,
                    $toTable,
                    $toColumn,
                )
            ) {
                $issues[] = 'ownership_path_fk_missing:'
                    . $current->name . '.' . $fromColumn
                    . '->' . $toTable . '.' . $toColumn;
                break;
            }
            $target = $tables[$toTable] ?? null;
            if ($target === null || !in_array($toColumn, $target->columns, true)) {
                $issues[] = 'ownership_path_target_missing:'
                    . $toTable . '.' . $toColumn;
                break;
            }
            $current = $target;

            if ($index === array_key_last($path)
                && ($toTable !== 'supplier' || $toColumn !== 'id')
            ) {
                $issues[] = 'ownership_path_not_tenant_root:' . $table->name;
            }
        }
        return $issues;
    }

    private function hasForeignKey(
        TenantSchemaTableInventory $table,
        string $column,
        string $referencedTable,
        string $referencedColumn,
    ): bool {
        foreach ($table->foreignKeys as $foreignKey) {
            if ($foreignKey->column === $column
                && $foreignKey->referencedTable === $referencedTable
                && $foreignKey->referencedColumn === $referencedColumn
            ) {
                return true;
            }
        }
        return false;
    }

    /** @return list<string> */
    private function globalReferenceIssues(
        TenantDataDefinition $definition,
        TenantSchemaTableInventory $table,
    ): array {
        $mapping = $definition->details['mapping'] ?? null;
        $keys = is_array($mapping) ? ($mapping['keys'] ?? null) : null;
        if (!is_array($mapping)
            || ($mapping['strategy'] ?? null) !== 'natural_key'
            || !is_array($keys)
            || !array_is_list($keys)
            || $keys === []
        ) {
            return ['invalid_global_mapping:' . $table->name];
        }
        foreach ($keys as $key) {
            if (!is_string($key)) {
                return ['invalid_global_mapping_key:' . $table->name];
            }
            if (!in_array($key, $table->columns, true)) {
                return ['global_mapping_column_missing:' . $table->name . '.' . $key];
            }
        }
        return [];
    }

    /** @return list<string> */
    private function reasonIssues(
        TenantDataDefinition $definition,
        TenantSchemaTableInventory $table,
    ): array {
        $reason = $definition->details['reason'] ?? null;
        if (!is_string($reason)
            || preg_match('/^[a-z][a-z0-9._-]{2,127}$/D', $reason) !== 1
        ) {
            return ['missing_policy_reason:' . $table->name];
        }
        return [];
    }

    /** @return list<string> */
    private function personalSecretIssues(
        TenantDataDefinition $definition,
        TenantSchemaTableInventory $table,
    ): array {
        if (($definition->details['consent'] ?? null) !== 'source_and_target_owner'
            || ($definition->details['default_selected'] ?? null) !== false
        ) {
            return ['invalid_personal_secret_policy:' . $table->name];
        }
        return [];
    }

    /** @return list<string> */
    private function secretCoverageIssues(
        TenantDataDefinition $definition,
        TenantSchemaTableInventory $table,
    ): array {
        $rawPolicies = $definition->details['secrets'] ?? [];
        if (!is_array($rawPolicies) || (array_is_list($rawPolicies) && $rawPolicies !== [])) {
            return ['invalid_secret_registry:' . $table->name];
        }

        $issues = [];
        $declaredColumns = [];
        foreach ($rawPolicies as $column => $declaration) {
            if (!is_string($column) || !in_array($column, $table->columns, true)) {
                $issues[] = 'secret_policy_unknown_column:' . $table->name . '.' . (string) $column;
                continue;
            }
            $declaredColumns[$column] = true;
            if (!is_array($declaration) || array_is_list($declaration)) {
                $issues[] = 'invalid_secret_policy:' . $table->name . '.' . $column;
                continue;
            }
            $policy = $declaration['policy'] ?? null;
            if (!is_string($policy)
                || TenantSecretPolicy::tryFrom($policy) === null
            ) {
                $issues[] = 'invalid_secret_policy:' . $table->name . '.' . $column;
                continue;
            }
            if ($policy === 'not_secret') {
                $reason = $declaration['reason'] ?? null;
                if (!is_string($reason)
                    || preg_match('/^[a-z][a-z0-9._-]{2,127}$/D', $reason) !== 1
                ) {
                    $issues[] = 'missing_not_secret_reason:' . $table->name . '.' . $column;
                    continue;
                }
            }
        }

        foreach ($table->columns as $column) {
            if (TenantSecretColumnDetector::matches($column)
                && !isset($declaredColumns[$column])
            ) {
                $issues[] = 'secret_policy_missing:' . $table->name . '.' . $column;
            }
        }
        return $issues;
    }
}
