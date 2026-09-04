<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

use MyInvoice\Service\Backup\CanonicalJson;
use PDO;
use PDOStatement;

/**
 * Dočasný SQL index zdrojových klíčů. U MariaDB používá InnoDB, aby
 * velikost obnovy nebyla omezená operační pamětí PHP.
 */
final class CompanyBackupSqlSourceIdentityIndex implements CompanyBackupSourceIdentityIndex
{
    private const KEY_ID_BYTES = 71;

    private readonly string $table;

    private ?PDOStatement $select = null;

    private ?PDOStatement $insert = null;

    private int $identities = 0;

    private int $entries = 0;

    private int $bytes = 0;

    private bool $sealed = false;

    private bool $closed = false;

    public function __construct(
        private readonly PDO $database,
        private readonly CompanyBackupArchiveLimits $limits =
            new CompanyBackupArchiveLimits(),
    ) {
        try {
            $suffix = bin2hex(random_bytes(8));
        } catch (\Throwable $e) {
            throw new CompanyBackupPreflightException(
                'source_index_unavailable',
                previous: $e,
            );
        }
        $this->table = 'company_backup_source_' . $suffix;
        try {
            $driver = $database->getAttribute(PDO::ATTR_DRIVER_NAME);
            if ($driver === 'mysql') {
                $created = $database->exec(
                    'CREATE TEMPORARY TABLE `' . $this->table . '` ('
                    . 'key_id VARBINARY(71) NOT NULL,'
                    . 'identity_payload LONGBLOB NOT NULL,'
                    . 'PRIMARY KEY (key_id)'
                    . ') ENGINE=InnoDB',
                );
                $quotedTable = '`' . $this->table . '`';
            } elseif ($driver === 'sqlite') {
                $created = $database->exec(
                    'CREATE TEMP TABLE "' . $this->table . '" ('
                    . 'key_id TEXT NOT NULL PRIMARY KEY,'
                    . 'identity_payload BLOB NOT NULL'
                    . ')',
                );
                $quotedTable = '"' . $this->table . '"';
            } else {
                throw new CompanyBackupPreflightException(
                    'source_index_driver_unsupported',
                );
            }
            if ($created === false) {
                throw new \RuntimeException('Dočasnou tabulku indexu nelze vytvořit.');
            }
            $select = $database->prepare(
                'SELECT identity_payload FROM ' . $quotedTable
                . ' WHERE key_id = :key_id',
            );
            $insert = $database->prepare(
                'INSERT INTO ' . $quotedTable
                . ' (key_id, identity_payload) VALUES (:key_id, :identity_payload)',
            );
            if (!$select instanceof PDOStatement
                || !$insert instanceof PDOStatement
            ) {
                throw new \RuntimeException('Dotazy zdrojového indexu nelze připravit.');
            }
            $this->select = $select;
            $this->insert = $insert;
        } catch (CompanyBackupPreflightException $e) {
            $this->bestEffortDrop();
            $this->closed = true;
            throw $e;
        } catch (\Throwable $e) {
            $this->bestEffortDrop();
            $this->closed = true;
            throw new CompanyBackupPreflightException(
                'source_index_unavailable',
                previous: $e,
            );
        }
    }

    public function add(CompanyBackupSourceIdentity $identity): void
    {
        $this->assertWritable();
        if ($this->identities >= $this->limits->maxSourceIdentities) {
            throw new CompanyBackupPreflightException(
                'source_identity_limit_exceeded',
                $identity->primaryKey->registryKey,
            );
        }

        $keys = $identity->keys();
        $keyCount = count($keys);
        if ($keyCount < 1 || $keyCount > $this->limits->maxSourceKeysPerRow) {
            throw new CompanyBackupPreflightException(
                'source_key_count_exceeded',
                $identity->primaryKey->registryKey,
            );
        }
        if ($keyCount > $this->limits->maxSourceIndexEntries - $this->entries) {
            throw new CompanyBackupPreflightException(
                'source_index_entry_limit_exceeded',
                $identity->primaryKey->registryKey,
            );
        }

        $payload = CanonicalJson::encode($identity->toArray());
        $entryBytes = strlen($payload) + self::KEY_ID_BYTES;
        if ($keyCount > intdiv($this->limits->maxSourceIndexBytes, $entryBytes)
            || $keyCount * $entryBytes
                > $this->limits->maxSourceIndexBytes - $this->bytes
        ) {
            throw new CompanyBackupPreflightException(
                'source_index_size_exceeded',
                $identity->primaryKey->registryKey,
            );
        }

        foreach ($keys as $key) {
            if ($this->lookup($key) !== null) {
                throw new CompanyBackupPreflightException(
                    'source_key_duplicate',
                    $key->registryKey,
                );
            }
        }

        $insert = $this->insert;
        if (!$insert instanceof PDOStatement) {
            throw new \LogicException('Zdrojový index není otevřený.');
        }
        try {
            foreach ($keys as $key) {
                if (!$insert->execute([
                    'key_id' => $key->id,
                    'identity_payload' => $payload,
                ])) {
                    throw new \RuntimeException('Zápis zdrojového indexu selhal.');
                }
            }
        } catch (\Throwable $e) {
            throw new CompanyBackupPreflightException(
                'source_index_write_failed',
                $identity->primaryKey->registryKey,
                previous: $e,
            );
        }

        $this->identities++;
        $this->entries += $keyCount;
        $this->bytes += $keyCount * $entryBytes;
    }

    public function seal(): void
    {
        $this->assertOpen();
        if ($this->sealed) {
            throw new \LogicException('Zdrojový index už je uzavřený.');
        }
        $this->sealed = true;
    }

    public function find(CompanyBackupSourceKey $key): ?CompanyBackupSourceIdentity
    {
        $this->assertOpen();
        if (!$this->sealed) {
            throw new \LogicException('Zdrojový index ještě není uzavřený.');
        }
        return $this->lookup($key);
    }

    public function identityCount(): int
    {
        return $this->identities;
    }

    public function entryCount(): int
    {
        return $this->entries;
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
            throw new CompanyBackupPreflightException(
                'source_index_cleanup_failed',
                previous: $e,
            );
        }
        $this->closed = true;
    }

    public function __destruct()
    {
        if (!$this->closed) {
            $this->select = null;
            $this->insert = null;
            $this->bestEffortDrop();
            $this->closed = true;
        }
    }

    private function lookup(
        CompanyBackupSourceKey $key,
    ): ?CompanyBackupSourceIdentity {
        $select = $this->select;
        if (!$select instanceof PDOStatement) {
            throw new \LogicException('Zdrojový index není otevřený.');
        }
        try {
            if (!$select->execute(['key_id' => $key->id])) {
                throw new \RuntimeException('Čtení zdrojového indexu selhalo.');
            }
            $payload = $select->fetchColumn();
        } catch (\Throwable $e) {
            throw new CompanyBackupPreflightException(
                'source_index_read_failed',
                $key->registryKey,
                previous: $e,
            );
        }
        if ($payload === false) {
            return null;
        }
        if (!is_string($payload)) {
            throw new CompanyBackupPreflightException(
                'source_index_corrupted',
                $key->registryKey,
            );
        }
        try {
            $decoded = json_decode($payload, true, 128, JSON_THROW_ON_ERROR);
            $identity = CompanyBackupSourceIdentity::fromArray(
                $decoded,
                $this->limits->maxSourceKeyBytes,
            );
            if (!hash_equals(CanonicalJson::encode($identity->toArray()), $payload)) {
                throw new \InvalidArgumentException(
                    'Uložená zdrojová identita není kanonická.',
                );
            }
        } catch (\Throwable $e) {
            throw new CompanyBackupPreflightException(
                'source_index_corrupted',
                $key->registryKey,
                previous: $e,
            );
        }
        if (!$identity->hasKey($key)) {
            throw new CompanyBackupPreflightException(
                'source_key_hash_collision',
                $key->registryKey,
            );
        }
        return $identity;
    }

    private function assertWritable(): void
    {
        $this->assertOpen();
        if ($this->sealed) {
            throw new \LogicException('Uzavřený zdrojový index nelze měnit.');
        }
    }

    private function assertOpen(): void
    {
        if ($this->closed) {
            throw new \LogicException('Zdrojový index už je zavřený.');
        }
    }

    private function drop(): void
    {
        $driver = $this->database->getAttribute(PDO::ATTR_DRIVER_NAME);
        $temporary = $driver === 'mysql' ? 'TEMPORARY ' : '';
        $quote = $driver === 'mysql' ? '`' : '"';
        $dropped = $this->database->exec(
            'DROP ' . $temporary . 'TABLE IF EXISTS '
            . $quote . $this->table . $quote,
        );
        if ($dropped === false) {
            throw new \RuntimeException('Dočasnou tabulku indexu nelze odstranit.');
        }
    }

    private function bestEffortDrop(): void
    {
        try {
            $this->drop();
        } catch (\Throwable) {
        }
    }
}
