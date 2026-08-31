<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

use MyInvoice\Service\Auth\SecretEncryption;
use PDO;
use PDOStatement;

/** Tenantově omezené SQL čtení pouze explicitně vybraných credential hodnot. */
final readonly class CompanyBackupSqlOptionalSecretSource implements
    CompanyBackupOptionalSecretSource
{
    private const SOURCE_ALIAS = '_company_optional_secret';

    public function __construct(
        private SecretEncryption $encryption,
        private CompanyBackupTableSchemaReader $schemaReader =
            new CompanyBackupTableSchemaReader(),
    ) {}

    /** @return iterable<CompanyBackupSecretValue> */
    public function values(
        PDO $snapshot,
        int $supplierId,
        CompanyBackupOptionalSecretProjection $projection,
    ): iterable {
        if ($supplierId < 1) {
            throw new \InvalidArgumentException(
                'Firma zdroje optional secrets musí mít kladné ID.',
            );
        }
        $projection->assertRuntimeSchema(
            $this->schemaReader->readOptionalSecrets($snapshot, $projection),
        );
        $plaintextBytes = 0;
        foreach ($projection->entries as $entry) {
            $selectedColumns = $projection->selectedColumns($entry);
            $columns = implode(', ', array_map(
                static fn (string $column): string => self::column($column),
                $selectedColumns,
            ));
            $conditions = [self::column($projection->ownershipColumn) . ' = ?'];
            $params = [$supplierId];
            foreach ($projection->primaryKey as $column) {
                $keyValue = $entry->primaryKey[$column];
                if ($column === $projection->ownershipColumn) {
                    if ($keyValue !== $supplierId) {
                        throw new CompanyBackupDataSourceException(
                            'secret_selection_tenant_mismatch',
                            $projection->registryKey,
                            $entry->name,
                        );
                    }
                    continue;
                }
                $conditions[] = self::column($column) . ' = ?';
                $params[] = $keyValue;
            }
            $table = CompanyBackupTenantSqlSelector::quoteIdentifier(
                $projection->name,
                $projection->registryKey,
            );
            $alias = CompanyBackupTenantSqlSelector::quoteIdentifier(
                self::SOURCE_ALIAS,
                $projection->registryKey,
            );
            $rows = $this->fetchRows(
                $snapshot,
                'SELECT ' . $columns . ' FROM ' . $table . ' AS ' . $alias
                    . ' WHERE ' . implode(' AND ', $conditions) . ' LIMIT 2',
                $params,
                $projection,
                $entry->name,
            );
            if (count($rows) !== 1) {
                throw new CompanyBackupDataSourceException(
                    count($rows) === 0
                        ? 'secret_selected_value_missing'
                        : 'secret_source_row_invalid',
                    $projection->registryKey,
                    $entry->name,
                );
            }
            $row = $rows[0];
            if (array_keys($row) !== $selectedColumns) {
                throw new CompanyBackupDataSourceException(
                    'secret_source_row_invalid',
                    $projection->registryKey,
                    $entry->name,
                );
            }
            foreach ($projection->primaryKey as $column) {
                if ($row[$column] !== $entry->primaryKey[$column]) {
                    throw new CompanyBackupDataSourceException(
                        'secret_source_row_invalid',
                        $projection->registryKey,
                        $entry->name,
                    );
                }
            }
            $stored = $row[$entry->name];
            if (!is_string($stored) || $stored === '') {
                throw new CompanyBackupDataSourceException(
                    'secret_selected_value_missing',
                    $projection->registryKey,
                    $entry->name,
                );
            }
            try {
                $plaintext = $projection->storage[$entry->name]->decode(
                    $stored,
                    $projection->contexts[$entry->name],
                    $this->encryption,
                );
            } catch (\Throwable $e) {
                throw new CompanyBackupDataSourceException(
                    'secret_source_decrypt_failed',
                    $projection->registryKey,
                    $entry->name,
                    $e,
                );
            }
            $bytes = strlen($plaintext);
            if ($bytes > CompanyBackupSecretEnvelopeDescriptor::MAX_PLAINTEXT_BYTES
                    - $plaintextBytes
            ) {
                self::wipe($plaintext);
                throw new CompanyBackupDataSourceException(
                    'secret_source_size_exceeded',
                    $projection->registryKey,
                    $entry->name,
                );
            }
            $plaintextBytes += $bytes;
            try {
                $value = CompanyBackupSecretValue::fromPlaintext(
                    $projection->registryKey,
                    CompanyBackupSecretScope::Column,
                    $entry->name,
                    $entry->primaryKey,
                    $plaintext,
                );
                yield $value;
            } catch (CompanyBackupSecretPayloadException $e) {
                throw new CompanyBackupDataSourceException(
                    'secret_source_value_invalid',
                    $projection->registryKey,
                    $entry->name,
                    $e,
                );
            } finally {
                self::wipe($plaintext);
            }
        }
    }

    /**
     * @param list<mixed> $params
     * @return list<array<string,mixed>>
     */
    private function fetchRows(
        PDO $snapshot,
        string $sql,
        array $params,
        CompanyBackupOptionalSecretProjection $projection,
        string $column,
    ): array {
        $statement = null;
        try {
            $prepared = $snapshot->prepare($sql);
            if (!$prepared instanceof PDOStatement) {
                throw new CompanyBackupDataSourceException(
                    'secret_source_query_failed',
                    $projection->registryKey,
                    $column,
                );
            }
            $statement = $prepared;
            if (!$statement->execute($params)) {
                throw new CompanyBackupDataSourceException(
                    'secret_source_query_failed',
                    $projection->registryKey,
                    $column,
                );
            }
            $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
            if (!array_is_list($rows)) {
                throw new CompanyBackupDataSourceException(
                    'secret_source_query_failed',
                    $projection->registryKey,
                    $column,
                );
            }
            $result = [];
            foreach ($rows as $row) {
                if (!is_array($row) || array_is_list($row)) {
                    throw new CompanyBackupDataSourceException(
                        'secret_source_row_invalid',
                        $projection->registryKey,
                        $column,
                    );
                }
                $result[] = $row;
            }
            if (!$statement->closeCursor()) {
                throw new CompanyBackupDataSourceException(
                    'secret_source_query_failed',
                    $projection->registryKey,
                    $column,
                );
            }
            $statement = null;
            return $result;
        } catch (CompanyBackupDataSourceException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new CompanyBackupDataSourceException(
                'secret_source_query_failed',
                $projection->registryKey,
                $column,
                $e,
            );
        } finally {
            if ($statement instanceof PDOStatement) {
                try {
                    $statement->closeCursor();
                } catch (\Throwable) {
                }
            }
        }
    }

    private static function column(string $column): string
    {
        return '`' . self::SOURCE_ALIAS . '`.`' . $column . '`';
    }

    private static function wipe(string &$value): void
    {
        $sensitive = $value;
        $value = '';
        if ($sensitive !== '' && function_exists('sodium_memzero')) {
            sodium_memzero($sensitive);
        }
    }
}
