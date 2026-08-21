<?php

declare(strict_types=1);

namespace MyInvoice\Service\TenantTransfer\Registry;

use MyInvoice\Service\TenantTransfer\Fingerprint\TenantSchemaTableInventory;

/** Kontrola uzavřených map polymorfních tenantových referencí bez fyzického FK. */
final class TenantDataSoftReferenceCoverageValidator
{
    /**
     * @param array<string,TenantSchemaTableInventory> $tables
     * @param array<string,TenantDataDefinition> $definitions
     * @return list<string>
     */
    public function issues(
        TenantDataDefinition $definition,
        TenantSchemaTableInventory $table,
        array $tables,
        array $definitions,
    ): array {
        $raw = $definition->details['soft_references'] ?? [];
        if (!is_array($raw) || (array_is_list($raw) && $raw !== [])) {
            return ['invalid_soft_reference_registry:' . $table->name];
        }

        $issues = [];
        foreach ($raw as $name => $reference) {
            if (!is_string($name)
                || preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $name) !== 1
                || !is_array($reference)
                || array_is_list($reference)
                || ($reference['strategy'] ?? null)
                    !== 'polymorphic_tenant_entity'
            ) {
                $issues[] = 'invalid_soft_reference:'
                    . $table->name . '.' . (string) $name;
                continue;
            }
            $typeColumn = $reference['type_column'] ?? null;
            $idColumn = $reference['id_column'] ?? null;
            $targets = $reference['targets'] ?? null;
            if (!is_string($typeColumn)
                || !is_string($idColumn)
                || !is_array($targets)
                || array_is_list($targets)
            ) {
                $issues[] = 'invalid_soft_reference:'
                    . $table->name . '.' . $name;
                continue;
            }
            foreach ([$typeColumn, $idColumn] as $column) {
                if (!in_array($column, $table->columns, true)) {
                    $issues[] = 'soft_reference_column_missing:'
                        . $table->name . '.' . $column;
                }
            }
            foreach ($table->foreignKeys as $foreignKey) {
                if ($foreignKey->column === $idColumn) {
                    $issues[] = 'soft_reference_has_fk:'
                        . $table->name . '.' . $idColumn;
                    break;
                }
            }
            if (($reference['unknown_value'] ?? null) !== 'block') {
                $issues[] = 'soft_reference_unknown_value_not_blocked:'
                    . $table->name . '.' . $name;
            }
            $targetTypes = [];
            $targetMapValid = true;
            foreach ($targets as $type => $targetTable) {
                if (!is_string($type)
                    || !is_string($targetTable)
                    || preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $type) !== 1
                    || preg_match(
                        '/^[a-z][a-z0-9_]{0,63}$/D',
                        $targetTable,
                    ) !== 1
                ) {
                    $targetMapValid = false;
                    $issues[] = 'invalid_soft_reference_target:'
                        . $table->name . '.' . $name;
                    continue;
                }
                $targetTypes[] = $type;
                $targetDefinition = $definitions[$targetTable] ?? null;
                if (!isset($tables[$targetTable])
                    || $targetDefinition === null
                ) {
                    $issues[] = 'soft_reference_target_unregistered:'
                        . $table->name . '.' . $name . '.' . $type
                        . '->' . $targetTable;
                    continue;
                }
                if (!in_array($targetDefinition->policy, [
                    TenantDataPolicy::TenantRoot,
                    TenantDataPolicy::TenantOwned,
                    TenantDataPolicy::TenantOwnedIndirect,
                    TenantDataPolicy::TenantRelation,
                ], true)) {
                    $issues[] = 'soft_reference_target_not_transferable:'
                        . $table->name . '.' . $name . '.' . $type
                        . '->' . $targetTable;
                }
            }
            $enumValues = $table->enumValues[$typeColumn] ?? null;
            if ($targetMapValid && $enumValues !== null) {
                sort($targetTypes, SORT_STRING);
                sort($enumValues, SORT_STRING);
                if ($targetTypes !== $enumValues) {
                    $issues[] = 'soft_reference_target_map_mismatch:'
                        . $table->name . '.' . $name;
                }
            }
        }

        sort($issues, SORT_STRING);
        return $issues;
    }
}
