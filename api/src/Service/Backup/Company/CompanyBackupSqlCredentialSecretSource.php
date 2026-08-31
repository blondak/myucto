<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Backup\Registry\TenantSecretPolicy;
use PDO;
use PDOStatement;

/** Tenantově omezené čtení firemního PFX a souvisejícího credential řádku. */
final readonly class CompanyBackupSqlCredentialSecretSource implements
    CompanyBackupCredentialSecretSource
{
    private const CREDENTIAL_ALIAS = '_company_credential';
    private const PROFILE_ALIAS = '_company_profile';
    private const OWNER_ALIAS = '_company_owner_user_id';

    public function __construct(
        private SecretEncryption $encryption,
        private CompanyBackupFileAreaRootResolver $roots =
            new CompanyBackupRuntimeFileAreaRootResolver(),
        private CompanyBackupTableSchemaReader $schemaReader =
            new CompanyBackupTableSchemaReader(),
    ) {}

    /**
     * @param array<mixed> $entries
     * @return iterable<CompanyBackupSecretValue>
     */
    public function values(
        PDO $snapshot,
        int $supplierId,
        CompanyBackupCredentialTableProjection $projection,
        array $entries,
    ): iterable {
        if ($supplierId < 1 || $entries === []) {
            throw new \InvalidArgumentException(
                'Zdroj credentialů vyžaduje firmu a explicitní výběr.',
            );
        }
        $projection->assertRuntimeSchema(
            $this->schemaReader->readCredential($snapshot, $projection),
            $this->schemaReader->readCredentialReferences($snapshot, $projection),
        );
        $this->assertEntries($entries, $projection);

        $plaintextBytes = 0;
        foreach ($entries as $entry) {
            $row = $this->fetchSelectedRow(
                $snapshot,
                $supplierId,
                $projection,
                $entry,
            );
            $attachments = [];
            $plaintextSecrets = [];
            try {
                $ownerUserId = $this->nullablePositiveInt(
                    $row[self::OWNER_ALIAS],
                    $projection,
                    $projection->ownership['owner_column'],
                );
                unset($row[self::OWNER_ALIAS]);
                $fileColumn = $projection->sourceColumns['file'];
                $vaultColumn = $projection->sourceColumns['vault'];
                $variant = $projection->variantFor(
                    $ownerUserId,
                    $this->nullableString($row[$fileColumn], $projection, $fileColumn),
                    $this->nullablePositiveInt(
                        $row[$vaultColumn],
                        $projection,
                        $vaultColumn,
                    ),
                );
                if ($variant['policy']
                    === TenantSecretPolicy::PersonalWithDualConsent
                ) {
                    throw self::error(
                        'secret_selection_consent_required',
                        $projection,
                    );
                }
                if ($variant['name'] !== $entry->name
                    || $variant['owner'] !== 'company'
                    || $variant['source'] !== 'file'
                ) {
                    throw self::error(
                        'secret_selection_scope_mismatch',
                        $projection,
                    );
                }

                foreach ($projection->attachmentSources as $column => $source) {
                    $storedPath = $this->nullableString(
                        $row[$column],
                        $projection,
                        $column,
                    );
                    $expectedPath = $projection->attachmentPath(
                        $column,
                        $supplierId,
                        $row,
                    );
                    if ($storedPath === null
                        || !hash_equals($expectedPath, $storedPath)
                    ) {
                        throw self::error(
                            'credential_attachment_path_mismatch',
                            $projection,
                            $column,
                        );
                    }
                    $attachments[$column] = $this->readAttachment(
                        $projection,
                        $column,
                        $projection->attachmentRelativePath($column, $storedPath),
                        $source,
                    );
                }
                foreach ($projection->secretStorage as $column => $storage) {
                    $stored = $row[$column] ?? null;
                    if ($stored === null) {
                        $plaintextSecrets[$column] = null;
                        continue;
                    }
                    if (!is_string($stored) || $stored === '') {
                        throw self::error(
                            'credential_secret_source_invalid',
                            $projection,
                            $column,
                        );
                    }
                    try {
                        $plaintextSecrets[$column] = $storage->decode(
                            $stored,
                            null,
                            $this->encryption,
                        );
                    } catch (\Throwable $e) {
                        throw self::error(
                            'credential_secret_decrypt_failed',
                            $projection,
                            $column,
                            $e,
                        );
                    }
                }

                $bundle = CompanyBackupCredentialSecretBundle::fromExportRow(
                    $projection,
                    $entry->name,
                    $ownerUserId,
                    $row,
                    $attachments,
                    $plaintextSecrets,
                );
                $plaintext = $bundle->toJson();
                $bytes = strlen($plaintext);
                if ($bytes > CompanyBackupSecretEnvelopeDescriptor::MAX_PLAINTEXT_BYTES
                        - $plaintextBytes
                ) {
                    self::wipe($plaintext);
                    throw self::error(
                        'secret_source_size_exceeded',
                        $projection,
                    );
                }
                $plaintextBytes += $bytes;
                try {
                    yield CompanyBackupSecretValue::fromPlaintext(
                        $projection->registryKey,
                        CompanyBackupSecretScope::CredentialVariant,
                        $entry->name,
                        $entry->primaryKey,
                        $plaintext,
                    );
                } finally {
                    self::wipe($plaintext);
                }
            } catch (CompanyBackupDataSourceException $e) {
                throw $e;
            } catch (CompanyBackupSecretPayloadException $e) {
                throw self::error(
                    'credential_source_value_invalid',
                    $projection,
                    previous: $e,
                );
            } catch (\Throwable $e) {
                throw self::error(
                    'credential_source_value_invalid',
                    $projection,
                    previous: $e,
                );
            } finally {
                foreach ($attachments as &$attachment) {
                    self::wipe($attachment);
                }
                unset($attachment);
                foreach ($plaintextSecrets as &$secret) {
                    if (is_string($secret)) {
                        self::wipe($secret);
                    }
                }
                unset($secret);
                foreach ($projection->secretStorage as $column => $_storage) {
                    if (isset($row[$column]) && is_string($row[$column])) {
                        self::wipe($row[$column]);
                    }
                }
            }
        }
    }

    /** @param array<mixed> $entries */
    private function assertEntries(
        array $entries,
        CompanyBackupCredentialTableProjection $projection,
    ): void {
        $seen = [];
        $expectedPrimaryKey = $projection->primaryKey;
        sort($expectedPrimaryKey, SORT_STRING);
        foreach ($entries as $entry) {
            if (!$entry instanceof CompanyBackupSecretSelectionEntry
                || $entry->registryKey !== $projection->registryKey
                || $entry->scope !== CompanyBackupSecretScope::CredentialVariant
                || $entry->policy !== TenantSecretPolicy::OptionalCredential
                || array_keys($entry->primaryKey) !== $expectedPrimaryKey
            ) {
                throw self::error(
                    'secret_selection_scope_mismatch',
                    $projection,
                );
            }
            $variant = $projection->variantNamed($entry->name);
            if ($variant['owner'] !== 'company'
                || $variant['source'] !== 'file'
                || $variant['policy'] !== $entry->policy
            ) {
                throw self::error(
                    $variant['owner'] === 'personal'
                        ? 'secret_selection_consent_required'
                        : 'secret_selection_scope_mismatch',
                    $projection,
                );
            }
            if (isset($seen[$entry->valueSignature()])) {
                throw self::error('secret_selection_duplicate', $projection);
            }
            $seen[$entry->valueSignature()] = true;
        }
    }

    /** @return array<string,mixed> */
    private function fetchSelectedRow(
        PDO $snapshot,
        int $supplierId,
        CompanyBackupCredentialTableProjection $projection,
        CompanyBackupSecretSelectionEntry $entry,
    ): array {
        $credentialAlias = self::quoted(self::CREDENTIAL_ALIAS, $projection);
        $profileAlias = self::quoted(self::PROFILE_ALIAS, $projection);
        $columns = implode(', ', array_map(
            static fn (string $column): string => $credentialAlias . '.`'
                . $column . '`',
            $projection->columns,
        ));
        $ownerColumn = $projection->ownership['owner_column'];
        $profileColumn = $projection->ownership['profile_column'];
        $profileTable = self::quoted(
            $projection->ownership['profile_table'],
            $projection,
        );
        $supplierColumn = $projection->ownership['supplier_column'];
        $conditions = [$profileAlias . '.`' . $supplierColumn . '` = ?'];
        $params = [$supplierId];
        foreach ($projection->primaryKey as $column) {
            $conditions[] = $credentialAlias . '.`' . $column . '` = ?';
            $params[] = $entry->primaryKey[$column];
        }
        $table = self::quoted($projection->name, $projection);
        $sql = 'SELECT ' . $columns . ', ' . $profileAlias . '.`'
            . $ownerColumn . '` AS `' . self::OWNER_ALIAS . '`'
            . ' FROM ' . $table . ' AS ' . $credentialAlias
            . ' JOIN ' . $profileTable . ' AS ' . $profileAlias
            . ' ON ' . $profileAlias . '.`id` = ' . $credentialAlias
            . '.`' . $profileColumn . '`'
            . ' WHERE ' . implode(' AND ', $conditions) . ' LIMIT 2';
        $rows = $this->fetchRows(
            $snapshot,
            $sql,
            $params,
            $projection,
        );
        if (count($rows) !== 1) {
            throw self::error(
                count($rows) === 0
                    ? 'secret_selected_value_missing'
                    : 'credential_source_row_invalid',
                $projection,
            );
        }
        $row = $rows[0];
        if (array_keys($row) !== [...$projection->columns, self::OWNER_ALIAS]) {
            throw self::error('credential_source_row_invalid', $projection);
        }
        foreach ($projection->primaryKey as $column) {
            if ($row[$column] !== $entry->primaryKey[$column]) {
                throw self::error(
                    'credential_source_row_invalid',
                    $projection,
                    $column,
                );
            }
        }
        return $row;
    }

    /**
     * @param array{max_bytes:int,path_template:string,storage_subdirectory:string} $source
     */
    private function readAttachment(
        CompanyBackupCredentialTableProjection $projection,
        string $column,
        string $relativePath,
        array $source,
    ): string {
        $configured = $this->roots->resolve($source['storage_subdirectory']);
        if ($configured === '' || str_contains($configured, "\0")) {
            throw self::error(
                'credential_attachment_root_invalid',
                $projection,
                $column,
            );
        }
        clearstatcache(true, $configured);
        $root = @realpath($configured);
        if (!is_string($root)
            || !is_dir($root)
            || !is_readable($root)
        ) {
            throw self::error(
                'credential_attachment_root_invalid',
                $projection,
                $column,
            );
        }
        $root = rtrim($root, '/\\');
        $candidate = $root;
        $segments = explode('/', $relativePath);
        foreach ($segments as $index => $segment) {
            $candidate .= DIRECTORY_SEPARATOR . $segment;
            clearstatcache(true, $candidate);
            if (is_link($candidate)) {
                throw self::error(
                    'credential_attachment_path_unsafe',
                    $projection,
                    $column,
                );
            }
            if (!file_exists($candidate)) {
                throw self::error(
                    'credential_attachment_missing',
                    $projection,
                    $column,
                );
            }
            if ($index < count($segments) - 1 && !is_dir($candidate)) {
                throw self::error(
                    'credential_attachment_path_unsafe',
                    $projection,
                    $column,
                );
            }
        }
        $resolved = @realpath($candidate);
        if (!is_string($resolved)
            || !str_starts_with(
                strtolower($resolved),
                strtolower($root . DIRECTORY_SEPARATOR),
            )
            || !is_file($resolved)
            || !is_readable($resolved)
        ) {
            throw self::error(
                'credential_attachment_path_unsafe',
                $projection,
                $column,
            );
        }

        clearstatcache(true, $resolved);
        $before = @stat($resolved);
        if ($before === false
            || $before['size'] < 1
            || $before['size'] > $source['max_bytes']
        ) {
            throw self::error(
                $before === false
                    ? 'credential_attachment_unreadable'
                    : 'credential_attachment_size_invalid',
                $projection,
                $column,
            );
        }
        $contents = @file_get_contents($resolved);
        clearstatcache(true, $resolved);
        $after = @stat($resolved);
        $afterResolved = @realpath($candidate);
        if (!is_string($contents)
            || $after === false
            || is_link($candidate)
            || !is_string($afterResolved)
            || strtolower($afterResolved) !== strtolower($resolved)
            || !self::segmentsAreSafe($root, $segments)
            || !self::sameFile($before, $after)
            || strlen($contents) !== $after['size']
        ) {
            if (is_string($contents)) {
                self::wipe($contents);
            }
            throw self::error(
                'credential_attachment_changed',
                $projection,
                $column,
            );
        }
        return $contents;
    }

    /** @param list<string> $segments */
    private static function segmentsAreSafe(string $root, array $segments): bool
    {
        $candidate = $root;
        foreach ($segments as $index => $segment) {
            $candidate .= DIRECTORY_SEPARATOR . $segment;
            clearstatcache(true, $candidate);
            if (is_link($candidate)
                || !file_exists($candidate)
                || $index < count($segments) - 1 && !is_dir($candidate)
            ) {
                return false;
            }
        }
        return true;
    }

    /**
     * @param list<mixed> $params
     * @return list<array<string,mixed>>
     */
    private function fetchRows(
        PDO $snapshot,
        string $sql,
        array $params,
        CompanyBackupCredentialTableProjection $projection,
    ): array {
        $statement = null;
        try {
            $prepared = $snapshot->prepare($sql);
            if (!$prepared instanceof PDOStatement) {
                throw self::error('credential_source_query_failed', $projection);
            }
            $statement = $prepared;
            if (!$statement->execute($params)) {
                throw self::error('credential_source_query_failed', $projection);
            }
            $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
            if (!array_is_list($rows)) {
                throw self::error('credential_source_query_failed', $projection);
            }
            $result = [];
            foreach ($rows as $row) {
                if (!is_array($row) || array_is_list($row)) {
                    throw self::error('credential_source_row_invalid', $projection);
                }
                $result[] = $row;
            }
            if (!$statement->closeCursor()) {
                throw self::error('credential_source_query_failed', $projection);
            }
            $statement = null;
            return $result;
        } catch (CompanyBackupDataSourceException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw self::error(
                'credential_source_query_failed',
                $projection,
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

    private function nullablePositiveInt(
        mixed $value,
        CompanyBackupCredentialTableProjection $projection,
        string $column,
    ): ?int {
        if ($value === null) {
            return null;
        }
        if (is_int($value) && $value > 0) {
            return $value;
        }
        if (is_string($value)
            && preg_match('/^[1-9][0-9]*$/D', $value) === 1
            && strlen($value) <= strlen((string) PHP_INT_MAX)
            && (strlen($value) < strlen((string) PHP_INT_MAX)
                || strcmp($value, (string) PHP_INT_MAX) <= 0)
        ) {
            return (int) $value;
        }
        throw self::error('credential_source_row_invalid', $projection, $column);
    }

    private function nullableString(
        mixed $value,
        CompanyBackupCredentialTableProjection $projection,
        string $column,
    ): ?string {
        if ($value === null || is_string($value) && $value !== '') {
            return $value;
        }
        throw self::error('credential_source_row_invalid', $projection, $column);
    }

    private static function quoted(
        string $identifier,
        CompanyBackupCredentialTableProjection $projection,
    ): string {
        return CompanyBackupTenantSqlSelector::quoteIdentifier(
            $identifier,
            $projection->registryKey,
        );
    }

    /**
     * @param array<int|string,int> $before
     * @param array<int|string,int> $after
     */
    private static function sameFile(array $before, array $after): bool
    {
        foreach (['dev', 'ino', 'size', 'mtime', 'ctime'] as $field) {
            if (($before[$field] ?? null) !== ($after[$field] ?? null)) {
                return false;
            }
        }
        return true;
    }

    private static function wipe(string &$value): void
    {
        $sensitive = $value;
        $value = '';
        if ($sensitive !== '' && function_exists('sodium_memzero')) {
            sodium_memzero($sensitive);
        }
    }

    private static function error(
        string $code,
        CompanyBackupCredentialTableProjection $projection,
        ?string $column = null,
        ?\Throwable $previous = null,
    ): CompanyBackupDataSourceException {
        return new CompanyBackupDataSourceException(
            $code,
            $projection->registryKey,
            $column,
            $previous,
        );
    }
}
