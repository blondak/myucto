<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

use MyInvoice\Service\Backup\Registry\TenantDataDefinition;
use MyInvoice\Service\Backup\Registry\TenantDataPolicy;
use PDO;
use PDOStatement;

/**
 * Druhý SQL průchod jednoho registry objektu. Aktualizuje pouze sloupce
 * odložené plánem a stav po prvním INSERTu používá jako optimistic guard.
 */
final class CompanyBackupSqlDeferredUpdateWriter
{
    private readonly CompanyBackupTableProjection $projection;

    private readonly CompanyBackupDeferredColumnSet $deferredColumns;

    private ?PDOStatement $statement;

    private int $processed = 0;

    private int $updated = 0;

    private bool $closed = false;

    public function __construct(
        private readonly PDO $database,
        TenantDataDefinition $definition,
        CompanyBackupTableSchema $schema,
        CompanyBackupImportDependencyPlan $plan,
        private readonly int $expectedRows,
        CompanyBackupArchiveLimits $limits = new CompanyBackupArchiveLimits(),
    ) {
        $this->projection = CompanyBackupTableProjection::fromDefinition($definition);
        if (!in_array($definition->policy, [
            TenantDataPolicy::TenantRoot,
            TenantDataPolicy::TenantOwned,
            TenantDataPolicy::TenantOwnedIndirect,
        ], true)
            || $expectedRows < 0
            || $expectedRows > $limits->maxSourceIdentities
        ) {
            throw self::error(
                'import_deferred_writer_context_invalid',
                $definition->key,
            );
        }
        $this->projection->assertRuntimeSchema(
            $schema->columns,
            $schema->generatedColumns,
            $schema->primaryKey,
            $schema->binaryColumns,
        );
        $this->deferredColumns = CompanyBackupDeferredColumnSet::fromProjection(
            $this->projection,
            $plan,
        );
        if ($this->deferredColumns->columns === []) {
            throw self::error(
                'import_deferred_columns_empty',
                $definition->key,
            );
        }
        $this->assertTransaction('import_transaction_required');
        $driver = $this->driver();
        if (!in_array($driver, ['mysql', 'sqlite'], true)) {
            throw self::error(
                'import_database_driver_unsupported',
                $this->projection->registryKey,
            );
        }

        $assignments = array_map(
            fn (string $column): string => $this->quote($column) . ' = ?',
            $this->deferredColumns->columns,
        );
        $comparison = $driver === 'mysql' ? ' <=> ?' : ' IS ?';
        $guardColumns = [
            ...$this->projection->primaryKey,
            ...$this->deferredColumns->columns,
        ];
        $guards = array_map(
            fn (string $column): string => $this->quote($column) . $comparison,
            $guardColumns,
        );
        $sql = 'UPDATE ' . $this->quote($this->projection->name)
            . ' SET ' . implode(', ', $assignments)
            . ' WHERE ' . implode(' AND ', $guards);
        try {
            $statement = $database->prepare($sql);
            if (!$statement instanceof PDOStatement) {
                throw new \RuntimeException('Odložený UPDATE nelze připravit.');
            }
            $this->statement = $statement;
        } catch (\Throwable $e) {
            throw self::error(
                'import_deferred_writer_unavailable',
                $this->projection->registryKey,
                previous: $e,
            );
        }
    }

    public function update(CompanyBackupPreparedDeferredUpdate $prepared): void
    {
        $this->assertOpen();
        $this->assertTransaction('import_transaction_lost');
        if ($this->processed >= $this->expectedRows) {
            throw self::error(
                'import_deferred_row_count_exceeded',
                $this->projection->registryKey,
            );
        }
        $this->assertPreparedUpdate($prepared);

        $values = [];
        try {
            foreach ($prepared->afterValues as $column => $value) {
                $values[] = $this->databaseValue($column, $value);
            }
            foreach ($prepared->targetPrimaryKey->values as $column => $value) {
                $values[] = $this->databaseValue($column, $value);
            }
            foreach ($prepared->beforeValues as $column => $value) {
                $values[] = $this->databaseValue($column, $value);
            }
        } catch (CompanyBackupDataSourceException $e) {
            throw self::error(
                'import_deferred_update_invalid',
                $this->projection->registryKey,
                $e->column,
                $e,
            );
        }
        foreach ($values as $value) {
            if (!self::isSqlValue($value)) {
                throw self::error(
                    'import_deferred_update_invalid',
                    $this->projection->registryKey,
                );
            }
        }
        if (!$prepared->hasChanges()) {
            $this->processed++;
            return;
        }

        $statement = $this->statement;
        if (!$statement instanceof PDOStatement) {
            throw self::error(
                'import_deferred_writer_closed',
                $this->projection->registryKey,
            );
        }
        try {
            foreach ($values as $index => $value) {
                if (!$statement->bindValue(
                    $index + 1,
                    $value,
                    self::parameterType($value),
                )) {
                    throw new \RuntimeException(
                        'Odložený UPDATE parametr nelze navázat.',
                    );
                }
            }
            if (!$statement->execute()) {
                throw new \RuntimeException('Odložený UPDATE selhal.');
            }
            $affectedRows = $statement->rowCount();
            if (!$statement->closeCursor()) {
                throw new \RuntimeException(
                    'Odložený UPDATE kurzor nelze uzavřít.',
                );
            }
        } catch (\Throwable $e) {
            try {
                $statement->closeCursor();
            } catch (\Throwable) {
                // Primární bezpečná chyba UPDATE má přednost před úklidem.
            }
            throw self::error(
                'import_deferred_row_update_failed',
                $this->projection->registryKey,
                previous: $e,
            );
        }
        if ($affectedRows !== 1) {
            throw self::error(
                'import_deferred_row_state_changed',
                $this->projection->registryKey,
            );
        }
        $this->processed++;
        $this->updated++;
    }

    public function processedRows(): int
    {
        return $this->processed;
    }

    public function updatedRows(): int
    {
        return $this->updated;
    }

    public function finish(): void
    {
        $this->assertOpen();
        $this->assertTransaction('import_transaction_lost');
        if ($this->processed !== $this->expectedRows) {
            throw self::error(
                'import_deferred_row_count_incomplete',
                $this->projection->registryKey,
            );
        }
        $this->statement = null;
        $this->closed = true;
    }

    private function assertPreparedUpdate(
        CompanyBackupPreparedDeferredUpdate $prepared,
    ): void {
        if ($prepared->targetPrimaryKey->registryKey
                !== $this->projection->registryKey
            || $prepared->targetPrimaryKey->columns
                !== $this->projection->primaryKey
            || array_keys($prepared->beforeValues)
                !== $this->deferredColumns->columns
            || array_keys($prepared->afterValues)
                !== $this->deferredColumns->columns
        ) {
            throw self::error(
                'import_deferred_update_invalid',
                $this->projection->registryKey,
            );
        }
    }

    private function databaseValue(string $column, mixed $value): mixed
    {
        $codec = $this->projection->columnCodecs[$column] ?? null;
        return $codec === null
            ? $value
            : $codec->decode(
                $value,
                $this->projection->registryKey,
                $column,
            );
    }

    private function assertOpen(): void
    {
        if ($this->closed) {
            throw self::error(
                'import_deferred_writer_closed',
                $this->projection->registryKey,
            );
        }
    }

    private function assertTransaction(string $errorCode): void
    {
        try {
            if ($this->database->inTransaction()) {
                return;
            }
        } catch (\Throwable $e) {
            throw self::error(
                'import_transaction_state_failed',
                $this->projection->registryKey,
                previous: $e,
            );
        }
        throw self::error($errorCode, $this->projection->registryKey);
    }

    private function driver(): string
    {
        try {
            $driver = $this->database->getAttribute(PDO::ATTR_DRIVER_NAME);
        } catch (\Throwable $e) {
            throw self::error(
                'import_database_driver_unsupported',
                $this->projection->registryKey,
                previous: $e,
            );
        }
        return is_string($driver) ? $driver : '';
    }

    private function quote(string $identifier): string
    {
        return CompanyBackupTenantSqlSelector::quoteIdentifier(
            $identifier,
            $this->projection->registryKey,
        );
    }

    private static function parameterType(mixed $value): int
    {
        return match (true) {
            $value === null => PDO::PARAM_NULL,
            is_bool($value) => PDO::PARAM_BOOL,
            is_int($value) => PDO::PARAM_INT,
            is_string($value), is_float($value) => PDO::PARAM_STR,
            default => throw new \InvalidArgumentException(
                'Odložený UPDATE nemá podporovaný skalární typ.',
            ),
        };
    }

    private static function isSqlValue(mixed $value): bool
    {
        return $value === null
            || is_bool($value)
            || is_int($value)
            || is_string($value)
            || is_float($value) && is_finite($value);
    }

    private static function error(
        string $errorCode,
        string $registryKey,
        ?string $column = null,
        ?\Throwable $previous = null,
    ): CompanyBackupImportWriteException {
        return new CompanyBackupImportWriteException(
            $errorCode,
            $registryKey,
            $column,
            $previous,
        );
    }
}
