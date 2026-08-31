<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

use MyInvoice\Service\Auth\SecretEncryption;
use PDO;
use PDOStatement;

/** Tenantově omezený SQL zdroj povinných secretů s in-memory dešifrováním. */
final readonly class CompanyBackupSqlProtectedSecretSource implements
    CompanyBackupProtectedSecretSource
{
    private const SOURCE_ALIAS = '_company_secret';
    private const MAX_ROWS = 100_000;

    public function __construct(
        private SecretEncryption $encryption,
        private CompanyBackupTableSchemaReader $schemaReader =
            new CompanyBackupTableSchemaReader(),
    ) {}

    /** @return iterable<CompanyBackupSecretValue> */
    public function values(
        PDO $snapshot,
        int $supplierId,
        CompanyBackupProtectedSecretProjection $projection,
    ): iterable {
        if ($supplierId < 1) {
            throw new \InvalidArgumentException(
                'Firma zdroje protected secrets musí mít kladné ID.',
            );
        }
        $projection->assertRuntimeSchema(
            $this->schemaReader->readProtectedSecrets($snapshot, $projection),
        );
        $selectedColumns = $projection->selectedColumns();
        $columns = implode(', ', array_map(
            static fn (string $column): string => self::column($column),
            $selectedColumns,
        ));
        $order = implode(', ', array_map(
            static fn (string $column): string => self::column($column),
            $projection->primaryKey,
        ));
        $table = CompanyBackupTenantSqlSelector::quoteIdentifier(
            $projection->name,
            $projection->registryKey,
        );
        $alias = CompanyBackupTenantSqlSelector::quoteIdentifier(
            self::SOURCE_ALIAS,
            $projection->registryKey,
        );
        $ownershipColumn = self::column($projection->ownershipColumn);
        $statement = $this->open(
            $snapshot,
            'SELECT ' . $columns . ' FROM ' . $table . ' AS ' . $alias
                . ' WHERE ' . $ownershipColumn . ' = ? ORDER BY ' . $order
                . ' LIMIT ' . (self::MAX_ROWS + 1),
            [$supplierId],
            $projection,
        );
        $rows = 0;
        $plaintextBytes = 0;
        try {
            while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
                if ($rows === self::MAX_ROWS) {
                    throw new CompanyBackupDataSourceException(
                        'secret_source_row_limit_exceeded',
                        $projection->registryKey,
                    );
                }
                $rows++;
                if (!is_array($row)
                    || array_is_list($row)
                    || array_keys($row) !== $selectedColumns
                ) {
                    throw new CompanyBackupDataSourceException(
                        'secret_source_row_invalid',
                        $projection->registryKey,
                    );
                }
                $primaryKey = [];
                foreach ($projection->primaryKey as $column) {
                    $primaryKey[$column] = $row[$column];
                }
                foreach ($projection->columns as $column) {
                    $stored = $row[$column];
                    if ($stored === null || $stored === '') {
                        continue;
                    }
                    if (!is_string($stored)) {
                        throw new CompanyBackupDataSourceException(
                            'secret_source_value_invalid',
                            $projection->registryKey,
                            $column,
                        );
                    }
                    try {
                        $plaintext = $projection->storage[$column]->decode(
                            $stored,
                            $projection->contexts[$column],
                            $this->encryption,
                        );
                    } catch (\Throwable $e) {
                        throw new CompanyBackupDataSourceException(
                            'secret_source_decrypt_failed',
                            $projection->registryKey,
                            $column,
                            $e,
                        );
                    }
                    $bytes = strlen($plaintext);
                    if ($bytes > CompanyBackupSecretEnvelopeDescriptor::MAX_PLAINTEXT_BYTES
                            - $plaintextBytes
                    ) {
                        throw new CompanyBackupDataSourceException(
                            'secret_source_size_exceeded',
                            $projection->registryKey,
                            $column,
                        );
                    }
                    $plaintextBytes += $bytes;
                    try {
                        yield CompanyBackupSecretValue::fromPlaintext(
                            $projection->registryKey,
                            CompanyBackupSecretScope::Column,
                            $column,
                            $primaryKey,
                            $plaintext,
                        );
                    } catch (CompanyBackupSecretPayloadException $e) {
                        throw new CompanyBackupDataSourceException(
                            'secret_source_value_invalid',
                            $projection->registryKey,
                            $column,
                            $e,
                        );
                    }
                }
            }
            if (!$statement->closeCursor()) {
                throw new CompanyBackupDataSourceException(
                    'secret_source_query_failed',
                    $projection->registryKey,
                );
            }
            $statement = null;
        } catch (CompanyBackupDataSourceException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new CompanyBackupDataSourceException(
                'secret_source_query_failed',
                $projection->registryKey,
                previous: $e,
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

    /**
     * @param list<mixed> $params
     */
    private function open(
        PDO $snapshot,
        string $sql,
        array $params,
        CompanyBackupProtectedSecretProjection $projection,
    ): PDOStatement {
        try {
            $prepared = $snapshot->prepare($sql);
            if (!$prepared instanceof PDOStatement) {
                throw new CompanyBackupDataSourceException(
                    'secret_source_query_failed',
                    $projection->registryKey,
                );
            }
            if (!$prepared->execute($params)) {
                throw new CompanyBackupDataSourceException(
                    'secret_source_query_failed',
                    $projection->registryKey,
                );
            }
            return $prepared;
        } catch (CompanyBackupDataSourceException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new CompanyBackupDataSourceException(
                'secret_source_query_failed',
                $projection->registryKey,
                previous: $e,
            );
        }
    }

    private static function column(string $column): string
    {
        return '`' . self::SOURCE_ALIAS . '`.`' . $column . '`';
    }
}
