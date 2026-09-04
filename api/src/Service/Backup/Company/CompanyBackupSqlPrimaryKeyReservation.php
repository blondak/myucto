<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

use PDO;
use PDOStatement;

/**
 * Konstantně paměťová rezervace souvislého rozsahu cílových ID. V MariaDB
 * drží horní mezeru primárního indexu zamčenou po dobu transakce volajícího.
 */
final class CompanyBackupSqlPrimaryKeyReservation
{
    private int $nextValue;

    private int $remaining;

    private bool $closed = false;

    private function __construct(
        private readonly PDO $database,
        private readonly string $registryKey,
        private readonly string $column,
        int $firstValue,
        int $count,
    ) {
        $this->nextValue = $firstValue;
        $this->remaining = $count;
    }

    public static function reserve(
        PDO $database,
        CompanyBackupTableProjection $projection,
        CompanyBackupAutoIncrementColumn $autoIncrement,
        int $rowCount,
        CompanyBackupArchiveLimits $limits = new CompanyBackupArchiveLimits(),
    ): self {
        if ($rowCount < 0 || $rowCount > $limits->maxSourceIdentities) {
            throw self::error(
                'import_primary_key_reservation_size_invalid',
                $projection,
                $autoIncrement->column,
            );
        }
        if ($projection->primaryKey !== [$autoIncrement->column]) {
            throw self::error(
                'import_auto_increment_primary_key_invalid',
                $projection,
                $autoIncrement->column,
            );
        }

        try {
            if (!$database->inTransaction()) {
                throw self::error('import_transaction_required', $projection);
            }
            $driver = $database->getAttribute(PDO::ATTR_DRIVER_NAME);
            if (!is_string($driver) || !in_array($driver, ['mysql', 'sqlite'], true)) {
                throw self::error(
                    'import_database_driver_unsupported',
                    $projection,
                );
            }
            if ($driver === 'mysql') {
                self::assertMysqlIsolation($database, $projection);
            }
            $highest = $rowCount === 0
                ? 0
                : self::highestValue(
                    $database,
                    $projection,
                    $autoIncrement,
                    $driver,
                );
        } catch (CompanyBackupImportWriteException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw self::error(
                'import_primary_key_reservation_failed',
                $projection,
                $autoIncrement->column,
                $e,
            );
        }

        if ($rowCount > $autoIncrement->maximumValue - $highest) {
            throw self::error(
                'import_primary_key_range_exhausted',
                $projection,
                $autoIncrement->column,
            );
        }
        return new self(
            $database,
            $projection->registryKey,
            $autoIncrement->column,
            $highest + 1,
            $rowCount,
        );
    }

    public function next(): int
    {
        $this->assertTransaction();
        if ($this->closed) {
            throw $this->stateError('import_primary_key_reservation_closed');
        }
        if ($this->remaining === 0) {
            throw $this->stateError('import_primary_key_reservation_consumed');
        }
        $value = $this->nextValue;
        $this->remaining--;
        if ($this->remaining > 0) {
            $this->nextValue++;
        }
        return $value;
    }

    public function remaining(): int
    {
        return $this->remaining;
    }

    public function finish(): void
    {
        $this->assertTransaction();
        if ($this->closed) {
            throw $this->stateError('import_primary_key_reservation_closed');
        }
        if ($this->remaining !== 0) {
            throw $this->stateError('import_primary_key_reservation_incomplete');
        }
        $this->closed = true;
    }

    private function assertTransaction(): void
    {
        try {
            if ($this->database->inTransaction()) {
                return;
            }
        } catch (\Throwable $e) {
            throw new CompanyBackupImportWriteException(
                'import_transaction_state_failed',
                $this->registryKey,
                $this->column,
                $e,
            );
        }
        throw $this->stateError('import_transaction_lost');
    }

    private static function assertMysqlIsolation(
        PDO $database,
        CompanyBackupTableProjection $projection,
    ): void {
        $isolation = self::scalar(
            $database,
            'SELECT @@transaction_isolation',
            $projection,
        );
        if (!is_string($isolation)
            || !in_array(strtoupper($isolation), [
                'REPEATABLE-READ',
                'SERIALIZABLE',
            ], true)
        ) {
            throw self::error(
                'import_transaction_isolation_invalid',
                $projection,
            );
        }
    }

    private static function highestValue(
        PDO $database,
        CompanyBackupTableProjection $projection,
        CompanyBackupAutoIncrementColumn $autoIncrement,
        string $driver,
    ): int {
        $quote = $driver === 'mysql' ? '`' : '"';
        $sql = 'SELECT ' . $quote . $autoIncrement->column . $quote
            . ' FROM ' . $quote . $projection->name . $quote
            . ' ORDER BY ' . $quote . $autoIncrement->column . $quote . ' DESC'
            . ' LIMIT 1'
            . ($driver === 'mysql' ? ' FOR UPDATE' : '');
        $value = self::scalar($database, $sql, $projection);
        if ($value === false) {
            return 0;
        }
        if (is_int($value)) {
            $highest = $value;
        } elseif (is_string($value)
            && preg_match('/^(?:0|[1-9][0-9]*)$/D', $value) === 1
        ) {
            $parsed = filter_var(
                $value,
                FILTER_VALIDATE_INT,
                ['options' => [
                    'min_range' => 0,
                    'max_range' => $autoIncrement->maximumValue,
                ]],
            );
            $highest = is_int($parsed) ? $parsed : -1;
        } else {
            $highest = -1;
        }
        if ($highest < 0 || $highest > $autoIncrement->maximumValue) {
            throw self::error(
                'import_primary_key_value_invalid',
                $projection,
                $autoIncrement->column,
            );
        }
        return $highest;
    }

    private static function scalar(
        PDO $database,
        string $sql,
        CompanyBackupTableProjection $projection,
    ): mixed {
        $statement = null;
        try {
            $prepared = $database->prepare($sql);
            if (!$prepared instanceof PDOStatement) {
                throw new \RuntimeException('SQL dotaz rezervace nelze připravit.');
            }
            $statement = $prepared;
            if (!$statement->execute()) {
                throw new \RuntimeException('SQL dotaz rezervace selhal.');
            }
            $value = $statement->fetchColumn();
            if (!$statement->closeCursor()) {
                throw new \RuntimeException('SQL kurzor rezervace nelze uzavřít.');
            }
            $statement = null;
            return $value;
        } catch (\Throwable $e) {
            throw self::error(
                'import_primary_key_reservation_failed',
                $projection,
                previous: $e,
            );
        } finally {
            if ($statement instanceof PDOStatement) {
                try {
                    $statement->closeCursor();
                } catch (\Throwable) {
                    // Primární bezpečná chyba rezervace má přednost před úklidem.
                }
            }
        }
    }

    private function stateError(string $errorCode): CompanyBackupImportWriteException
    {
        return new CompanyBackupImportWriteException(
            $errorCode,
            $this->registryKey,
            $this->column,
        );
    }

    private static function error(
        string $errorCode,
        CompanyBackupTableProjection $projection,
        ?string $column = null,
        ?\Throwable $previous = null,
    ): CompanyBackupImportWriteException {
        return new CompanyBackupImportWriteException(
            $errorCode,
            $projection->registryKey,
            $column,
            $previous,
        );
    }
}
