<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

use MyInvoice\Service\Backup\Registry\TenantDataPolicy;

/** Překládá pouze allowlistované ownership politiky registru do parametrizovaného SQL. */
final class CompanyBackupTenantSqlSelector
{
    public const SOURCE_ALIAS = '_company_source';
    private const MAX_PATH_DEPTH = 16;
    private const MAX_REFERENCE_SOURCES = 64;

    public function select(
        CompanyBackupTableProjection $table,
        int $supplierId,
    ): CompanyBackupSqlSelection {
        if ($supplierId < 1) {
            throw new \InvalidArgumentException('Firma SQL selektoru musí mít kladné ID.');
        }

        $strategy = $table->ownership['strategy'] ?? null;
        if (!is_string($strategy)) {
            throw $this->error($table, 'data_ownership_invalid');
        }
        if ($table->policy === TenantDataPolicy::TenantRoot
            && $strategy === 'selected_supplier'
            && $table->name === 'supplier'
        ) {
            $column = $this->directColumn($table, 'id');
            return new CompanyBackupSqlSelection(
                self::column(self::SOURCE_ALIAS, $column) . ' = ?',
                [$supplierId],
            );
        }
        if ($table->policy === TenantDataPolicy::TenantOwned
            && $strategy === 'supplier_id'
        ) {
            $column = $this->directColumn($table, 'supplier_id');
            return new CompanyBackupSqlSelection(
                self::column(self::SOURCE_ALIAS, $column) . ' = ?',
                [$supplierId],
            );
        }
        if ($table->policy === TenantDataPolicy::TenantOwnedIndirect
            && $strategy === 'foreign_key_path'
        ) {
            return new CompanyBackupSqlSelection(
                $this->ownershipPathWhere($table),
                [$supplierId],
            );
        }
        if ($table->policy === TenantDataPolicy::TenantOwnedIndirect
            && $strategy === 'bank_transaction_relationships'
            && $table->name === 'bank_transactions'
        ) {
            $this->assertOwnershipKeys($table, ['strategy']);
            $this->assertDataColumns($table, ['id', 'matched_invoice_id']);
            return new CompanyBackupSqlSelection(
                $this->bankTransactionWhere(self::SOURCE_ALIAS),
                [$supplierId, $supplierId, $supplierId],
            );
        }
        if ($table->policy === TenantDataPolicy::GlobalReference
            && $strategy === 'bank_statement_relationships'
            && $table->name === 'bank_statements'
        ) {
            $this->assertOwnershipKeys($table, ['strategy']);
            $this->assertDataColumns($table, ['id']);
            return new CompanyBackupSqlSelection(
                self::column(self::SOURCE_ALIAS, 'id')
                . ' IN (SELECT ' . self::column('_related_transaction', 'statement_id')
                . ' FROM `bank_transactions` AS `_related_transaction`'
                . ' WHERE ' . self::column('_related_transaction', 'statement_id')
                . ' IS NOT NULL AND '
                . $this->bankTransactionWhere('_related_transaction') . ')',
                [$supplierId, $supplierId, $supplierId],
            );
        }
        if ($table->policy === TenantDataPolicy::GlobalReference
            && $strategy === 'accounting_period_currency'
            && $table->name === 'exchange_rates'
        ) {
            $this->assertOwnershipKeys($table, ['strategy']);
            $this->assertDataColumns($table, ['currency_code', 'rate_date']);
            return new CompanyBackupSqlSelection(
                self::column(self::SOURCE_ALIAS, 'currency_code')
                . ' IN (SELECT `code` FROM `currencies` WHERE `supplier_id` = ?)'
                . ' AND ' . self::column(self::SOURCE_ALIAS, 'rate_date')
                . ' BETWEEN (SELECT MIN(`starts_on`) FROM `accounting_periods`'
                . ' WHERE `supplier_id` = ?)'
                . ' AND (SELECT MAX(`ends_on`) FROM `accounting_periods`'
                . ' WHERE `supplier_id` = ?)',
                [$supplierId, $supplierId, $supplierId],
            );
        }
        if ($table->policy === TenantDataPolicy::GlobalReference
            && $strategy === 'tenant_reference_sources'
        ) {
            return $this->tenantReferenceSourcesSelection($table, $supplierId);
        }

        throw $this->error($table, 'data_ownership_unsupported');
    }

    private function directColumn(
        CompanyBackupTableProjection $table,
        string $expectedColumn,
    ): string
    {
        $this->assertOwnershipKeys($table, ['column', 'strategy']);
        $column = $table->ownership['column'] ?? null;
        if (!is_string($column) || $column !== $expectedColumn) {
            throw $this->error($table, 'data_ownership_invalid');
        }
        self::quoteIdentifier($column, $table->registryKey);
        $this->assertDataColumns($table, [$column]);
        return $column;
    }

    private function ownershipPathWhere(CompanyBackupTableProjection $table): string
    {
        $this->assertOwnershipKeys($table, ['path', 'strategy']);
        $path = $table->ownership['path'] ?? null;
        if (!is_array($path)
            || !array_is_list($path)
            || $path === []
            || count($path) > self::MAX_PATH_DEPTH
        ) {
            throw $this->error($table, 'data_ownership_path_invalid');
        }

        $from = '';
        $joins = '';
        $firstCondition = '';
        $previousAlias = null;
        $lastTable = null;
        $lastColumn = null;
        foreach ($path as $index => $step) {
            if (!is_array($step) || array_is_list($step)) {
                throw $this->error($table, 'data_ownership_path_invalid');
            }
            $keys = array_keys($step);
            sort($keys, SORT_STRING);
            if ($keys !== ['from_column', 'to_column', 'to_table']) {
                throw $this->error($table, 'data_ownership_path_invalid');
            }
            $fromColumn = $this->pathIdentifier($table, $step, 'from_column');
            $toTable = $this->pathIdentifier($table, $step, 'to_table');
            $toColumn = $this->pathIdentifier($table, $step, 'to_column');
            $alias = '_tenant_path_' . $index;
            if ($index === 0) {
                $this->assertDataColumns($table, [$fromColumn]);
                $from = self::quoteIdentifier($toTable, $table->registryKey)
                    . ' AS ' . self::quoteIdentifier($alias, $table->registryKey);
                $firstCondition = self::column(self::SOURCE_ALIAS, $fromColumn)
                    . ' = ' . self::column($alias, $toColumn);
            } else {
                if ($previousAlias === null) {
                    throw $this->error($table, 'data_ownership_path_invalid');
                }
                $joins .= ' JOIN ' . self::quoteIdentifier($toTable, $table->registryKey)
                    . ' AS ' . self::quoteIdentifier($alias, $table->registryKey)
                    . ' ON ' . self::column($previousAlias, $fromColumn)
                    . ' = ' . self::column($alias, $toColumn);
            }
            $previousAlias = $alias;
            $lastTable = $toTable;
            $lastColumn = $toColumn;
        }

        if ($lastTable !== 'supplier' || $lastColumn !== 'id') {
            throw $this->error($table, 'data_ownership_path_invalid');
        }
        return 'EXISTS (SELECT 1 FROM ' . $from . $joins
            . ' WHERE ' . $firstCondition
            . ' AND ' . self::column($previousAlias, $lastColumn) . ' = ?)';
    }

    private function bankTransactionWhere(string $alias): string
    {
        return '(' . self::column($alias, 'id')
            . ' IN (SELECT `bank_transaction_id` FROM `payment_matches`'
            . ' WHERE `supplier_id` = ?) OR '
            . self::column($alias, 'matched_invoice_id')
            . ' IN (SELECT `id` FROM `invoices` WHERE `supplier_id` = ?) OR '
            . self::column($alias, 'id')
            . ' IN (SELECT `last_bank_transaction_id` FROM `client_bank_accounts`'
            . ' WHERE `supplier_id` = ? AND `last_bank_transaction_id` IS NOT NULL))';
    }

    private function tenantReferenceSourcesSelection(
        CompanyBackupTableProjection $table,
        int $supplierId,
    ): CompanyBackupSqlSelection {
        $this->assertOwnershipKeys($table, ['sources', 'strategy']);
        $sources = $table->ownership['sources'] ?? null;
        if ($table->primaryKey !== ['id']
            || !is_array($sources)
            || !array_is_list($sources)
            || $sources === []
            || count($sources) > self::MAX_REFERENCE_SOURCES
        ) {
            throw $this->error($table, 'data_ownership_reference_sources_invalid');
        }
        $this->assertDataColumns($table, ['id']);

        $conditions = [];
        $params = [];
        $previousSignature = null;
        foreach ($sources as $index => $source) {
            if (!is_array($source) || array_is_list($source)) {
                throw $this->error($table, 'data_ownership_reference_sources_invalid');
            }
            $keys = array_keys($source);
            sort($keys, SORT_STRING);
            if ($keys !== ['reference_column', 'supplier_column', 'table']) {
                throw $this->error($table, 'data_ownership_reference_sources_invalid');
            }
            $sourceTable = $this->referenceSourceIdentifier($table, $source, 'table');
            $referenceColumn = $this->referenceSourceIdentifier(
                $table,
                $source,
                'reference_column',
            );
            $supplierColumn = $this->referenceSourceIdentifier(
                $table,
                $source,
                'supplier_column',
            );
            $expectedSupplierColumn = $sourceTable === 'supplier'
                ? 'id'
                : 'supplier_id';
            $signature = $sourceTable . ':' . $referenceColumn . ':' . $supplierColumn;
            if ($supplierColumn !== $expectedSupplierColumn
                || ($previousSignature !== null
                    && strcmp($previousSignature, $signature) >= 0)
            ) {
                throw $this->error($table, 'data_ownership_reference_sources_invalid');
            }
            $previousSignature = $signature;

            $alias = '_tenant_reference_' . $index;
            $conditions[] = self::column(self::SOURCE_ALIAS, 'id')
                . ' IN (SELECT ' . self::column($alias, $referenceColumn)
                . ' FROM ' . self::quoteIdentifier($sourceTable, $table->registryKey)
                . ' AS ' . self::quoteIdentifier($alias, $table->registryKey)
                . ' WHERE ' . self::column($alias, $supplierColumn) . ' = ?'
                . ' AND ' . self::column($alias, $referenceColumn) . ' IS NOT NULL)';
            $params[] = $supplierId;
        }

        return new CompanyBackupSqlSelection(
            '(' . implode(' OR ', $conditions) . ')',
            $params,
        );
    }

    /** @param list<string> $expected */
    private function assertOwnershipKeys(
        CompanyBackupTableProjection $table,
        array $expected,
    ): void {
        $keys = array_keys($table->ownership);
        sort($keys, SORT_STRING);
        if ($keys !== $expected) {
            throw $this->error($table, 'data_ownership_invalid');
        }
    }

    /** @param list<string> $columns */
    private function assertDataColumns(
        CompanyBackupTableProjection $table,
        array $columns,
    ): void {
        foreach ($columns as $column) {
            if (!$table->hasDataColumn($column)) {
                throw new CompanyBackupDataSourceException(
                    'data_ownership_column_not_exported',
                    $table->registryKey,
                    $column,
                );
            }
        }
    }

    /** @param array<mixed> $step */
    private function pathIdentifier(
        CompanyBackupTableProjection $table,
        array $step,
        string $field,
    ): string {
        $value = $step[$field] ?? null;
        if (!is_string($value)) {
            throw $this->error($table, 'data_ownership_path_invalid');
        }
        self::quoteIdentifier($value, $table->registryKey);
        return $value;
    }

    /** @param array<mixed> $source */
    private function referenceSourceIdentifier(
        CompanyBackupTableProjection $table,
        array $source,
        string $field,
    ): string {
        $value = $source[$field] ?? null;
        if (!is_string($value)
            || preg_match('/^[a-z_][a-z0-9_]{0,63}$/D', $value) !== 1
        ) {
            throw $this->error($table, 'data_ownership_reference_sources_invalid');
        }
        return $value;
    }

    private static function column(string $alias, string $column): string
    {
        return '`' . $alias . '`.`' . $column . '`';
    }

    public static function quoteIdentifier(string $identifier, string $registryKey): string
    {
        if (preg_match('/^[a-z_][a-z0-9_]{0,63}$/D', $identifier) !== 1) {
            throw new CompanyBackupDataSourceException(
                'data_sql_identifier_invalid',
                $registryKey,
            );
        }
        return '`' . $identifier . '`';
    }

    private function error(
        CompanyBackupTableProjection $table,
        string $code,
    ): CompanyBackupDataSourceException {
        return new CompanyBackupDataSourceException($code, $table->registryKey);
    }
}
