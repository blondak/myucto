<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

use MyInvoice\Service\Backup\CanonicalJson;
use PDO;
use PDOStatement;

/**
 * Diskový index potomků kořenové zákonné pečeti. MariaDB varianta používá
 * temporary InnoDB, takže úplnost velké zálohy nezávisí na PHP heapu.
 */
final class CompanyBackupPayrollStatutoryResultSetSourceIndex
{
    private const PERSON = 0;
    private const RELATIONSHIP = 1;
    private const INDEX_OVERHEAD_BYTES = 24;

    private readonly string $rowsTable;
    private readonly string $rootsTable;
    private string $quotedRows = '';
    private string $quotedRoots = '';
    private ?PDOStatement $insertRow = null;
    private ?PDOStatement $selectRows = null;
    private ?PDOStatement $insertRoot = null;
    private ?PDOStatement $selectRoot = null;
    private int $rows = 0;
    private int $claimedRows = 0;
    private int $claimedRoots = 0;
    private int $bytes = 0;
    private bool $sealed = false;
    private bool $finished = false;
    private bool $closed = false;

    public function __construct(
        private readonly PDO $database,
        private readonly CompanyBackupArchiveLimits $limits =
            new CompanyBackupArchiveLimits(),
    ) {
        try {
            $suffix = bin2hex(random_bytes(8));
        } catch (\Throwable $e) {
            throw self::error('source_aggregate_index_unavailable', previous: $e);
        }
        $this->rowsTable = 'company_backup_statutory_rows_' . $suffix;
        $this->rootsTable = 'company_backup_statutory_roots_' . $suffix;

        try {
            $driver = $database->getAttribute(PDO::ATTR_DRIVER_NAME);
            if (!is_string($driver) || !in_array($driver, ['mysql', 'sqlite'], true)) {
                throw self::error('source_aggregate_index_driver_unsupported');
            }
            $mysql = $driver === 'mysql';
            $quote = $mysql ? '`' : '"';
            $temporary = $mysql ? 'TEMPORARY ' : '';
            $this->quotedRows = $quote . $this->rowsTable . $quote;
            $this->quotedRoots = $quote . $this->rootsTable . $quote;
            $rowsDdl = $mysql
                ? 'CREATE TEMPORARY TABLE ' . $this->quotedRows . ' ('
                    . 'kind TINYINT UNSIGNED NOT NULL,'
                    . 'row_id BIGINT UNSIGNED NOT NULL,'
                    . 'root_id BIGINT UNSIGNED NOT NULL,'
                    . 'payload LONGBLOB NOT NULL,'
                    . 'PRIMARY KEY (kind, row_id),'
                    . 'KEY idx_root (root_id, kind, row_id)'
                    . ') ENGINE=InnoDB'
                : 'CREATE TEMP TABLE ' . $this->quotedRows . ' ('
                    . 'kind INTEGER NOT NULL,'
                    . 'row_id INTEGER NOT NULL,'
                    . 'root_id INTEGER NOT NULL,'
                    . 'payload BLOB NOT NULL,'
                    . 'PRIMARY KEY (kind, row_id)'
                    . ')';
            $rootsDdl = 'CREATE ' . $temporary . 'TABLE '
                . $this->quotedRoots . ' ('
                . 'root_id ' . ($mysql ? 'BIGINT UNSIGNED' : 'INTEGER')
                . ' NOT NULL PRIMARY KEY)'
                . ($mysql ? ' ENGINE=InnoDB' : '');
            if ($database->exec($rowsDdl) === false
                || $database->exec($rootsDdl) === false
            ) {
                throw new \RuntimeException('Dočasný agregátní index nelze vytvořit.');
            }
            $this->insertRow = $database->prepare(
                'INSERT INTO ' . $this->quotedRows
                    . ' (kind, row_id, root_id, payload) VALUES (?, ?, ?, ?)',
            );
            $this->selectRows = $database->prepare(
                'SELECT kind, payload FROM ' . $this->quotedRows
                    . ' WHERE root_id = ? ORDER BY kind, row_id',
            );
            $this->insertRoot = $database->prepare(
                'INSERT INTO ' . $this->quotedRoots . ' (root_id) VALUES (?)',
            );
            $this->selectRoot = $database->prepare(
                'SELECT 1 FROM ' . $this->quotedRoots . ' WHERE root_id = ?',
            );
        } catch (CompanyBackupPreflightException $e) {
            $this->bestEffortDrop();
            $this->closed = true;
            throw $e;
        } catch (\Throwable $e) {
            $this->bestEffortDrop();
            $this->closed = true;
            throw self::error('source_aggregate_index_unavailable', previous: $e);
        }
    }

    /** @param array<string,mixed> $row */
    public function addPerson(array $row): void
    {
        $this->add(self::PERSON, $row);
    }

    /** @param array<string,mixed> $row */
    public function addRelationship(array $row): void
    {
        $this->add(self::RELATIONSHIP, $row);
    }

    public function seal(): void
    {
        $this->assertOpen();
        if ($this->sealed) {
            throw new \LogicException('Agregátní index už je uzavřený.');
        }
        $this->sealed = true;
    }

    /** @param array<string,mixed> $header */
    public function assertSourceHeader(array $header): void
    {
        $this->assertReadable();
        $rootId = self::positiveInt($header['id'] ?? null);
        $selectRoot = $this->selectRoot;
        $insertRoot = $this->insertRoot;
        if (!$selectRoot instanceof PDOStatement
            || !$insertRoot instanceof PDOStatement
        ) {
            throw new \LogicException('Agregátní index není otevřený.');
        }
        try {
            if (!$selectRoot->execute([$rootId])) {
                throw new \RuntimeException('Kontrola agregátního kořene selhala.');
            }
            $existing = $selectRoot->fetchColumn();
            if (!$selectRoot->closeCursor()) {
                throw new \RuntimeException('Kontrolu agregátního kořene nelze uzavřít.');
            }
            if ($existing !== false) {
                throw self::error('source_aggregate_root_duplicate');
            }
            $rows = $this->rowsFor($rootId);
            CompanyBackupPayrollStatutoryResultSetAssembler::assertSource(
                $header,
                $rows['people'],
                $rows['relationships'],
            );
            if (!$insertRoot->execute([$rootId])) {
                throw new \RuntimeException('Zápis agregátního kořene selhal.');
            }
        } catch (CompanyBackupPreflightException $e) {
            throw $e;
        } catch (CompanyBackupDataSourceException $e) {
            throw new CompanyBackupPreflightException(
                $e->errorCode,
                $e->registryKey,
                $e->column,
                $e,
            );
        } catch (\Throwable $e) {
            throw self::error('source_aggregate_index_read_failed', previous: $e);
        }
        $this->claimedRoots++;
        $this->claimedRows += count($rows['people']) + count($rows['relationships']);
    }

    /**
     * @return array{
     *   people:list<array<string,mixed>>,
     *   relationships:list<array<string,mixed>>
     * }
     */
    public function rowsFor(int $rootId): array
    {
        $this->assertReadable();
        if ($rootId < 1) {
            throw self::error('source_aggregate_graph_invalid');
        }
        $select = $this->selectRows;
        if (!$select instanceof PDOStatement) {
            throw new \LogicException('Agregátní index není otevřený.');
        }
        try {
            if (!$select->execute([$rootId])) {
                throw new \RuntimeException('Čtení agregátního indexu selhalo.');
            }
            $stored = $select->fetchAll(PDO::FETCH_ASSOC);
            if (!$select->closeCursor()) {
                throw new \RuntimeException('Čtení agregátního indexu nelze uzavřít.');
            }
        } catch (\Throwable $e) {
            throw self::error('source_aggregate_index_read_failed', previous: $e);
        }
        $people = [];
        $relationships = [];
        foreach ($stored as $item) {
            if (!is_array($item)) {
                throw self::error('source_aggregate_index_corrupted');
            }
            $kind = $item['kind'] ?? null;
            if (is_string($kind) && in_array($kind, ['0', '1'], true)) {
                $kind = (int) $kind;
            }
            $payload = $item['payload'] ?? null;
            if (!is_string($payload)) {
                throw self::error('source_aggregate_index_corrupted');
            }
            try {
                $row = json_decode($payload, true, 128, JSON_THROW_ON_ERROR);
                if (!is_array($row)
                    || array_is_list($row)
                    || !hash_equals(CanonicalJson::encode($row), $payload)
                ) {
                    throw new \UnexpectedValueException(
                        'Agregátní index neobsahuje kanonický řádek.',
                    );
                }
            } catch (\Throwable $e) {
                throw self::error('source_aggregate_index_corrupted', previous: $e);
            }
            if ($kind === self::PERSON) {
                $people[] = $row;
            } elseif ($kind === self::RELATIONSHIP) {
                $relationships[] = $row;
            } else {
                throw self::error('source_aggregate_index_corrupted');
            }
        }
        return ['people' => $people, 'relationships' => $relationships];
    }

    public function finish(int $expectedRoots): void
    {
        $this->assertReadable();
        if ($this->finished) {
            throw new \LogicException('Agregátní index už byl dokončený.');
        }
        if ($expectedRoots < 0
            || $this->claimedRoots !== $expectedRoots
            || $this->claimedRows !== $this->rows
        ) {
            throw self::error('source_aggregate_graph_incomplete');
        }
        $this->finished = true;
    }

    public function rowCount(): int
    {
        return $this->rows;
    }

    public function indexedBytes(): int
    {
        return $this->bytes;
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->insertRow = null;
        $this->selectRows = null;
        $this->insertRoot = null;
        $this->selectRoot = null;
        try {
            $this->drop();
        } catch (\Throwable $e) {
            $this->closed = true;
            throw self::error('source_aggregate_index_cleanup_failed', previous: $e);
        }
        $this->closed = true;
    }

    public function __destruct()
    {
        if (!$this->closed) {
            $this->insertRow = null;
            $this->selectRows = null;
            $this->insertRoot = null;
            $this->selectRoot = null;
            $this->bestEffortDrop();
            $this->closed = true;
        }
    }

    /** @param array<string,mixed> $row */
    private function add(int $kind, array $row): void
    {
        $this->assertWritable();
        $rowId = self::positiveInt($row['id'] ?? null);
        $rootId = self::positiveInt($row['statutory_result_id'] ?? null);
        if ($this->rows >= $this->limits->maxSourceIdentities) {
            throw self::error('source_aggregate_index_limit_exceeded');
        }
        try {
            $payload = CanonicalJson::encode($row);
        } catch (\Throwable $e) {
            throw self::error('source_aggregate_row_invalid', previous: $e);
        }
        $additionalBytes = strlen($payload) + self::INDEX_OVERHEAD_BYTES;
        if ($additionalBytes > $this->limits->maxSourceIndexBytes - $this->bytes) {
            throw self::error('source_aggregate_index_size_exceeded');
        }
        $insert = $this->insertRow;
        if (!$insert instanceof PDOStatement) {
            throw new \LogicException('Agregátní index není otevřený.');
        }
        try {
            if (!$insert->execute([$kind, $rowId, $rootId, $payload])) {
                throw new \RuntimeException('Zápis agregátního indexu selhal.');
            }
        } catch (\Throwable $e) {
            throw self::error('source_aggregate_index_write_failed', previous: $e);
        }
        $this->rows++;
        $this->bytes += $additionalBytes;
    }

    private function assertWritable(): void
    {
        $this->assertOpen();
        if ($this->sealed) {
            throw new \LogicException('Uzavřený agregátní index nelze měnit.');
        }
    }

    private function assertReadable(): void
    {
        $this->assertOpen();
        if (!$this->sealed) {
            throw new \LogicException('Agregátní index ještě není uzavřený.');
        }
    }

    private function assertOpen(): void
    {
        if ($this->closed) {
            throw new \LogicException('Agregátní index už je zavřený.');
        }
    }

    private function drop(): void
    {
        $driver = $this->database->getAttribute(PDO::ATTR_DRIVER_NAME);
        $temporary = $driver === 'mysql' ? 'TEMPORARY ' : '';
        foreach ([$this->rootsTable, $this->rowsTable] as $table) {
            $quote = $driver === 'mysql' ? '`' : '"';
            $dropped = $this->database->exec(
                'DROP ' . $temporary . 'TABLE IF EXISTS '
                    . $quote . $table . $quote,
            );
            if ($dropped === false) {
                throw new \RuntimeException(
                    'Dočasnou tabulku agregátního indexu nelze odstranit.',
                );
            }
        }
    }

    private function bestEffortDrop(): void
    {
        try {
            $this->drop();
        } catch (\Throwable) {
        }
    }

    private static function positiveInt(mixed $value): int
    {
        if (!is_int($value) || $value < 1) {
            throw self::error('source_aggregate_row_invalid');
        }
        return $value;
    }

    private static function error(
        string $code,
        ?\Throwable $previous = null,
    ): CompanyBackupPreflightException {
        return new CompanyBackupPreflightException(
            $code,
            CompanyBackupPayrollStatutoryResultSetAssembler::ROOT_REGISTRY_KEY,
            CompanyBackupPayrollStatutoryResultSetAssembler::HASH_COLUMN,
            $previous,
        );
    }
}
