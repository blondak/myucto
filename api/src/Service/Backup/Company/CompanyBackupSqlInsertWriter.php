<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

use MyInvoice\Service\Backup\Registry\TenantDataDefinition;
use MyInvoice\Service\Backup\Registry\TenantDataPolicy;
use MyInvoice\Service\Backup\Registry\TenantSecretPolicy;
use PDO;
use PDOStatement;

/**
 * Parametrizovaný INSERT writer jednoho registry objektu. SQL skládá pouze z
 * runtime ověřeného allowlistu a transakci vždy ponechá volajícímu.
 */
final class CompanyBackupSqlInsertWriter
{
    private readonly CompanyBackupTableProjection $projection;

    /** @var list<string> */
    private readonly array $protectedColumns;

    /** @var list<string> */
    private readonly array $insertColumns;

    private ?PDOStatement $statement;

    private int $inserted = 0;

    private bool $closed = false;

    private readonly int $maxSourceKeyBytes;

    public function __construct(
        private readonly PDO $database,
        TenantDataDefinition $definition,
        CompanyBackupTableSchema $schema,
        private readonly int $expectedRows,
        CompanyBackupArchiveLimits $limits = new CompanyBackupArchiveLimits(),
    ) {
        $this->projection = CompanyBackupTableProjection::fromDefinition($definition);
        $this->maxSourceKeyBytes = $limits->maxSourceKeyBytes;
        if (!in_array($definition->policy, [
            TenantDataPolicy::TenantRoot,
            TenantDataPolicy::TenantOwned,
            TenantDataPolicy::TenantOwnedIndirect,
        ], true)
            || $expectedRows < 0
            || $expectedRows > $limits->maxSourceIdentities
        ) {
            throw self::error('import_insert_writer_context_invalid', $definition->key);
        }
        $this->projection->assertRuntimeSchema(
            $schema->columns,
            $schema->generatedColumns,
            $schema->primaryKey,
            $schema->binaryColumns,
        );
        $this->assertTransaction('import_transaction_required');
        $driver = $this->driver();
        if (!in_array($driver, ['mysql', 'sqlite'], true)) {
            throw self::error(
                'import_database_driver_unsupported',
                $this->projection->registryKey,
            );
        }

        $this->protectedColumns = $this->protectedColumns();
        $this->insertColumns = [
            ...$this->projection->dataColumns,
            ...$this->protectedColumns,
        ];
        $quotedColumns = array_map(
            fn (string $column): string => $this->quote($column),
            $this->insertColumns,
        );
        $sql = 'INSERT INTO ' . $this->quote($this->projection->name)
            . ' (' . implode(', ', $quotedColumns) . ') VALUES ('
            . implode(', ', array_fill(0, count($quotedColumns), '?')) . ')';
        try {
            $statement = $database->prepare($sql);
            if (!$statement instanceof PDOStatement) {
                throw new \RuntimeException('INSERT nelze připravit.');
            }
            $this->statement = $statement;
        } catch (\Throwable $e) {
            throw self::error(
                'import_insert_writer_unavailable',
                $this->projection->registryKey,
                previous: $e,
            );
        }
    }

    /** @param array<string,mixed> $protectedValues */
    public function insert(
        CompanyBackupPreparedImportRow $prepared,
        array $protectedValues = [],
    ): void {
        $this->assertOpen();
        $this->assertTransaction('import_transaction_lost');
        if ($this->inserted >= $this->expectedRows) {
            throw self::error(
                'import_row_count_exceeded',
                $this->projection->registryKey,
            );
        }
        $this->assertPreparedRow($prepared);
        $this->assertProtectedValues($protectedValues);

        $values = [];
        foreach ($this->projection->dataColumns as $column) {
            $value = $prepared->row[$column];
            $codec = $this->projection->columnCodecs[$column] ?? null;
            $values[] = $codec === null
                ? $value
                : $codec->decode(
                    $value,
                    $this->projection->registryKey,
                    $column,
                );
        }
        foreach ($this->protectedColumns as $column) {
            $values[] = $protectedValues[$column];
        }
        foreach ($this->insertColumns as $index => $column) {
            if (!self::isSqlValue($values[$index])) {
                throw self::error(
                    'import_row_insert_invalid',
                    $this->projection->registryKey,
                    $column,
                );
            }
        }
        $statement = $this->statement;
        if (!$statement instanceof PDOStatement) {
            throw self::error(
                'import_insert_writer_closed',
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
                    throw new \RuntimeException('INSERT parametr nelze navázat.');
                }
            }
            if (!$statement->execute() || $statement->rowCount() !== 1) {
                throw new \RuntimeException('INSERT nezapsal právě jeden řádek.');
            }
            if (!$statement->closeCursor()) {
                throw new \RuntimeException('INSERT kurzor nelze uzavřít.');
            }
        } catch (\Throwable $e) {
            try {
                $statement->closeCursor();
            } catch (\Throwable) {
                // Primární bezpečná chyba INSERTu má přednost před úklidem.
            }
            throw self::error(
                'import_row_insert_failed',
                $this->projection->registryKey,
                previous: $e,
            );
        }
        $this->inserted++;
    }

    public function insertedRows(): int
    {
        return $this->inserted;
    }

    public function finish(): void
    {
        $this->assertOpen();
        $this->assertTransaction('import_transaction_lost');
        if ($this->inserted !== $this->expectedRows) {
            throw self::error(
                'import_row_count_incomplete',
                $this->projection->registryKey,
            );
        }
        $this->statement = null;
        $this->closed = true;
    }

    /** @return list<string> */
    private function protectedColumns(): array
    {
        $expectedSecrets = [];
        foreach ($this->projection->secretPolicies as $column => $policy) {
            if ($policy === TenantSecretPolicy::ProtectedDomainSecret) {
                $expectedSecrets[] = $column;
            }
        }
        sort($expectedSecrets, SORT_STRING);

        $materializedSecrets = [];
        $columns = [];
        foreach (
            $this->projection->protectedSecretMaterializations->materializations
            as $materialization
        ) {
            $materializedSecrets[] = $materialization->secretColumn;
            foreach ($materialization->targetColumns as $column) {
                $columns[] = $column;
            }
        }
        sort($materializedSecrets, SORT_STRING);
        if ($materializedSecrets !== $expectedSecrets) {
            throw self::error(
                'import_protected_materialization_incomplete',
                $this->projection->registryKey,
            );
        }
        return $columns;
    }

    private function assertPreparedRow(
        CompanyBackupPreparedImportRow $prepared,
    ): void {
        if (array_keys($prepared->row) !== $this->projection->dataColumns
            || $prepared->sourceIdentity->policy !== $this->projection->policy
            || $prepared->targetIdentity->policy !== $this->projection->policy
            || $prepared->sourceIdentity->primaryKey->registryKey
                !== $this->projection->registryKey
            || $prepared->targetIdentity->primaryKey->registryKey
                !== $this->projection->registryKey
        ) {
            throw self::error(
                'import_row_insert_invalid',
                $this->projection->registryKey,
            );
        }
        try {
            $rowPrimaryKey = CompanyBackupSourceKey::fromRow(
                $this->projection->registryKey,
                $this->projection->primaryKey,
                $prepared->row,
                $this->maxSourceKeyBytes,
            );
        } catch (CompanyBackupPreflightException $e) {
            throw self::error(
                'import_row_insert_invalid',
                $this->projection->registryKey,
                previous: $e,
            );
        }
        if (!$rowPrimaryKey->equals($prepared->targetIdentity->primaryKey)) {
            throw self::error(
                'import_row_insert_invalid',
                $this->projection->registryKey,
            );
        }
    }

    /** @param array<string,mixed> $values */
    private function assertProtectedValues(array $values): void
    {
        $actual = array_keys($values);
        sort($actual, SORT_STRING);
        $expected = $this->protectedColumns;
        sort($expected, SORT_STRING);
        if ($actual !== $expected) {
            throw self::error(
                'import_protected_values_invalid',
                $this->projection->registryKey,
            );
        }
        foreach ($values as $column => $value) {
            if (!is_string($value) && $value !== null) {
                throw self::error(
                    'import_protected_values_invalid',
                    $this->projection->registryKey,
                    $column,
                );
            }
        }
    }

    private function assertOpen(): void
    {
        if ($this->closed) {
            throw self::error(
                'import_insert_writer_closed',
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
                'INSERT hodnota nemá podporovaný skalární typ.',
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
