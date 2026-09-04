<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

use MyInvoice\Service\Backup\CanonicalJson;
use MyInvoice\Service\Backup\Registry\TenantDataPolicy;
use PDO;
use PDOStatement;

/**
 * SQL-backed mapa nových ID. Jediný vícenásobný INSERT ukládá cílový sentinel
 * i dvojice odpovídajících aliasů atomicky; temp InnoDB drží velké mapy mimo
 * PHP heap.
 */
final class CompanyBackupSqlTargetIdentityMap implements CompanyBackupTargetIdentityMap
{
    private const SOURCE_PREFIX = 's:';
    private const TARGET_PREFIX = 't:';
    private const LOOKUP_ID_BYTES = 73;
    private const KEY_ID_BYTES = 71;

    private readonly string $table;

    private ?PDOStatement $select = null;

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
            throw new CompanyBackupIdentityMapException(
                'target_identity_map_unavailable',
                previous: $e,
            );
        }
        $this->table = 'company_backup_target_' . $suffix;
        try {
            $driver = $database->getAttribute(PDO::ATTR_DRIVER_NAME);
            if ($driver === 'mysql') {
                $created = $database->exec(
                    'CREATE TEMPORARY TABLE `' . $this->table . '` ('
                    . 'lookup_id VARBINARY(73) NOT NULL,'
                    . 'lookup_key_payload LONGBLOB NOT NULL,'
                    . 'mapped_key_payload LONGBLOB NOT NULL,'
                    . 'target_key_id VARBINARY(71) NOT NULL,'
                    . 'external_requirement_id VARBINARY(71) NULL,'
                    . 'PRIMARY KEY (lookup_id)'
                    . ') ENGINE=InnoDB',
                );
                $quotedTable = '`' . $this->table . '`';
            } elseif ($driver === 'sqlite') {
                $created = $database->exec(
                    'CREATE TEMP TABLE "' . $this->table . '" ('
                    . 'lookup_id TEXT NOT NULL PRIMARY KEY,'
                    . 'lookup_key_payload BLOB NOT NULL,'
                    . 'mapped_key_payload BLOB NOT NULL,'
                    . 'target_key_id TEXT NOT NULL,'
                    . 'external_requirement_id TEXT NULL'
                    . ')',
                );
                $quotedTable = '"' . $this->table . '"';
            } else {
                throw new CompanyBackupIdentityMapException(
                    'target_identity_map_driver_unsupported',
                );
            }
            if ($created === false) {
                throw new \RuntimeException('Dočasnou cílovou mapu nelze vytvořit.');
            }
            $select = $database->prepare(
                'SELECT lookup_key_payload, mapped_key_payload, target_key_id,'
                . ' external_requirement_id'
                . ' FROM ' . $quotedTable
                . ' WHERE lookup_id = :lookup_id',
            );
            if (!$select instanceof PDOStatement) {
                throw new \RuntimeException('Lookup cílové mapy nelze připravit.');
            }
            $this->select = $select;
        } catch (CompanyBackupIdentityMapException $e) {
            $this->bestEffortDrop();
            $this->closed = true;
            throw $e;
        } catch (\Throwable $e) {
            $this->bestEffortDrop();
            $this->closed = true;
            throw new CompanyBackupIdentityMapException(
                'target_identity_map_unavailable',
                previous: $e,
            );
        }
    }

    public function add(
        CompanyBackupSourceIdentity $sourceIdentity,
        CompanyBackupSourceIdentity $targetIdentity,
    ): void {
        $this->assertWritable();
        $registryKey = $sourceIdentity->primaryKey->registryKey;
        $pairs = $this->identityPairs(
            $sourceIdentity,
            $targetIdentity,
            $registryKey,
        );
        $targetPrimaryKey = $targetIdentity->primaryKey;
        $externalRequirementId = $this->externalRequirementId(
            $sourceIdentity,
            $targetIdentity,
            $registryKey,
        );
        if ($this->identities >= $this->limits->maxSourceIdentities) {
            throw self::error('target_identity_limit_exceeded', $registryKey);
        }

        $keyCount = count($pairs);
        if ($keyCount < 1 || $keyCount > $this->limits->maxSourceKeysPerRow) {
            throw self::error(
                'target_identity_key_count_exceeded',
                $registryKey,
            );
        }
        if ($keyCount > $this->limits->maxSourceIndexEntries - $this->entries) {
            throw self::error(
                'target_identity_entry_limit_exceeded',
                $registryKey,
            );
        }

        $targetPayload = CanonicalJson::encode($targetPrimaryKey->toArray());
        $sourcePayloads = [];
        $mappedPayloads = [];
        $additionalBytes = self::LOOKUP_ID_BYTES
            + self::KEY_ID_BYTES
            + strlen($targetPayload) * 2;
        foreach ($pairs as [$sourceKey, $mappedKey]) {
            $payload = CanonicalJson::encode($sourceKey->toArray());
            $mappedPayload = CanonicalJson::encode($mappedKey->toArray());
            $sourcePayloads[] = $payload;
            $mappedPayloads[] = $mappedPayload;
            $additionalBytes += self::LOOKUP_ID_BYTES
                + self::KEY_ID_BYTES
                + strlen($payload)
                + strlen($mappedPayload)
                + strlen($externalRequirementId ?? '');
        }
        if ($additionalBytes > $this->limits->maxSourceIndexBytes - $this->bytes) {
            throw self::error('target_identity_size_exceeded', $registryKey);
        }

        foreach ($pairs as [$sourceKey]) {
            if ($this->lookupSource($sourceKey) !== null) {
                throw self::error(
                    'target_identity_source_duplicate',
                    $registryKey,
                );
            }
        }
        if ($this->lookupTarget($targetPrimaryKey) !== null) {
            throw self::error(
                'target_identity_target_duplicate',
                $registryKey,
            );
        }

        $rows = [[
            self::TARGET_PREFIX . $targetPrimaryKey->id,
            $targetPayload,
            $targetPayload,
            $targetPrimaryKey->id,
            null,
        ]];
        foreach ($pairs as $index => [$sourceKey]) {
            $rows[] = [
                self::SOURCE_PREFIX . $sourceKey->id,
                $sourcePayloads[$index],
                $mappedPayloads[$index],
                $targetPrimaryKey->id,
                $externalRequirementId,
            ];
        }
        $this->insertRows($rows, $registryKey);

        $this->identities++;
        $this->entries += $keyCount;
        $this->bytes += $additionalBytes;
    }

    public function find(
        CompanyBackupSourceKey $sourceKey,
    ): ?CompanyBackupSourceKey {
        return $this->findMatch($sourceKey)?->mappedKey;
    }

    public function findMatch(
        CompanyBackupSourceKey $sourceKey,
    ): ?CompanyBackupTargetIdentityMatch {
        $this->assertOpen();
        return $this->lookupSourceMatch($sourceKey);
    }

    public function seal(): void
    {
        $this->assertOpen();
        if ($this->sealed) {
            throw new \LogicException('Cílová mapa už je uzavřená.');
        }
        $this->sealed = true;
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
        try {
            $this->drop();
        } catch (\Throwable $e) {
            $this->closed = true;
            throw new CompanyBackupIdentityMapException(
                'target_identity_map_cleanup_failed',
                previous: $e,
            );
        }
        $this->closed = true;
    }

    public function __destruct()
    {
        if (!$this->closed) {
            $this->select = null;
            $this->bestEffortDrop();
            $this->closed = true;
        }
    }

    private function lookupSource(
        CompanyBackupSourceKey $sourceKey,
    ): ?CompanyBackupSourceKey {
        return $this->lookupSourceMatch($sourceKey)?->mappedKey;
    }

    private function lookupSourceMatch(
        CompanyBackupSourceKey $sourceKey,
    ): ?CompanyBackupTargetIdentityMatch {
        $row = $this->lookupRow(
            self::SOURCE_PREFIX . $sourceKey->id,
            $sourceKey->registryKey,
        );
        if ($row === null) {
            return null;
        }
        $storedSource = $this->decodeKey(
            $row['lookup_key_payload'],
            $sourceKey->registryKey,
        );
        if (!$storedSource->equals($sourceKey)) {
            throw self::error(
                'target_identity_source_hash_collision',
                $sourceKey->registryKey,
            );
        }
        $targetPrimaryKey = $this->targetById(
            $row['target_key_id'],
            $sourceKey->registryKey,
        );
        $mapped = $this->decodeKey(
            $row['mapped_key_payload'],
            $sourceKey->registryKey,
        );
        if ($targetPrimaryKey->registryKey !== $sourceKey->registryKey
            || $mapped->registryKey !== $sourceKey->registryKey
            || $mapped->columns !== $sourceKey->columns
        ) {
            throw self::error(
                'target_identity_map_corrupted',
                $sourceKey->registryKey,
            );
        }
        $externalRequirementId = $row['external_requirement_id'];
        if ($externalRequirementId !== null
            && preg_match(
                '/^sha256:[0-9a-f]{64}$/D',
                $externalRequirementId,
            ) !== 1
        ) {
            throw self::error(
                'target_identity_map_corrupted',
                $sourceKey->registryKey,
            );
        }
        try {
            return new CompanyBackupTargetIdentityMatch(
                $storedSource,
                $mapped,
                $targetPrimaryKey,
                $externalRequirementId,
            );
        } catch (\InvalidArgumentException $e) {
            throw self::error(
                'target_identity_map_corrupted',
                $sourceKey->registryKey,
                $e,
            );
        }
    }

    private function lookupTarget(
        CompanyBackupSourceKey $targetKey,
    ): ?CompanyBackupSourceKey {
        $row = $this->lookupRow(
            self::TARGET_PREFIX . $targetKey->id,
            $targetKey->registryKey,
        );
        if ($row === null) {
            return null;
        }
        $stored = $this->decodeKey(
            $row['lookup_key_payload'],
            $targetKey->registryKey,
        );
        $mapped = $this->decodeKey(
            $row['mapped_key_payload'],
            $targetKey->registryKey,
        );
        if (!$stored->equals($targetKey) || !$mapped->equals($targetKey)) {
            throw self::error(
                'target_identity_target_hash_collision',
                $targetKey->registryKey,
            );
        }
        if (!hash_equals($stored->id, $row['target_key_id'])) {
            throw self::error(
                'target_identity_map_corrupted',
                $targetKey->registryKey,
            );
        }
        if ($row['external_requirement_id'] !== null) {
            throw self::error(
                'target_identity_map_corrupted',
                $targetKey->registryKey,
            );
        }
        return $stored;
    }

    private function targetById(
        string $targetKeyId,
        string $registryKey,
    ): CompanyBackupSourceKey {
        if (preg_match('/^sha256:[0-9a-f]{64}$/D', $targetKeyId) !== 1) {
            throw self::error('target_identity_map_corrupted', $registryKey);
        }
        $row = $this->lookupRow(
            self::TARGET_PREFIX . $targetKeyId,
            $registryKey,
        );
        if ($row === null
            || !hash_equals($targetKeyId, $row['target_key_id'])
            || $row['external_requirement_id'] !== null
        ) {
            throw self::error('target_identity_map_corrupted', $registryKey);
        }
        $target = $this->decodeKey($row['lookup_key_payload'], $registryKey);
        $mapped = $this->decodeKey($row['mapped_key_payload'], $registryKey);
        if (!hash_equals($targetKeyId, $target->id)
            || !$mapped->equals($target)
        ) {
            throw self::error('target_identity_map_corrupted', $registryKey);
        }
        return $target;
    }

    /**
     * @return array{
     *   lookup_key_payload:string,
     *   mapped_key_payload:string,
     *   target_key_id:string,
     *   external_requirement_id:?string
     * }|null
     */
    private function lookupRow(
        string $lookupId,
        string $registryKey,
    ): ?array {
        $select = $this->select;
        if (!$select instanceof PDOStatement) {
            throw new \LogicException('Cílová mapa není otevřená.');
        }
        try {
            if (!$select->execute(['lookup_id' => $lookupId])) {
                throw new \RuntimeException('Čtení cílové mapy selhalo.');
            }
            $row = $select->fetch(PDO::FETCH_ASSOC);
            if (!$select->closeCursor()) {
                throw new \RuntimeException('Čtení cílové mapy nelze uzavřít.');
            }
        } catch (\Throwable $e) {
            try {
                $select->closeCursor();
            } catch (\Throwable) {
                // Primární bezpečná chyba čtení má přednost před úklidem.
            }
            throw self::error(
                'target_identity_map_read_failed',
                $registryKey,
                $e,
            );
        }
        if ($row === false) {
            return null;
        }
        if (!is_array($row)
            || array_is_list($row)
            || array_keys($row) !== [
                'lookup_key_payload',
                'mapped_key_payload',
                'target_key_id',
                'external_requirement_id',
            ]
            || !is_string($row['lookup_key_payload'])
            || !is_string($row['mapped_key_payload'])
            || !is_string($row['target_key_id'])
            || ($row['external_requirement_id'] !== null
                && !is_string($row['external_requirement_id']))
        ) {
            throw self::error('target_identity_map_corrupted', $registryKey);
        }
        return $row;
    }

    private function decodeKey(
        string $payload,
        string $registryKey,
    ): CompanyBackupSourceKey {
        try {
            $decoded = json_decode($payload, true, 128, JSON_THROW_ON_ERROR);
            $key = CompanyBackupSourceKey::fromArray(
                $decoded,
                $this->limits->maxSourceKeyBytes,
            );
            if (!hash_equals(CanonicalJson::encode($key->toArray()), $payload)) {
                throw new \InvalidArgumentException(
                    'Uložený klíč cílové mapy není kanonický.',
                );
            }
        } catch (\Throwable $e) {
            throw self::error(
                'target_identity_map_corrupted',
                $registryKey,
                $e,
            );
        }
        return $key;
    }

    private function assertKeyWithinLimits(
        CompanyBackupSourceKey $key,
        string $registryKey,
    ): void {
        try {
            $validated = CompanyBackupSourceKey::fromValues(
                $key->registryKey,
                $key->values,
                $this->limits->maxSourceKeyBytes,
            );
        } catch (CompanyBackupPreflightException $e) {
            throw self::error('target_identity_key_invalid', $registryKey, $e);
        }
        if (!$validated->equals($key) || !hash_equals($validated->id, $key->id)) {
            throw self::error('target_identity_key_invalid', $registryKey);
        }
    }

    /**
     * @return list<array{CompanyBackupSourceKey,CompanyBackupSourceKey}>
     */
    private function identityPairs(
        CompanyBackupSourceIdentity $source,
        CompanyBackupSourceIdentity $target,
        string $registryKey,
    ): array {
        if ($target->policy !== $source->policy
            || $target->primaryKey->registryKey !== $registryKey
            || ($source->tenantScopedPrimaryKey === null)
                !== ($target->tenantScopedPrimaryKey === null)
            || ($source->naturalKey === null) !== ($target->naturalKey === null)
            || count($source->referenceKeys) !== count($target->referenceKeys)
        ) {
            throw self::error('target_identity_key_invalid', $registryKey);
        }

        $pairs = [];
        $this->addIdentityPair(
            $pairs,
            $source->primaryKey,
            $target->primaryKey,
            $registryKey,
        );
        if ($source->tenantScopedPrimaryKey !== null
            && $target->tenantScopedPrimaryKey !== null
        ) {
            $this->addIdentityPair(
                $pairs,
                $source->tenantScopedPrimaryKey,
                $target->tenantScopedPrimaryKey,
                $registryKey,
            );
        }
        if ($source->naturalKey !== null && $target->naturalKey !== null) {
            $this->addIdentityPair(
                $pairs,
                $source->naturalKey,
                $target->naturalKey,
                $registryKey,
            );
        }
        foreach ($source->referenceKeys as $index => $sourceKey) {
            $this->addIdentityPair(
                $pairs,
                $sourceKey,
                $target->referenceKeys[$index],
                $registryKey,
            );
        }
        return array_values($pairs);
    }

    private function externalRequirementId(
        CompanyBackupSourceIdentity $source,
        CompanyBackupSourceIdentity $target,
        string $registryKey,
    ): ?string {
        if ($source->policy !== TenantDataPolicy::GlobalReference) {
            return null;
        }
        $sourceNaturalKey = $source->naturalKey;
        $targetNaturalKey = $target->naturalKey;
        if ($sourceNaturalKey === null
            || $targetNaturalKey === null
            || $sourceNaturalKey->values !== $targetNaturalKey->values
        ) {
            throw self::error('target_identity_key_invalid', $registryKey);
        }
        return CompanyBackupExternalReferenceRequirement::idFor(
            CompanyBackupReferenceMapping::GlobalNaturalKey,
            $registryKey,
            $sourceNaturalKey->values,
        );
    }

    /**
     * @param array<string,array{CompanyBackupSourceKey,CompanyBackupSourceKey}> $pairs
     */
    private function addIdentityPair(
        array &$pairs,
        CompanyBackupSourceKey $source,
        CompanyBackupSourceKey $target,
        string $registryKey,
    ): void {
        if ($source->registryKey !== $registryKey
            || $target->registryKey !== $registryKey
            || $source->columns !== $target->columns
        ) {
            throw self::error('target_identity_key_invalid', $registryKey);
        }
        $this->assertKeyWithinLimits($source, $registryKey);
        $this->assertKeyWithinLimits($target, $registryKey);
        $existing = $pairs[$source->id] ?? null;
        if ($existing !== null && !$existing[1]->equals($target)) {
            throw self::error('target_identity_key_invalid', $registryKey);
        }
        $pairs[$source->id] = [$source, $target];
    }

    /**
     * @param list<array{string,string,string,string,?string}> $rows
     */
    private function insertRows(array $rows, string $registryKey): void
    {
        $placeholders = implode(
            ', ',
            array_fill(0, count($rows), '(?, ?, ?, ?, ?)'),
        );
        $params = [];
        foreach ($rows as $row) {
            array_push($params, ...$row);
        }
        $statement = null;
        try {
            $prepared = $this->database->prepare(
                'INSERT INTO ' . $this->quotedTable()
                . ' (lookup_id, lookup_key_payload, mapped_key_payload,'
                . ' target_key_id, external_requirement_id) VALUES '
                . $placeholders,
            );
            if (!$prepared instanceof PDOStatement) {
                throw new \RuntimeException('Zápis cílové mapy nelze připravit.');
            }
            $statement = $prepared;
            if (!$statement->execute($params)) {
                throw new \RuntimeException('Zápis cílové mapy selhal.');
            }
            if (!$statement->closeCursor()) {
                throw new \RuntimeException('Zápis cílové mapy nelze uzavřít.');
            }
            $statement = null;
        } catch (\Throwable $e) {
            throw self::error(
                'target_identity_map_write_failed',
                $registryKey,
                $e,
            );
        } finally {
            if ($statement instanceof PDOStatement) {
                try {
                    $statement->closeCursor();
                } catch (\Throwable) {
                    // Primární bezpečná chyba zápisu má přednost před úklidem.
                }
            }
        }
    }

    private function assertWritable(): void
    {
        $this->assertOpen();
        if ($this->sealed) {
            throw new \LogicException('Cílová mapa už je uzavřená.');
        }
    }

    private function assertOpen(): void
    {
        if ($this->closed) {
            throw new \LogicException('Cílová mapa už je zavřená.');
        }
    }

    private function quotedTable(): string
    {
        $driver = $this->database->getAttribute(PDO::ATTR_DRIVER_NAME);
        $quote = $driver === 'mysql' ? '`' : '"';
        return $quote . $this->table . $quote;
    }

    private function drop(): void
    {
        $driver = $this->database->getAttribute(PDO::ATTR_DRIVER_NAME);
        $temporary = $driver === 'mysql' ? 'TEMPORARY ' : '';
        $dropped = $this->database->exec(
            'DROP ' . $temporary . 'TABLE IF EXISTS ' . $this->quotedTable(),
        );
        if ($dropped === false) {
            throw new \RuntimeException('Dočasnou cílovou mapu nelze odstranit.');
        }
    }

    private function bestEffortDrop(): void
    {
        try {
            $this->drop();
        } catch (\Throwable) {
            // Destruktor ani selhání konstrukce nesmějí zastínit původní chybu.
        }
    }

    private static function error(
        string $errorCode,
        ?string $registryKey = null,
        ?\Throwable $previous = null,
    ): CompanyBackupIdentityMapException {
        return new CompanyBackupIdentityMapException(
            $errorCode,
            $registryKey,
            $previous,
        );
    }
}
