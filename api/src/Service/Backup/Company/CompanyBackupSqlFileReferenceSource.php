<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

use MyInvoice\Service\Backup\Registry\TenantDataDefinition;
use MyInvoice\Service\Backup\Registry\TenantDataPolicy;
use MyInvoice\Service\Backup\Registry\TenantDataRegistry;
use PDO;
use PDOStatement;

/** Dávkovaný SQL zdroj allowlistovaných scalar a JSON souborových referencí. */
final readonly class CompanyBackupSqlFileReferenceSource implements
    CompanyBackupFileReferenceSource
{
    private const PATH_ALIAS = '_file_source_path';

    public function __construct(private int $batchSize = 1_000)
    {
        if ($batchSize < 1 || $batchSize > 10_000) {
            throw new \InvalidArgumentException(
                'Velikost dávky souborových referencí musí být mezi 1 a 10000.',
            );
        }
    }

    /** @return iterable<CompanyBackupFileReference> */
    public function references(
        PDO $snapshot,
        int $supplierId,
        TenantDataDefinition $definition,
        TenantDataRegistry $registry,
    ): iterable {
        if ($supplierId < 1) {
            throw new \InvalidArgumentException('Firma SQL file zdroje musí mít kladné ID.');
        }
        $area = CompanyBackupFileAreaProjection::fromDefinition(
            $definition,
            $registry,
        );
        foreach ($area->owners->owners as $owner) {
            $target = $registry->definition($owner->registryKey);
            if (!$target instanceof TenantDataDefinition) {
                throw $this->error('file_owner_metadata_invalid', $area);
            }
            $selection = $this->selection($target, $supplierId, $area);
            foreach ($this->ownerReferences(
                $snapshot,
                $area,
                $target,
                $owner,
                $selection,
                $supplierId,
            ) as $reference) {
                yield $reference;
            }
        }
    }

    /** @return iterable<CompanyBackupFileReference> */
    private function ownerReferences(
        PDO $snapshot,
        CompanyBackupFileAreaProjection $area,
        TenantDataDefinition $target,
        CompanyBackupFileOwnerDefinition $owner,
        CompanyBackupSqlSelection $selection,
        int $supplierId,
    ): iterable {
        $primaryKey = $this->primaryKey($target, $area);
        $offset = 0;
        while (true) {
            $rows = $this->fetchPage(
                $snapshot,
                $area,
                $target,
                $owner,
                $selection,
                $primaryKey,
                $offset,
            );
            foreach ($rows as $row) {
                $storedPath = $row[self::PATH_ALIAS] ?? null;
                $key = [];
                foreach ($primaryKey as $column) {
                    if (!array_key_exists($column, $row)) {
                        throw $this->error('file_reference_row_invalid', $area);
                    }
                    $key[$column] = $row[$column];
                }
                try {
                    $sourcePath = $owner->relativeSourcePath($storedPath);
                } catch (\InvalidArgumentException $e) {
                    throw $this->error(
                        'file_reference_path_invalid',
                        $area,
                        previous: $e,
                    );
                }
                if (!$area->pathPolicy->accepts($sourcePath, $supplierId)) {
                    throw $this->error(
                        'file_reference_tenant_mismatch',
                        $area,
                        $sourcePath,
                    );
                }
                yield new CompanyBackupFileReference(
                    $sourcePath,
                    $owner->registryKey,
                    $key,
                    $owner->column,
                    $owner->path,
                );
            }
            $count = count($rows);
            if ($count < $this->batchSize) {
                break;
            }
            if ($offset > PHP_INT_MAX - $count) {
                throw $this->error('file_reference_offset_overflow', $area);
            }
            $offset += $count;
        }
    }

    /**
     * @param list<string> $primaryKey
     * @return list<array<string,mixed>>
     */
    private function fetchPage(
        PDO $snapshot,
        CompanyBackupFileAreaProjection $area,
        TenantDataDefinition $target,
        CompanyBackupFileOwnerDefinition $owner,
        CompanyBackupSqlSelection $selection,
        array $primaryKey,
        int $offset,
    ): array {
        $alias = CompanyBackupTenantSqlSelector::SOURCE_ALIAS;
        $keyColumns = array_map(
            static fn (string $column): string => self::column($alias, $column),
            $primaryKey,
        );
        $pathExpression = $this->pathExpression($alias, $owner);
        $table = CompanyBackupTenantSqlSelector::quoteIdentifier(
            $target->name(),
            $area->registryKey,
        );
        $quotedAlias = CompanyBackupTenantSqlSelector::quoteIdentifier(
            $alias,
            $area->registryKey,
        );
        $sql = 'SELECT ' . implode(', ', $keyColumns)
            . ', ' . $pathExpression . ' AS `' . self::PATH_ALIAS . '`'
            . ' FROM ' . $table . ' AS ' . $quotedAlias
            . ' WHERE ' . $selection->where
            . ' AND ' . $pathExpression . ' IS NOT NULL'
            . " AND " . $pathExpression . " <> ''"
            . ' ORDER BY ' . implode(', ', $keyColumns)
            . ' LIMIT ' . $this->batchSize . ' OFFSET ' . $offset;

        $statement = null;
        try {
            $prepared = $snapshot->prepare($sql);
            if (!$prepared instanceof PDOStatement) {
                throw $this->error('file_reference_query_failed', $area);
            }
            $statement = $prepared;
            if (!$statement->execute($selection->params)) {
                throw $this->error('file_reference_query_failed', $area);
            }
            $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
            if (!array_is_list($rows)) {
                throw $this->error('file_reference_query_failed', $area);
            }
            if (!$statement->closeCursor()) {
                throw $this->error('file_reference_query_failed', $area);
            }
            $statement = null;

            $expectedColumns = [...$primaryKey, self::PATH_ALIAS];
            foreach ($rows as $row) {
                if (!is_array($row)
                    || array_is_list($row)
                    || array_keys($row) !== $expectedColumns
                ) {
                    throw $this->error('file_reference_row_invalid', $area);
                }
            }
            return $rows;
        } catch (CompanyBackupFileSourceException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw $this->error('file_reference_query_failed', $area, previous: $e);
        } finally {
            if ($statement instanceof PDOStatement) {
                try {
                    $statement->closeCursor();
                } catch (\Throwable) {
                    // Primární fail-closed chyba dotazu má přednost před úklidem.
                }
            }
        }
    }

    private function selection(
        TenantDataDefinition $target,
        int $supplierId,
        CompanyBackupFileAreaProjection $area,
    ): CompanyBackupSqlSelection {
        $ownership = $target->details['ownership'] ?? null;
        if (!is_array($ownership) || array_is_list($ownership)) {
            throw $this->error('file_reference_ownership_invalid', $area);
        }
        $keys = array_keys($ownership);
        sort($keys, SORT_STRING);
        $strategy = $ownership['strategy'] ?? null;
        $column = $ownership['column'] ?? null;
        if ($target->policy === TenantDataPolicy::TenantRoot
            && $target->name() === 'supplier'
            && $keys === ['column', 'strategy']
            && $strategy === 'selected_supplier'
            && $column === 'id'
        ) {
            return new CompanyBackupSqlSelection(
                self::column(CompanyBackupTenantSqlSelector::SOURCE_ALIAS, 'id') . ' = ?',
                [$supplierId],
            );
        }
        if ($target->policy === TenantDataPolicy::TenantOwned
            && $keys === ['column', 'strategy']
            && $strategy === 'supplier_id'
            && $column === 'supplier_id'
        ) {
            return new CompanyBackupSqlSelection(
                self::column(
                    CompanyBackupTenantSqlSelector::SOURCE_ALIAS,
                    'supplier_id',
                ) . ' = ?',
                [$supplierId],
            );
        }
        throw $this->error('file_reference_ownership_unsupported', $area);
    }

    /** @return list<string> */
    private function primaryKey(
        TenantDataDefinition $target,
        CompanyBackupFileAreaProjection $area,
    ): array {
        $value = $target->details['primary_key'] ?? null;
        if (!is_array($value)
            || !array_is_list($value)
            || $value === []
            || in_array(self::PATH_ALIAS, $value, true)
        ) {
            throw $this->error('file_owner_metadata_invalid', $area);
        }
        $result = [];
        foreach ($value as $column) {
            if (!is_string($column)) {
                throw $this->error('file_owner_metadata_invalid', $area);
            }
            CompanyBackupTenantSqlSelector::quoteIdentifier(
                $column,
                $area->registryKey,
            );
            $result[] = $column;
        }
        return $result;
    }

    private function pathExpression(
        string $alias,
        CompanyBackupFileOwnerDefinition $owner,
    ): string {
        $column = self::column($alias, $owner->column);
        if ($owner->path === []) {
            return $column;
        }
        return "JSON_UNQUOTE(JSON_EXTRACT(" . $column . ", '$."
            . implode('.', $owner->path) . "'))";
    }

    private static function column(string $alias, string $column): string
    {
        return '`' . $alias . '`.`' . $column . '`';
    }

    private function error(
        string $errorCode,
        CompanyBackupFileAreaProjection $area,
        ?string $sourcePath = null,
        ?\Throwable $previous = null,
    ): CompanyBackupFileSourceException {
        return new CompanyBackupFileSourceException(
            $errorCode,
            $area->registryKey,
            $sourcePath,
            previous: $previous,
        );
    }
}
