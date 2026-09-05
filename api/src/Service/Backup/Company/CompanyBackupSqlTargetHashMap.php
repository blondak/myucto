<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

use PDO;
use PDOStatement;

/**
 * Diskově omezená mapa původních odvozených hashů na jejich hodnoty po remapu.
 * Dočasná SQL tabulka neuchovává zdrojové ani cílové business řádky.
 */
final class CompanyBackupSqlTargetHashMap
{
    private readonly string $table;

    private string $quotedTable = '';

    private bool $mysql = false;

    private ?PDOStatement $select = null;

    private ?PDOStatement $insert = null;

    private int $mappings = 0;

    private int $bytes = 0;

    private bool $sealed = false;

    private bool $closed = false;

    public function __construct(
        private readonly PDO $database,
        private readonly CompanyBackupArchiveLimits $limits =
            new CompanyBackupArchiveLimits(),
    ) {
        $registryKey = 'profile:company_backup';
        try {
            $driver = $database->getAttribute(PDO::ATTR_DRIVER_NAME);
            if (!is_string($driver) || !in_array($driver, ['mysql', 'sqlite'], true)) {
                throw self::error('import_hash_map_driver_unsupported', $registryKey);
            }
            $this->mysql = $driver === 'mysql';
            $this->table = 'company_backup_hash_' . bin2hex(random_bytes(12));
            $this->quotedTable = $driver === 'mysql'
                ? '`' . $this->table . '`'
                : '"' . $this->table . '"';
            $created = $driver === 'mysql'
                ? $database->exec(
                    'CREATE TEMPORARY TABLE ' . $this->quotedTable . ' ('
                        . '`registry_key` VARCHAR(191) CHARACTER SET ascii'
                        . ' COLLATE ascii_bin NOT NULL,'
                        . '`hash_column` VARCHAR(64) CHARACTER SET ascii'
                        . ' COLLATE ascii_bin NOT NULL,'
                        . '`source_hash` CHAR(64) CHARACTER SET ascii'
                        . ' COLLATE ascii_bin NOT NULL,'
                        . '`mapped_hash` CHAR(64) CHARACTER SET ascii'
                        . ' COLLATE ascii_bin NOT NULL,'
                        . 'PRIMARY KEY (`registry_key`, `hash_column`, `source_hash`)'
                        . ') ENGINE=InnoDB',
                )
                : $database->exec(
                    'CREATE TEMP TABLE ' . $this->quotedTable . ' ('
                        . 'registry_key TEXT NOT NULL,'
                        . 'hash_column TEXT NOT NULL,'
                        . 'source_hash TEXT NOT NULL,'
                        . 'mapped_hash TEXT NOT NULL,'
                        . 'PRIMARY KEY (registry_key, hash_column, source_hash)'
                        . ')',
                );
            if ($created === false) {
                throw new \RuntimeException('Dočasnou hashovou mapu nelze vytvořit.');
            }
            $select = $database->prepare(
                'SELECT mapped_hash FROM ' . $this->quotedTable
                    . ' WHERE registry_key = ? AND hash_column = ?'
                    . ' AND source_hash = ?',
            );
            $insert = $database->prepare(
                'INSERT INTO ' . $this->quotedTable
                    . ' (registry_key, hash_column, source_hash, mapped_hash)'
                    . ' VALUES (?, ?, ?, ?)',
            );
            if (!$select instanceof PDOStatement
                || !$insert instanceof PDOStatement
            ) {
                throw new \RuntimeException('Hashovou mapu nelze připravit.');
            }
            $this->select = $select;
            $this->insert = $insert;
        } catch (CompanyBackupImportWriteException $e) {
            $this->bestEffortDrop();
            $this->closed = true;
            throw $e;
        } catch (\Throwable $e) {
            $this->bestEffortDrop();
            $this->closed = true;
            throw self::error(
                'import_hash_map_unavailable',
                $registryKey,
                previous: $e,
            );
        }
    }

    /**
     * @param array<string,mixed> $sourceRow
     * @param array<string,mixed> $targetRow
     */
    public function addRow(
        CompanyBackupTableProjection $projection,
        array $sourceRow,
        array $targetRow,
    ): void {
        $this->assertWritable($projection->registryKey);
        if (array_keys($sourceRow) !== $projection->dataColumns
            || array_keys($targetRow) !== $projection->dataColumns
        ) {
            throw self::error(
                'import_hash_row_invalid',
                $projection->registryKey,
            );
        }
        try {
            $projection->derivedHashes->assertSourceRow($sourceRow);
            $projection->derivedHashes->assertSourceRow($targetRow);
        } catch (CompanyBackupDataSourceException $e) {
            throw self::error(
                'import_hash_row_invalid',
                $projection->registryKey,
                $e->column,
                $e,
            );
        }

        /** @var list<array{string,string,string,string,int}> $pending */
        $pending = [];
        $additionalBytes = 0;
        foreach ($projection->derivedHashes->hashes as $hash) {
            $source = $sourceRow[$hash->hashColumn];
            $target = $targetRow[$hash->hashColumn];
            if ($source === null && $target === null && $hash->nullable) {
                continue;
            }
            if (!self::validHash($source) || !self::validHash($target)) {
                throw self::error(
                    'import_hash_row_invalid',
                    $projection->registryKey,
                    $hash->hashColumn,
                );
            }
            $existing = $this->lookup(
                $projection->registryKey,
                $hash->hashColumn,
                $source,
            );
            if ($existing !== null) {
                if (!hash_equals($existing, $target)) {
                    throw self::error(
                        'import_hash_mapping_ambiguous',
                        $projection->registryKey,
                        $hash->hashColumn,
                    );
                }
                continue;
            }
            $pairBytes = strlen($projection->registryKey)
                + strlen($hash->hashColumn)
                + strlen($source)
                + strlen($target);
            $pending[] = [
                $projection->registryKey,
                $hash->hashColumn,
                $source,
                $target,
                $pairBytes,
            ];
            $additionalBytes += $pairBytes;
        }
        if (count($pending) > $this->limits->maxSourceIndexEntries - $this->mappings) {
            throw self::error(
                'import_hash_mapping_limit_exceeded',
                $projection->registryKey,
            );
        }
        if ($additionalBytes > $this->limits->maxSourceIndexBytes - $this->bytes) {
            throw self::error(
                'import_hash_mapping_size_exceeded',
                $projection->registryKey,
            );
        }

        foreach ($pending as [$registryKey, $column, $source, $target, $pairBytes]) {
            $this->insert($registryKey, $column, $source, $target);
            $this->mappings++;
            $this->bytes += $pairBytes;
        }
    }

    public function resolve(
        CompanyBackupEmbeddedHashReference $reference,
        string $sourceHash,
    ): string {
        $this->assertOpen($reference->target);
        if (!self::validHash($sourceHash)) {
            throw self::error(
                'import_hash_reference_invalid',
                $reference->target,
                $reference->targetHashColumn,
            );
        }
        $mapped = $this->lookup(
            $reference->target,
            $reference->targetHashColumn,
            $sourceHash,
        );
        if ($mapped === null) {
            throw self::error(
                'import_hash_reference_unresolved',
                $reference->target,
                $reference->targetHashColumn,
            );
        }
        return $mapped;
    }

    public function seal(): void
    {
        $this->assertOpen('profile:company_backup');
        if ($this->sealed) {
            throw new \LogicException('Cílová hashová mapa už je uzavřená.');
        }
        $this->sealed = true;
    }

    public function isSealed(): bool
    {
        $this->assertOpen('profile:company_backup');
        return $this->sealed;
    }

    public function mappingCount(): int
    {
        return $this->mappings;
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
        $this->select = null;
        $this->insert = null;
        try {
            $this->drop();
        } catch (\Throwable $e) {
            $this->closed = true;
            throw self::error(
                'import_hash_map_cleanup_failed',
                'profile:company_backup',
                previous: $e,
            );
        }
        $this->closed = true;
    }

    public function __destruct()
    {
        if ($this->closed) {
            return;
        }
        $this->select = null;
        $this->insert = null;
        $this->bestEffortDrop();
        $this->closed = true;
    }

    private function lookup(
        string $registryKey,
        string $column,
        string $sourceHash,
    ): ?string {
        $statement = $this->select;
        if (!$statement instanceof PDOStatement) {
            throw self::error('import_hash_map_closed', $registryKey, $column);
        }
        try {
            if (!$statement->execute([$registryKey, $column, $sourceHash])) {
                throw new \RuntimeException('Hashový lookup selhal.');
            }
            $value = $statement->fetchColumn();
            if (!$statement->closeCursor()) {
                throw new \RuntimeException('Hashový lookup nelze uzavřít.');
            }
        } catch (\Throwable $e) {
            try {
                $statement->closeCursor();
            } catch (\Throwable) {
                // Primární bezpečná chyba lookupu má přednost před úklidem.
            }
            throw self::error(
                'import_hash_map_lookup_failed',
                $registryKey,
                $column,
                $e,
            );
        }
        if ($value === false) {
            return null;
        }
        if (!is_string($value) || !self::validHash($value)) {
            throw self::error(
                'import_hash_map_corrupted',
                $registryKey,
                $column,
            );
        }
        return $value;
    }

    private function insert(
        string $registryKey,
        string $column,
        string $sourceHash,
        string $targetHash,
    ): void {
        $statement = $this->insert;
        if (!$statement instanceof PDOStatement) {
            throw self::error('import_hash_map_closed', $registryKey, $column);
        }
        try {
            if (!$statement->execute([
                $registryKey,
                $column,
                $sourceHash,
                $targetHash,
            ]) || $statement->rowCount() !== 1) {
                throw new \RuntimeException('Hashové mapování nelze zapsat.');
            }
            if (!$statement->closeCursor()) {
                throw new \RuntimeException('Hashový zápis nelze uzavřít.');
            }
        } catch (\Throwable $e) {
            try {
                $statement->closeCursor();
            } catch (\Throwable) {
                // Primární bezpečná chyba zápisu má přednost před úklidem.
            }
            throw self::error(
                'import_hash_map_insert_failed',
                $registryKey,
                $column,
                $e,
            );
        }
    }

    private function assertWritable(string $registryKey): void
    {
        $this->assertOpen($registryKey);
        if ($this->sealed) {
            throw self::error('import_hash_map_sealed', $registryKey);
        }
    }

    private function assertOpen(string $registryKey): void
    {
        if ($this->closed) {
            throw self::error('import_hash_map_closed', $registryKey);
        }
    }

    private function drop(): void
    {
        if ($this->database->exec(
            'DROP ' . ($this->mysql ? 'TEMPORARY ' : '')
                . 'TABLE IF EXISTS ' . $this->quotedTable,
        ) === false) {
            throw new \RuntimeException('Dočasnou hashovou mapu nelze odstranit.');
        }
    }

    private function bestEffortDrop(): void
    {
        if ($this->quotedTable === '') {
            return;
        }
        try {
            $this->database->exec(
                'DROP ' . ($this->mysql ? 'TEMPORARY ' : '')
                    . 'TABLE IF EXISTS ' . $this->quotedTable,
            );
        } catch (\Throwable) {
            // Destruktor ani chyba konstrukce nesmí překrýt primární chybu.
        }
    }

    private static function validHash(mixed $value): bool
    {
        return is_string($value)
            && preg_match('/^[0-9a-f]{64}$/D', $value) === 1;
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
