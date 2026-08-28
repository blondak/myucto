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
        private CompanyBackupTableSchemaReader $schemaReader = new CompanyBackupTableSchemaReader(),
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
        $schema = $this->schemaReader->read($snapshot, $projection);
        $projection->assertRuntimeSchema(
            $schema->columns,
            $schema->generatedColumns,
            $schema->primaryKey,
            $schema->binaryColumns,
        );
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
            $result[] = $this->encodeColumns($row, $projection);
        }
        return $result;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function encodeColumns(
        array $row,
        CompanyBackupTableProjection $projection,
    ): array {
        $projection->assertExportRow($row);
        foreach ($projection->columnCodecs as $column => $codec) {
            $row[$column] = $codec->encode(
                $row[$column],
                $projection->registryKey,
                $column,
            );
        }
        return $row;
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
