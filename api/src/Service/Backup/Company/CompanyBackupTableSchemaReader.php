<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

use MyInvoice\Service\Backup\Registry\TenantDataRegistry;
use PDO;
use PDOStatement;

/** Jediná runtime interpretace tabulek, sloupců, klíčů a jejich referencí. */
final class CompanyBackupTableSchemaReader
{
    /** @return list<string> */
    public function tableNames(PDO $pdo): array
    {
        $registryKey = 'profile:' . TenantDataRegistry::COMPANY_BACKUP_PROFILE;
        $rows = $this->fetchAll(
            $pdo,
            'SELECT `TABLE_NAME` FROM `information_schema`.`TABLES`'
            . ' WHERE `TABLE_SCHEMA` = DATABASE()'
            . " AND `TABLE_TYPE` IN ('BASE TABLE', 'SYSTEM VERSIONED')"
            . ' ORDER BY `TABLE_NAME`',
            [],
            PDO::FETCH_COLUMN,
            $registryKey,
            'data_schema_inventory_failed',
        );
        $tables = [];
        $seen = [];
        foreach ($rows as $table) {
            if (!is_string($table)
                || preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $table) !== 1
                || isset($seen[$table])
            ) {
                throw new CompanyBackupDataSourceException(
                    'data_schema_inventory_invalid',
                    $registryKey,
                );
            }
            $seen[$table] = true;
            $tables[] = $table;
        }
        return $tables;
    }

    public function read(
        PDO $pdo,
        CompanyBackupTableProjection $projection,
    ): CompanyBackupTableSchema {
        $columnsSql = 'SELECT `c`.`COLUMN_NAME`, `c`.`EXTRA`,'
            . ' `c`.`GENERATION_EXPRESSION`, `t`.`TABLE_TYPE`'
            . ' FROM `information_schema`.`COLUMNS` AS `c`'
            . ' JOIN `information_schema`.`TABLES` AS `t`'
            . ' ON `t`.`TABLE_SCHEMA` = `c`.`TABLE_SCHEMA`'
            . ' AND `t`.`TABLE_NAME` = `c`.`TABLE_NAME`'
            . ' WHERE `c`.`TABLE_SCHEMA` = DATABASE() AND `c`.`TABLE_NAME` = ?'
            . ' ORDER BY `c`.`ORDINAL_POSITION`';
        $rows = $this->fetchAll(
            $pdo,
            $columnsSql,
            [$projection->name],
            PDO::FETCH_ASSOC,
            $projection->registryKey,
            'data_schema_read_failed',
        );
        if ($rows === []) {
            throw new CompanyBackupDataSourceException(
                'data_table_missing',
                $projection->registryKey,
            );
        }

        $columns = [];
        $generated = [];
        foreach ($rows as $row) {
            if (!is_array($row) || array_is_list($row)) {
                throw new CompanyBackupDataSourceException(
                    'data_schema_invalid',
                    $projection->registryKey,
                );
            }
            $column = $row['COLUMN_NAME'] ?? null;
            $extra = $row['EXTRA'] ?? null;
            $generation = $row['GENERATION_EXPRESSION'] ?? null;
            $tableType = $row['TABLE_TYPE'] ?? null;
            if (!is_string($column)
                || preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $column) !== 1
                || !is_string($extra)
                || !is_string($tableType)
                || !is_string($generation) && $generation !== null
                || !in_array($tableType, ['BASE TABLE', 'SYSTEM VERSIONED'], true)
            ) {
                throw new CompanyBackupDataSourceException(
                    'data_schema_invalid',
                    $projection->registryKey,
                );
            }
            $columns[] = $column;
            if (($generation !== null && $generation !== '')
                || str_contains(strtoupper($extra), 'GENERATED')
            ) {
                $generated[] = $column;
            }
        }

        $primaryRows = $this->fetchAll(
            $pdo,
            'SELECT `COLUMN_NAME`'
            . ' FROM `information_schema`.`KEY_COLUMN_USAGE`'
            . ' WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = ?'
            . " AND `CONSTRAINT_NAME` = 'PRIMARY'"
            . ' ORDER BY `ORDINAL_POSITION`',
            [$projection->name],
            PDO::FETCH_COLUMN,
            $projection->registryKey,
            'data_schema_read_failed',
        );
        $primaryKey = [];
        foreach ($primaryRows as $column) {
            if (!is_string($column)
                || preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $column) !== 1
            ) {
                throw new CompanyBackupDataSourceException(
                    'data_schema_invalid',
                    $projection->registryKey,
                );
            }
            $primaryKey[] = $column;
        }
        return new CompanyBackupTableSchema($columns, $generated, $primaryKey);
    }

    public function readReferences(
        PDO $pdo,
        CompanyBackupTableProjection $projection,
    ): CompanyBackupTableReferenceSchema {
        $columnRows = $this->fetchAll(
            $pdo,
            'SELECT `COLUMN_NAME`, `IS_NULLABLE`'
                . ' FROM `information_schema`.`COLUMNS`'
                . ' WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = ?'
                . ' ORDER BY `ORDINAL_POSITION`',
            [$projection->name],
            PDO::FETCH_ASSOC,
            $projection->registryKey,
            'data_reference_schema_read_failed',
        );
        if ($columnRows === []) {
            throw new CompanyBackupDataSourceException(
                'data_table_missing',
                $projection->registryKey,
            );
        }
        $nullableColumns = [];
        $seenColumns = [];
        foreach ($columnRows as $row) {
            if (!is_array($row) || array_is_list($row)) {
                throw $this->invalidReferenceSchema($projection);
            }
            $column = $row['COLUMN_NAME'] ?? null;
            $nullable = $row['IS_NULLABLE'] ?? null;
            if (!is_string($column)
                || preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $column) !== 1
                || !in_array($nullable, ['YES', 'NO'], true)
                || isset($seenColumns[$column])
            ) {
                throw $this->invalidReferenceSchema($projection);
            }
            $seenColumns[$column] = true;
            if ($nullable === 'YES') {
                $nullableColumns[] = $column;
            }
        }

        $foreignKeyRows = $this->fetchAll(
            $pdo,
            'SELECT `CONSTRAINT_NAME`, `COLUMN_NAME`, `ORDINAL_POSITION`,'
                . ' `REFERENCED_TABLE_NAME`, `REFERENCED_COLUMN_NAME`'
                . ' FROM `information_schema`.`KEY_COLUMN_USAGE`'
                . ' WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = ?'
                . ' AND `REFERENCED_TABLE_NAME` IS NOT NULL'
                . ' ORDER BY `CONSTRAINT_NAME`, `ORDINAL_POSITION`',
            [$projection->name],
            PDO::FETCH_ASSOC,
            $projection->registryKey,
            'data_reference_schema_read_failed',
        );
        /** @var array<string,array{target:string,columns:list<string>,target_columns:list<string>}> $groups */
        $groups = [];
        foreach ($foreignKeyRows as $row) {
            if (!is_array($row) || array_is_list($row)) {
                throw $this->invalidReferenceSchema($projection);
            }
            $constraint = $row['CONSTRAINT_NAME'] ?? null;
            $column = $row['COLUMN_NAME'] ?? null;
            $ordinal = $row['ORDINAL_POSITION'] ?? null;
            $target = $row['REFERENCED_TABLE_NAME'] ?? null;
            $targetColumn = $row['REFERENCED_COLUMN_NAME'] ?? null;
            $ordinalNumber = is_int($ordinal)
                ? $ordinal
                : (is_string($ordinal) && ctype_digit($ordinal) ? (int) $ordinal : 0);
            if (!is_string($constraint)
                || $constraint === ''
                || strlen($constraint) > 64
                || !is_string($column)
                || preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $column) !== 1
                || !is_string($target)
                || preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $target) !== 1
                || !is_string($targetColumn)
                || preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $targetColumn) !== 1
                || $ordinalNumber < 1
            ) {
                throw $this->invalidReferenceSchema($projection);
            }
            $group = $groups[$constraint] ?? [
                'target' => $target,
                'columns' => [],
                'target_columns' => [],
            ];
            if ($group['target'] !== $target
                || $ordinalNumber !== count($group['columns']) + 1
            ) {
                throw $this->invalidReferenceSchema($projection);
            }
            $group['columns'][] = $column;
            $group['target_columns'][] = $targetColumn;
            $groups[$constraint] = $group;
        }

        $foreignKeys = [];
        try {
            foreach ($groups as $group) {
                $foreignKeys[] = new CompanyBackupForeignKey(
                    $group['columns'],
                    $group['target'],
                    $group['target_columns'],
                );
            }
            return new CompanyBackupTableReferenceSchema($nullableColumns, $foreignKeys);
        } catch (\InvalidArgumentException) {
            throw $this->invalidReferenceSchema($projection);
        }
    }

    private function invalidReferenceSchema(
        CompanyBackupTableProjection $projection,
    ): CompanyBackupDataSourceException {
        return new CompanyBackupDataSourceException(
            'data_reference_schema_invalid',
            $projection->registryKey,
        );
    }

    /**
     * @param list<mixed> $params
     * @return list<mixed>
     */
    private function fetchAll(
        PDO $pdo,
        string $sql,
        array $params,
        int $fetchMode,
        string $registryKey,
        string $errorCode,
    ): array {
        $statement = null;
        try {
            $prepared = $pdo->prepare($sql);
            if (!$prepared instanceof PDOStatement) {
                throw new CompanyBackupDataSourceException($errorCode, $registryKey);
            }
            $statement = $prepared;
            if (!$statement->execute($params)) {
                throw new CompanyBackupDataSourceException($errorCode, $registryKey);
            }
            $rows = $statement->fetchAll($fetchMode);
            if (!array_is_list($rows)) {
                throw new CompanyBackupDataSourceException($errorCode, $registryKey);
            }
            if (!$statement->closeCursor()) {
                throw new CompanyBackupDataSourceException($errorCode, $registryKey);
            }
            $statement = null;
            return $rows;
        } catch (CompanyBackupDataSourceException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new CompanyBackupDataSourceException(
                $errorCode,
                $registryKey,
                previous: $e,
            );
        } finally {
            if ($statement instanceof PDOStatement) {
                try {
                    $statement->closeCursor();
                } catch (\Throwable) {
                    // Primární bezpečná chyba čtení má přednost před úklidem kurzoru.
                }
            }
        }
    }
}
