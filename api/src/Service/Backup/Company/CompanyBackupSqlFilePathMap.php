<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

use MyInvoice\Service\Backup\CanonicalJson;
use MyInvoice\Service\Backup\Registry\TenantDataDefinition;
use MyInvoice\Service\Backup\Registry\TenantDataObjectKind;
use MyInvoice\Service\Backup\Registry\TenantDataPolicy;
use MyInvoice\Service\Backup\Registry\TenantDataRegistry;
use MyInvoice\Service\Backup\Registry\TenantDataRegistrySnapshot;
use PDO;
use PDOStatement;

/**
 * Diskově omezená mapa manifestových vlastníků na tenantově přepsané cesty.
 * Souřadnice řádků zůstávají v dočasné SQL tabulce a každá musí být při prvním
 * průchodu spotřebována právě jednou.
 */
final class CompanyBackupSqlFilePathMap
{
    private readonly string $table;

    private string $quotedTable = '';

    private bool $mysql = false;

    private ?PDOStatement $select = null;

    private ?PDOStatement $insert = null;

    private ?PDOStatement $consume = null;

    /** @var array<string,CompanyBackupFileAreaProjection> */
    private array $areas = [];

    /**
     * @var array<string,list<array{
     *   area:CompanyBackupFileAreaProjection,
     *   owner:CompanyBackupFileOwnerDefinition
     * }>>
     */
    private array $ownersByRegistry = [];

    private ?int $sourceSupplierId = null;

    private ?int $targetSupplierId = null;

    private ?CompanyBackupFilePublicationPlan $publicationPlan = null;

    private int $fileEntries = 0;

    private int $ownerEntries = 0;

    private int $consumedOwners = 0;

    private int $indexedBytes = 0;

    private bool $finished = false;

    private bool $closed = false;

    public function __construct(
        private readonly PDO $database,
        private readonly CompanyBackupFileInventory $inventory,
        TenantDataRegistrySnapshot $sourceRegistry,
        private readonly TenantDataRegistrySnapshot $targetRegistry,
        private readonly CompanyBackupArchiveLimits $limits =
            new CompanyBackupArchiveLimits(),
    ) {
        $this->assertContext($sourceRegistry, $targetRegistry);
        $this->buildContracts($sourceRegistry, $targetRegistry);

        try {
            $driver = $database->getAttribute(PDO::ATTR_DRIVER_NAME);
            if (!is_string($driver)
                || !in_array($driver, ['mysql', 'sqlite'], true)
            ) {
                throw self::error('file_restore_map_driver_unsupported');
            }
            $this->mysql = $driver === 'mysql';
            $this->table = 'company_backup_file_path_'
                . bin2hex(random_bytes(12));
            $this->quotedTable = $this->mysql
                ? '`' . $this->table . '`'
                : '"' . $this->table . '"';
            $created = $this->mysql
                ? $database->exec(
                    'CREATE TEMPORARY TABLE ' . $this->quotedTable . ' ('
                        . '`owner_id` CHAR(64) CHARACTER SET ascii'
                        . ' COLLATE ascii_bin NOT NULL,'
                        . '`owner_payload` LONGBLOB NOT NULL,'
                        . '`area_registry_key` VARCHAR(191) CHARACTER SET ascii'
                        . ' COLLATE ascii_bin NOT NULL,'
                        . '`source_path` VARBINARY(1024) NOT NULL,'
                        . '`consumed` TINYINT UNSIGNED NOT NULL DEFAULT 0,'
                        . 'PRIMARY KEY (`owner_id`)'
                        . ') ENGINE=InnoDB',
                )
                : $database->exec(
                    'CREATE TEMP TABLE ' . $this->quotedTable . ' ('
                        . 'owner_id TEXT NOT NULL PRIMARY KEY,'
                        . 'owner_payload BLOB NOT NULL,'
                        . 'area_registry_key TEXT NOT NULL,'
                        . 'source_path BLOB NOT NULL,'
                        . 'consumed INTEGER NOT NULL DEFAULT 0'
                        . ')',
                );
            if ($created === false) {
                throw new \RuntimeException(
                    'Dočasnou mapu souborových cest nelze vytvořit.',
                );
            }
            $select = $database->prepare(
                'SELECT owner_payload, area_registry_key, source_path, consumed'
                    . ' FROM ' . $this->quotedTable
                    . ' WHERE owner_id = ?',
            );
            $insert = $database->prepare(
                'INSERT INTO ' . $this->quotedTable
                    . ' (owner_id, owner_payload, area_registry_key, source_path)'
                    . ' VALUES (?, ?, ?, ?)',
            );
            $consume = $database->prepare(
                'UPDATE ' . $this->quotedTable . ' SET consumed = 1'
                    . ' WHERE owner_id = ? AND consumed = 0',
            );
            if (!$select instanceof PDOStatement
                || !$insert instanceof PDOStatement
                || !$consume instanceof PDOStatement
            ) {
                throw new \RuntimeException(
                    'Dotazy mapy souborových cest nelze připravit.',
                );
            }
            $this->select = $select;
            $this->insert = $insert;
            $this->consume = $consume;
            $this->indexInventory();
        } catch (CompanyBackupFileRestoreException $e) {
            $this->bestEffortDrop();
            $this->closed = true;
            throw $e;
        } catch (\Throwable $e) {
            $this->bestEffortDrop();
            $this->closed = true;
            throw self::error('file_restore_map_unavailable', previous: $e);
        }
    }

    /**
     * @param array<string,mixed> $sourceRow
     * @param array<string,mixed> $targetRow
     * @return array<string,mixed>
     */
    public function transform(
        CompanyBackupTableProjection $projection,
        array $sourceRow,
        array $targetRow,
        bool $consume,
    ): array {
        $this->assertOpen($projection->registryKey);
        if ($this->finished) {
            throw self::error(
                'file_restore_map_finished',
                $projection->registryKey,
            );
        }
        if ($projection->registryKey === 'table:supplier') {
            $this->bindSupplier($projection, $sourceRow, $targetRow);
        }

        foreach ($this->ownersByRegistry[$projection->registryKey] ?? [] as $context) {
            $area = $context['area'];
            $owner = $context['owner'];
            $payload = $this->ownerPayload($projection, $sourceRow, $owner);
            $ownerId = hash('sha256', $payload);
            $binding = $this->lookup($ownerId, $area->registryKey);
            $sourceStoredPath = $this->storedPath(
                $sourceRow,
                $owner,
                $area->registryKey,
            );
            $targetStoredPath = $this->storedPath(
                $targetRow,
                $owner,
                $area->registryKey,
            );
            if ($binding === null) {
                if ($sourceStoredPath !== null || $targetStoredPath !== null) {
                    throw self::error(
                        'file_restore_inventory_owner_missing',
                        $area->registryKey,
                    );
                }
                continue;
            }
            if (!hash_equals($payload, $binding['owner_payload'])) {
                throw self::error(
                    'file_restore_owner_hash_collision',
                    $area->registryKey,
                );
            }
            if ($binding['area_registry_key'] !== $area->registryKey) {
                throw self::error(
                    'file_restore_map_corrupted',
                    $area->registryKey,
                );
            }
            $sourceSupplierId = $this->sourceSupplierId;
            $targetSupplierId = $this->targetSupplierId;
            if (!is_int($sourceSupplierId) || !is_int($targetSupplierId)) {
                throw self::error(
                    'file_restore_supplier_identity_missing',
                    $area->registryKey,
                );
            }
            try {
                $expected = $owner->storedPrefix
                    . $area->pathPolicy->storedRelativePath(
                        $binding['source_path'],
                        $sourceSupplierId,
                    );
                $targetPath = $area->pathPolicy->restoreTargetPath(
                    $binding['source_path'],
                    $sourceSupplierId,
                    $targetSupplierId,
                );
                $targetStoredPathValue = $owner->storedPrefix
                    . $area->pathPolicy->storedRelativePath(
                        $targetPath,
                        $targetSupplierId,
                    );
            } catch (\InvalidArgumentException $e) {
                throw self::error(
                    'file_restore_target_path_invalid',
                    $area->registryKey,
                    $e,
                );
            }
            if ($sourceStoredPath !== $expected
                || $targetStoredPath !== $expected
            ) {
                throw self::error(
                    'file_restore_owner_path_mismatch',
                    $area->registryKey,
                );
            }
            $targetRow = $this->replaceStoredPath(
                $targetRow,
                $owner,
                $expected,
                $targetStoredPathValue,
                $area->registryKey,
            );
            if ($consume) {
                if ($binding['consumed']) {
                    throw self::error(
                        'file_restore_owner_duplicate',
                        $area->registryKey,
                    );
                }
                $this->markConsumed($ownerId, $area->registryKey);
                $this->consumedOwners++;
            } elseif (!$binding['consumed']) {
                throw self::error(
                    'file_restore_owner_not_consumed',
                    $area->registryKey,
                );
            }
        }
        return $targetRow;
    }

    public function finish(): void
    {
        $this->assertOpen();
        if ($this->finished) {
            throw self::error('file_restore_map_finished');
        }
        if (!is_int($this->sourceSupplierId)
            || !is_int($this->targetSupplierId)
        ) {
            throw self::error('file_restore_supplier_identity_missing');
        }
        try {
            $statement = $this->database->query(
                'SELECT COUNT(*) FROM ' . $this->quotedTable
                    . ' WHERE consumed = 0',
            );
            if ($statement === false) {
                throw new \RuntimeException(
                    'Nelze ověřit spotřebu souborových vlastníků.',
                );
            }
            $remaining = $statement->fetchColumn();
            if (!$statement->closeCursor()) {
                throw new \RuntimeException(
                    'Kontrolu souborových vlastníků nelze uzavřít.',
                );
            }
        } catch (\Throwable $e) {
            throw self::error(
                'file_restore_map_read_failed',
                previous: $e,
            );
        }
        if (!is_int($remaining) && !is_string($remaining)) {
            throw self::error('file_restore_map_corrupted');
        }
        $remaining = filter_var(
            $remaining,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 0]],
        );
        if (!is_int($remaining)
            || $remaining !== 0
            || $this->consumedOwners !== $this->ownerEntries
        ) {
            throw self::error('file_restore_owner_unconsumed');
        }
        $this->finished = true;
    }

    public function fileEntryCount(): int
    {
        return $this->fileEntries;
    }

    public function ownerEntryCount(): int
    {
        return $this->ownerEntries;
    }

    public function indexedBytes(): int
    {
        return $this->indexedBytes;
    }

    public function publicationPlan(): CompanyBackupFilePublicationPlan
    {
        $this->assertOpen();
        if (!$this->finished
            || !$this->publicationPlan instanceof CompanyBackupFilePublicationPlan
        ) {
            throw self::error('file_restore_map_not_finished');
        }
        return $this->publicationPlan;
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->select = null;
        $this->insert = null;
        $this->consume = null;
        try {
            $this->drop();
        } catch (\Throwable $e) {
            $this->closed = true;
            throw self::error('file_restore_map_cleanup_failed', previous: $e);
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
        $this->consume = null;
        $this->bestEffortDrop();
        $this->closed = true;
    }

    private function assertContext(
        TenantDataRegistrySnapshot $sourceRegistry,
        TenantDataRegistrySnapshot $targetRegistry,
    ): void {
        if ($sourceRegistry->profile !== TenantDataRegistry::COMPANY_BACKUP_PROFILE
            || $targetRegistry->profile
                !== TenantDataRegistry::COMPANY_BACKUP_PROFILE
            || !hash_equals(
                $sourceRegistry->fingerprint,
                $this->inventory->registryFingerprint,
            )
            || !hash_equals(
                $sourceRegistry->fingerprint,
                $targetRegistry->fingerprint,
            )
        ) {
            throw self::error('file_restore_context_mismatch');
        }
    }

    private function buildContracts(
        TenantDataRegistrySnapshot $sourceRegistry,
        TenantDataRegistrySnapshot $targetRegistry,
    ): void {
        $ownerSignatures = [];
        try {
            foreach ($this->inventory->areas as $inventoryArea) {
                $sourceDefinition = $sourceRegistry->registry->definition(
                    $inventoryArea->registryKey,
                );
                $targetDefinition = $targetRegistry->registry->definition(
                    $inventoryArea->registryKey,
                );
                if (!$sourceDefinition instanceof TenantDataDefinition
                    || !$targetDefinition instanceof TenantDataDefinition
                    || CanonicalJson::encode($sourceDefinition->toArray())
                        !== CanonicalJson::encode($targetDefinition->toArray())
                ) {
                    throw self::error(
                        'file_restore_area_contract_mismatch',
                        $inventoryArea->registryKey,
                    );
                }
                $area = CompanyBackupFileAreaProjection::fromDefinition(
                    $sourceDefinition,
                    $sourceRegistry->registry,
                );
                $this->areas[$area->registryKey] = $area;
                foreach ($area->owners->owners as $owner) {
                    $target = $targetRegistry->registry->definition(
                        $owner->registryKey,
                    );
                    if (!$target instanceof TenantDataDefinition
                        || $target->kind !== TenantDataObjectKind::Table
                        || !in_array($target->policy, [
                            TenantDataPolicy::TenantRoot,
                            TenantDataPolicy::TenantOwned,
                            TenantDataPolicy::TenantOwnedIndirect,
                        ], true)
                    ) {
                        throw self::error(
                            'file_restore_owner_contract_invalid',
                            $area->registryKey,
                        );
                    }
                    $projection = CompanyBackupTableProjection::fromDefinition(
                        $target,
                    );
                    if (!in_array($owner->column, $projection->dataColumns, true)
                        || isset(
                            $projection->restoreOverrides->overrides[$owner->column],
                        )
                        || isset($ownerSignatures[$owner->signature()])
                    ) {
                        throw self::error(
                            'file_restore_owner_contract_invalid',
                            $area->registryKey,
                        );
                    }
                    $ownerSignatures[$owner->signature()] = true;
                    $this->ownersByRegistry[$owner->registryKey][] = [
                        'area' => $area,
                        'owner' => $owner,
                    ];
                }
                $this->fileEntries += count($inventoryArea->entries);
                if ($this->fileEntries > $this->limits->maxSourceIdentities) {
                    throw self::error(
                        'file_restore_entry_limit_exceeded',
                        $area->registryKey,
                    );
                }
            }
        } catch (CompanyBackupFileRestoreException $e) {
            throw $e;
        } catch (CompanyBackupFileSourceException|CompanyBackupDataSourceException $e) {
            throw self::error(
                'file_restore_registry_contract_invalid',
                $e->registryKey,
                $e,
            );
        }
    }

    private function indexInventory(): void
    {
        foreach ($this->inventory->areas as $inventoryArea) {
            $area = $this->areas[$inventoryArea->registryKey] ?? null;
            if (!$area instanceof CompanyBackupFileAreaProjection) {
                throw self::error(
                    'file_restore_area_contract_mismatch',
                    $inventoryArea->registryKey,
                );
            }
            foreach ($inventoryArea->entries as $entry) {
                foreach ($entry->owners as $owner) {
                    $payload = CanonicalJson::encode($owner);
                    $ownerId = hash('sha256', $payload);
                    $known = $this->lookup($ownerId, $area->registryKey);
                    if ($known !== null) {
                        throw self::error(
                            hash_equals($payload, $known['owner_payload'])
                                ? 'file_restore_owner_duplicate'
                                : 'file_restore_owner_hash_collision',
                            $area->registryKey,
                        );
                    }
                    if ($this->ownerEntries
                            >= $this->limits->maxReferenceOccurrences
                        || $this->ownerEntries
                            >= $this->limits->maxSourceIndexEntries
                    ) {
                        throw self::error(
                            'file_restore_owner_limit_exceeded',
                            $area->registryKey,
                        );
                    }
                    $additionalBytes = 64
                        + strlen($payload)
                        + strlen($area->registryKey)
                        + strlen($entry->sourcePath);
                    if ($additionalBytes
                        > $this->limits->maxSourceIndexBytes
                            - $this->indexedBytes
                    ) {
                        throw self::error(
                            'file_restore_owner_size_exceeded',
                            $area->registryKey,
                        );
                    }
                    $this->insert(
                        $ownerId,
                        $payload,
                        $area->registryKey,
                        $entry->sourcePath,
                    );
                    $this->ownerEntries++;
                    $this->indexedBytes += $additionalBytes;
                }
            }
        }
    }

    /**
     * @param array<string,mixed> $sourceRow
     * @param array<string,mixed> $targetRow
     */
    private function bindSupplier(
        CompanyBackupTableProjection $projection,
        array $sourceRow,
        array $targetRow,
    ): void {
        $sourceId = $sourceRow['id'] ?? null;
        $targetId = $targetRow['id'] ?? null;
        if ($projection->policy !== TenantDataPolicy::TenantRoot
            || $projection->primaryKey !== ['id']
            || !is_int($sourceId)
            || $sourceId < 1
            || !is_int($targetId)
            || $targetId < 1
        ) {
            throw self::error(
                'file_restore_supplier_identity_invalid',
                $projection->registryKey,
            );
        }
        if ($this->sourceSupplierId !== null
            || $this->targetSupplierId !== null
        ) {
            if ($this->sourceSupplierId !== $sourceId
                || $this->targetSupplierId !== $targetId
            ) {
                throw self::error(
                    'file_restore_supplier_identity_conflict',
                    $projection->registryKey,
                );
            }
            return;
        }

        $publicationPlan = CompanyBackupFilePublicationPlan::fromInventory(
            $this->inventory,
            $this->targetRegistry,
            $sourceId,
            $targetId,
        );
        $this->sourceSupplierId = $sourceId;
        $this->targetSupplierId = $targetId;
        $this->publicationPlan = $publicationPlan;
    }

    /**
     * @param array<string,mixed> $sourceRow
     */
    private function ownerPayload(
        CompanyBackupTableProjection $projection,
        array $sourceRow,
        CompanyBackupFileOwnerDefinition $owner,
    ): string {
        $primaryKey = [];
        foreach ($projection->primaryKey as $column) {
            if (!array_key_exists($column, $sourceRow)
                || !self::keyValue($sourceRow[$column])
            ) {
                throw self::error(
                    'file_restore_owner_identity_invalid',
                    $projection->registryKey,
                );
            }
            $primaryKey[$column] = $sourceRow[$column];
        }
        ksort($primaryKey, SORT_STRING);
        return CanonicalJson::encode([
            'registry_key' => $projection->registryKey,
            'primary_key' => $primaryKey,
            'column' => $owner->column,
            'path' => $owner->path,
        ]);
    }

    /**
     * @param array<string,mixed> $row
     */
    private function storedPath(
        array $row,
        CompanyBackupFileOwnerDefinition $owner,
        string $areaRegistryKey,
    ): ?string {
        if (!array_key_exists($owner->column, $row)) {
            throw self::error(
                'file_restore_owner_column_missing',
                $areaRegistryKey,
            );
        }
        if ($owner->path === []) {
            return $this->optionalStoredPath(
                $row[$owner->column],
                $areaRegistryKey,
            );
        }
        $document = $this->document(
            $row[$owner->column],
            $areaRegistryKey,
        );
        if ($document === null) {
            return null;
        }
        $value = $document;
        foreach ($owner->path as $segment) {
            if (!is_array($value)) {
                throw self::error(
                    'file_restore_owner_document_invalid',
                    $areaRegistryKey,
                );
            }
            if (!array_key_exists($segment, $value)) {
                return null;
            }
            $value = $value[$segment];
        }
        return $this->optionalStoredPath($value, $areaRegistryKey);
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function replaceStoredPath(
        array $row,
        CompanyBackupFileOwnerDefinition $owner,
        string $expected,
        string $replacement,
        string $areaRegistryKey,
    ): array {
        if ($owner->path === []) {
            if (($row[$owner->column] ?? null) !== $expected) {
                throw self::error(
                    'file_restore_owner_path_mismatch',
                    $areaRegistryKey,
                );
            }
            $row[$owner->column] = $replacement;
            return $row;
        }

        $raw = $row[$owner->column] ?? null;
        $encoded = is_string($raw);
        $document = $this->document($raw, $areaRegistryKey);
        if (!is_array($document)) {
            throw self::error(
                'file_restore_owner_document_invalid',
                $areaRegistryKey,
            );
        }
        $value =& $document;
        foreach ($owner->path as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                throw self::error(
                    'file_restore_owner_path_mismatch',
                    $areaRegistryKey,
                );
            }
            $value =& $value[$segment];
        }
        if ($value !== $expected) {
            throw self::error(
                'file_restore_owner_path_mismatch',
                $areaRegistryKey,
            );
        }
        $value = $replacement;
        unset($value);
        $row[$owner->column] = $encoded
            ? CanonicalJson::encode($document)
            : $document;
        return $row;
    }

    /** @return array<mixed>|null */
    private function document(
        mixed $raw,
        string $areaRegistryKey,
    ): ?array {
        if ($raw === null) {
            return null;
        }
        if (is_array($raw)) {
            return $raw;
        }
        if (!is_string($raw)) {
            throw self::error(
                'file_restore_owner_document_invalid',
                $areaRegistryKey,
            );
        }
        try {
            $decoded = json_decode($raw, true, 128, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw self::error(
                'file_restore_owner_document_invalid',
                $areaRegistryKey,
                $e,
            );
        }
        if (!is_array($decoded)) {
            throw self::error(
                'file_restore_owner_document_invalid',
                $areaRegistryKey,
            );
        }
        return $decoded;
    }

    private function optionalStoredPath(
        mixed $value,
        string $areaRegistryKey,
    ): ?string {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value)) {
            throw self::error(
                'file_restore_owner_path_invalid',
                $areaRegistryKey,
            );
        }
        return $value;
    }

    /**
     * @return array{
     *   owner_payload:string,
     *   area_registry_key:string,
     *   source_path:string,
     *   consumed:bool
     * }|null
     */
    private function lookup(
        string $ownerId,
        string $areaRegistryKey,
    ): ?array {
        $statement = $this->select;
        if (!$statement instanceof PDOStatement) {
            throw self::error('file_restore_map_closed', $areaRegistryKey);
        }
        try {
            if (!$statement->execute([$ownerId])) {
                throw new \RuntimeException(
                    'Čtení mapy souborových cest selhalo.',
                );
            }
            $row = $statement->fetch(PDO::FETCH_ASSOC);
            if (!$statement->closeCursor()) {
                throw new \RuntimeException(
                    'Čtení mapy souborových cest nelze uzavřít.',
                );
            }
        } catch (\Throwable $e) {
            try {
                $statement->closeCursor();
            } catch (\Throwable) {
                // Primární fail-closed chyba lookupu má přednost před úklidem.
            }
            throw self::error(
                'file_restore_map_read_failed',
                $areaRegistryKey,
                $e,
            );
        }
        if ($row === false) {
            return null;
        }
        if (!is_array($row)
            || array_is_list($row)
            || array_keys($row) !== [
                'owner_payload',
                'area_registry_key',
                'source_path',
                'consumed',
            ]
            || !is_string($row['owner_payload'])
            || !is_string($row['area_registry_key'])
            || !is_string($row['source_path'])
            || !in_array($row['consumed'], [0, 1, '0', '1'], true)
        ) {
            throw self::error(
                'file_restore_map_corrupted',
                $areaRegistryKey,
            );
        }
        try {
            $sourcePath = CompanyBackupFileEntry::normalizeSourcePath(
                $row['source_path'],
            );
        } catch (\InvalidArgumentException $e) {
            throw self::error(
                'file_restore_map_corrupted',
                $areaRegistryKey,
                $e,
            );
        }
        return [
            'owner_payload' => $row['owner_payload'],
            'area_registry_key' => $row['area_registry_key'],
            'source_path' => $sourcePath,
            'consumed' => (int) $row['consumed'] === 1,
        ];
    }

    private function insert(
        string $ownerId,
        string $payload,
        string $areaRegistryKey,
        string $sourcePath,
    ): void {
        $statement = $this->insert;
        if (!$statement instanceof PDOStatement) {
            throw self::error('file_restore_map_closed', $areaRegistryKey);
        }
        try {
            if (!$statement->execute([
                $ownerId,
                $payload,
                $areaRegistryKey,
                $sourcePath,
            ]) || $statement->rowCount() !== 1) {
                throw new \RuntimeException(
                    'Mapování souborové cesty nelze zapsat.',
                );
            }
            if (!$statement->closeCursor()) {
                throw new \RuntimeException(
                    'Zápis mapy souborových cest nelze uzavřít.',
                );
            }
        } catch (\Throwable $e) {
            try {
                $statement->closeCursor();
            } catch (\Throwable) {
                // Primární fail-closed chyba zápisu má přednost před úklidem.
            }
            throw self::error(
                'file_restore_map_write_failed',
                $areaRegistryKey,
                $e,
            );
        }
    }

    private function markConsumed(
        string $ownerId,
        string $areaRegistryKey,
    ): void {
        $statement = $this->consume;
        if (!$statement instanceof PDOStatement) {
            throw self::error('file_restore_map_closed', $areaRegistryKey);
        }
        try {
            if (!$statement->execute([$ownerId])
                || $statement->rowCount() !== 1
                || !$statement->closeCursor()
            ) {
                throw new \RuntimeException(
                    'Spotřebu souborového vlastníka nelze zapsat.',
                );
            }
        } catch (\Throwable $e) {
            try {
                $statement->closeCursor();
            } catch (\Throwable) {
                // Primární fail-closed chyba zápisu má přednost před úklidem.
            }
            throw self::error(
                'file_restore_map_write_failed',
                $areaRegistryKey,
                $e,
            );
        }
    }

    private function assertOpen(
        string $registryKey = 'file-area:inventory',
    ): void {
        if ($this->closed) {
            throw self::error('file_restore_map_closed', $registryKey);
        }
    }

    private function drop(): void
    {
        if ($this->database->exec(
            'DROP ' . ($this->mysql ? 'TEMPORARY ' : '')
                . 'TABLE IF EXISTS ' . $this->quotedTable,
        ) === false) {
            throw new \RuntimeException(
                'Dočasnou mapu souborových cest nelze odstranit.',
            );
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

    private static function keyValue(mixed $value): bool
    {
        return is_int($value) && $value >= 0
            || is_string($value)
                && $value !== ''
                && strlen($value) <= 255
                && preg_match('//u', $value) === 1
                && !str_contains($value, "\0");
    }

    private static function error(
        string $errorCode,
        string $registryKey = 'file-area:inventory',
        ?\Throwable $previous = null,
    ): CompanyBackupFileRestoreException {
        return new CompanyBackupFileRestoreException(
            $errorCode,
            $registryKey,
            $previous,
        );
    }
}
