<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

use MyInvoice\Service\Backup\Registry\TenantDataDefinition;
use PDO;
use PDOStatement;

/** Dávkovaný, registry-driven zdroj tabulkových řádků z DB snapshotu. */
final readonly class CompanyBackupSqlRowSource implements CompanyBackupDataRowSource
{
    public function __construct(
        private CompanyBackupTenantSqlSelector $selector = new CompanyBackupTenantSqlSelector(),
        private int $batchSize = 1_000,
    ) {
        if ($batchSize < 1 || $batchSize > 10_000) {
            throw new \InvalidArgumentException('Velikost SQL dávky musí být mezi 1 a 10000.');
        }
    }

    /** @return iterable<int,array<string,mixed>> */
    public function rows(
        PDO $snapshot,
        int $supplierId,
        TenantDataDefinition $definition,
    ): iterable {
        if ($supplierId < 1) {
            throw new \InvalidArgumentException('Firma datového zdroje musí mít kladné ID.');
        }
        $projection = CompanyBackupTableProjection::fromDefinition($definition);
        [$columns, $generatedColumns, $primaryKey] = $this->runtimeSchema(
            $snapshot,
            $projection,
        );
        $projection->assertRuntimeSchema($columns, $generatedColumns, $primaryKey);
        $protectedColumn = $projection->requiredSecretEnvelopeColumn();
        if ($protectedColumn !== null) {
            throw new CompanyBackupDataSourceException(
                'data_secret_envelope_required',
                $projection->registryKey,
                $protectedColumn,
            );
        }
        $selection = $this->selector->select($projection, $supplierId);

        $offset = 0;
        while (true) {
            $page = $this->fetchPage($snapshot, $projection, $selection, $offset);
            foreach ($page as $row) {
                yield $row;
            }
            $count = count($page);
            if ($count < $this->batchSize) {
                break;
            }
            if ($offset > PHP_INT_MAX - $count) {
                throw new CompanyBackupDataSourceException(
                    'data_row_offset_overflow',
                    $projection->registryKey,
                );
            }
            $offset += $count;
        }
    }

    /**
     * @return array{0:list<string>,1:list<string>,2:list<string>}
     */
    private function runtimeSchema(
        PDO $snapshot,
        CompanyBackupTableProjection $projection,
    ): array {
        $columnsSql = 'SELECT `c`.`COLUMN_NAME`, `c`.`EXTRA`,'
            . ' `c`.`GENERATION_EXPRESSION`, `t`.`TABLE_TYPE`'
            . ' FROM `information_schema`.`COLUMNS` AS `c`'
            . ' JOIN `information_schema`.`TABLES` AS `t`'
            . ' ON `t`.`TABLE_SCHEMA` = `c`.`TABLE_SCHEMA`'
            . ' AND `t`.`TABLE_NAME` = `c`.`TABLE_NAME`'
            . ' WHERE `c`.`TABLE_SCHEMA` = DATABASE() AND `c`.`TABLE_NAME` = ?'
            . ' ORDER BY `c`.`ORDINAL_POSITION`';
        $rows = $this->fetchAll(
            $snapshot,
            $columnsSql,
            [$projection->name],
            PDO::FETCH_ASSOC,
            $projection,
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

        $primarySql = 'SELECT `COLUMN_NAME`'
            . ' FROM `information_schema`.`KEY_COLUMN_USAGE`'
            . ' WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = ?'
            . " AND `CONSTRAINT_NAME` = 'PRIMARY'"
            . ' ORDER BY `ORDINAL_POSITION`';
        $primaryRows = $this->fetchAll(
            $snapshot,
            $primarySql,
            [$projection->name],
            PDO::FETCH_COLUMN,
            $projection,
            'data_schema_read_failed',
        );
        $primaryKey = [];
        foreach ($primaryRows as $column) {
            if (!is_string($column)) {
                throw new CompanyBackupDataSourceException(
                    'data_schema_invalid',
                    $projection->registryKey,
                );
            }
            $primaryKey[] = $column;
        }
        return [$columns, $generated, $primaryKey];
    }

    /** @return list<array<string,mixed>> */
    private function fetchPage(
        PDO $snapshot,
        CompanyBackupTableProjection $projection,
        CompanyBackupSqlSelection $selection,
        int $offset,
    ): array {
        $columns = implode(', ', array_map(
            static fn (string $column): string => '`'
                . CompanyBackupTenantSqlSelector::SOURCE_ALIAS . '`.`' . $column . '`',
            $projection->dataColumns,
        ));
        $order = implode(', ', array_map(
            static fn (string $column): string => '`'
                . CompanyBackupTenantSqlSelector::SOURCE_ALIAS . '`.`' . $column . '`',
            $projection->primaryKey,
        ));
        $table = CompanyBackupTenantSqlSelector::quoteIdentifier(
            $projection->name,
            $projection->registryKey,
        );
        $alias = CompanyBackupTenantSqlSelector::quoteIdentifier(
            CompanyBackupTenantSqlSelector::SOURCE_ALIAS,
            $projection->registryKey,
        );
        $sql = 'SELECT ' . $columns . ' FROM ' . $table . ' AS ' . $alias
            . ' WHERE ' . $selection->where
            . ' ORDER BY ' . $order
            . ' LIMIT ' . $this->batchSize . ' OFFSET ' . $offset;
        $rows = $this->fetchAll(
            $snapshot,
            $sql,
            $selection->params,
            PDO::FETCH_ASSOC,
            $projection,
            'data_query_failed',
        );

        $result = [];
        foreach ($rows as $row) {
            if (!is_array($row)
                || array_is_list($row)
                || array_keys($row) !== $projection->dataColumns
            ) {
                throw new CompanyBackupDataSourceException(
                    'data_row_shape_invalid',
                    $projection->registryKey,
                );
            }
            $result[] = $row;
        }
        return $result;
    }

    /**
     * @param list<mixed> $params
     * @return list<mixed>
     */
    private function fetchAll(
        PDO $snapshot,
        string $sql,
        array $params,
        int $fetchMode,
        CompanyBackupTableProjection $projection,
        string $errorCode,
    ): array {
        $statement = null;
        try {
            $prepared = $snapshot->prepare($sql);
            if (!$prepared instanceof PDOStatement) {
                throw new CompanyBackupDataSourceException(
                    $errorCode,
                    $projection->registryKey,
                );
            }
            $statement = $prepared;
            if (!$statement->execute($params)) {
                throw new CompanyBackupDataSourceException(
                    $errorCode,
                    $projection->registryKey,
                );
            }
            $rows = $statement->fetchAll($fetchMode);
            if (!array_is_list($rows)) {
                throw new CompanyBackupDataSourceException(
                    $errorCode,
                    $projection->registryKey,
                );
            }
            if (!$statement->closeCursor()) {
                throw new CompanyBackupDataSourceException(
                    $errorCode,
                    $projection->registryKey,
                );
            }
            $statement = null;
            return $rows;
        } catch (CompanyBackupDataSourceException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new CompanyBackupDataSourceException(
                $errorCode,
                $projection->registryKey,
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
