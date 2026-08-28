<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

use MyInvoice\Service\Backup\Registry\TenantDataDefinition;
use MyInvoice\Service\Backup\Registry\TenantDataPolicy;
use MyInvoice\Service\Backup\Registry\TenantDataRegistry;
use PDO;
use PDOStatement;

/** Registry-driven SQL počítání hodnot, které výchozí záloha bezpečně vynechá. */
final readonly class CompanyBackupSqlSecretOmissionCountSource implements
    CompanyBackupSecretOmissionCountSource
{
    public function __construct(
        private CompanyBackupTenantSqlSelector $selector =
            new CompanyBackupTenantSqlSelector(),
    ) {}

    /**
     * @param list<CompanyBackupSecretDeclaration> $declarations
     * @return array<string,int>
     */
    public function counts(
        PDO $snapshot,
        int $supplierId,
        TenantDataDefinition $definition,
        array $declarations,
        TenantDataRegistry $registry,
    ): array {
        if ($supplierId < 1 || $declarations === []) {
            throw new \InvalidArgumentException(
                'SQL počítání secrets vyžaduje firmu a deklarace.',
            );
        }
        if ($definition->policy->hasMachineDataPayload()) {
            return $this->payloadCounts(
                $snapshot,
                $supplierId,
                $definition,
                $declarations,
            );
        }
        if ($definition->policy === TenantDataPolicy::OptionalCredential) {
            return $this->signingCredentialCounts(
                $snapshot,
                $supplierId,
                $definition,
                $declarations,
            );
        }
        if ($definition->policy === TenantDataPolicy::PersonalSecretAttachment) {
            return $this->personalCredentialCounts(
                $snapshot,
                $supplierId,
                $definition,
                $declarations,
                $registry,
            );
        }
        throw new CompanyBackupDataSourceException(
            'secret_count_object_unsupported',
            $definition->key,
        );
    }

    /**
     * @param list<CompanyBackupSecretDeclaration> $declarations
     * @return array<string,int>
     */
    private function payloadCounts(
        PDO $snapshot,
        int $supplierId,
        TenantDataDefinition $definition,
        array $declarations,
    ): array {
        $projection = CompanyBackupTableProjection::fromDefinition($definition);
        $selection = $this->selector->select($projection, $supplierId);
        $expressions = [];
        foreach ($declarations as $index => $declaration) {
            if ($declaration->scope !== CompanyBackupSecretScope::Column
                || ($projection->secretPolicies[$declaration->name] ?? null)
                    !== $declaration->policy
            ) {
                throw new CompanyBackupDataSourceException(
                    'secret_count_declaration_mismatch',
                    $definition->key,
                    $declaration->name,
                );
            }
            $column = CompanyBackupTenantSqlSelector::quoteIdentifier(
                $declaration->name,
                $definition->key,
            );
            $expressions[] = 'COUNT(CASE WHEN `'
                . CompanyBackupTenantSqlSelector::SOURCE_ALIAS . '`.' . $column
                . ' IS NOT NULL AND OCTET_LENGTH(`'
                . CompanyBackupTenantSqlSelector::SOURCE_ALIAS . '`.' . $column
                . ') > 0 THEN 1 END) AS `_secret_count_' . $index . '`';
        }
        $table = CompanyBackupTenantSqlSelector::quoteIdentifier(
            $projection->name,
            $definition->key,
        );
        $alias = CompanyBackupTenantSqlSelector::quoteIdentifier(
            CompanyBackupTenantSqlSelector::SOURCE_ALIAS,
            $definition->key,
        );
        $row = $this->fetchOne(
            $snapshot,
            'SELECT ' . implode(', ', $expressions)
                . ' FROM ' . $table . ' AS ' . $alias
                . ' WHERE ' . $selection->where,
            $selection->params,
            $definition->key,
        );
        return $this->aggregateCounts($row, $declarations, $definition->key);
    }

    /**
     * @param list<CompanyBackupSecretDeclaration> $declarations
     * @return array<string,int>
     */
    private function signingCredentialCounts(
        PDO $snapshot,
        int $supplierId,
        TenantDataDefinition $definition,
        array $declarations,
    ): array {
        $projection = CompanyBackupCredentialTableProjection::fromDefinition(
            $definition,
        );
        $byVariant = [];
        $counts = [];
        foreach ($declarations as $declaration) {
            if ($declaration->scope !== CompanyBackupSecretScope::CredentialVariant) {
                throw new CompanyBackupDataSourceException(
                    'secret_count_declaration_mismatch',
                    $definition->key,
                );
            }
            $byVariant[$declaration->name] = $declaration;
            $counts[$declaration->signature()] = 0;
        }
        if (array_keys($byVariant) !== array_column($projection->variants, 'name')) {
            throw new CompanyBackupDataSourceException(
                'secret_count_declaration_mismatch',
                $definition->key,
            );
        }

        $credentialAlias = '_credential';
        $profileAlias = '_profile';
        $ownerColumn = $projection->ownership['owner_column'];
        $profileColumn = $projection->ownership['profile_column'];
        $profileTable = $projection->ownership['profile_table'];
        $supplierColumn = $projection->ownership['supplier_column'];
        $fileColumn = $projection->sourceColumns['file'];
        $vaultColumn = $projection->sourceColumns['vault'];
        $rows = $this->fetchAll(
            $snapshot,
            'SELECT `' . $profileAlias . '`.`' . $ownerColumn . '` AS `owner_user_id`,'
                . ' `' . $credentialAlias . '`.`' . $fileColumn
                . '` AS `certificate_path`,'
                . ' `' . $credentialAlias . '`.`' . $vaultColumn
                . '` AS `vault_credential_id`'
                . ' FROM `' . $projection->name . '` AS `' . $credentialAlias . '`'
                . ' JOIN `' . $profileTable . '` AS `' . $profileAlias . '`'
                . ' ON `' . $profileAlias . '`.`id` = `'
                . $credentialAlias . '`.`' . $profileColumn . '`'
                . ' WHERE `' . $profileAlias . '`.`' . $supplierColumn . '` = ?'
                . ' ORDER BY `' . $credentialAlias . '`.`'
                . $projection->primaryKey[0] . '`',
            [$supplierId],
            $definition->key,
        );
        foreach ($rows as $row) {
            if (array_keys($row) !== [
                'owner_user_id',
                'certificate_path',
                'vault_credential_id',
            ]) {
                throw new CompanyBackupDataSourceException(
                    'secret_count_row_invalid',
                    $definition->key,
                );
            }
            $variant = $projection->variantFor(
                $this->nullablePositiveInt(
                    $row['owner_user_id'],
                    $definition->key,
                    $ownerColumn,
                ),
                $this->nullableString(
                    $row['certificate_path'],
                    $definition->key,
                    $fileColumn,
                ),
                $this->nullablePositiveInt(
                    $row['vault_credential_id'],
                    $definition->key,
                    $vaultColumn,
                ),
            );
            $declaration = $byVariant[$variant['name']] ?? null;
            if ($declaration === null || $declaration->policy !== $variant['policy']) {
                throw new CompanyBackupDataSourceException(
                    'secret_count_declaration_mismatch',
                    $definition->key,
                );
            }
            $signature = $declaration->signature();
            if ($counts[$signature] === PHP_INT_MAX) {
                throw new CompanyBackupDataSourceException(
                    'secret_count_overflow',
                    $definition->key,
                );
            }
            $counts[$signature]++;
        }
        return $counts;
    }

    /**
     * @param list<CompanyBackupSecretDeclaration> $declarations
     * @return array<string,int>
     */
    private function personalCredentialCounts(
        PDO $snapshot,
        int $supplierId,
        TenantDataDefinition $definition,
        array $declarations,
        TenantDataRegistry $registry,
    ): array {
        $ownership = $definition->details['ownership'] ?? null;
        $relation = $registry->definition('table:epo_signing_credential_suppliers');
        if ($definition->key !== 'table:epo_signing_credentials'
            || $ownership !== [
                'owner_column' => 'owner_user_id',
                'strategy' => 'personal_credential_owner',
            ]
            || $relation === null
            || $relation->policy !== TenantDataPolicy::TenantRelation
            || ($relation->details['ownership'] ?? null) !== [
                'actor_column' => 'enabled_by',
                'credential_column' => 'credential_id',
                'strategy' => 'credential_consent_relation',
                'supplier_column' => 'supplier_id',
            ]
        ) {
            throw new CompanyBackupDataSourceException(
                'secret_count_personal_scope_invalid',
                $definition->key,
            );
        }
        $policies = CompanyBackupSecretColumnSet::fromArray(
            $definition->details['secrets'] ?? null,
            $definition->key,
        )->policies;
        $expressions = [];
        foreach ($declarations as $index => $declaration) {
            if ($declaration->scope !== CompanyBackupSecretScope::Column
                || ($policies[$declaration->name] ?? null) !== $declaration->policy
            ) {
                throw new CompanyBackupDataSourceException(
                    'secret_count_declaration_mismatch',
                    $definition->key,
                    $declaration->name,
                );
            }
            $column = CompanyBackupTenantSqlSelector::quoteIdentifier(
                $declaration->name,
                $definition->key,
            );
            $expressions[] = 'COUNT(CASE WHEN `_personal_credential`.' . $column
                . ' IS NOT NULL AND OCTET_LENGTH(`_personal_credential`.' . $column
                . ') > 0 THEN 1 END) AS `_secret_count_' . $index . '`';
        }
        $row = $this->fetchOne(
            $snapshot,
            'SELECT ' . implode(', ', $expressions)
                . ' FROM `epo_signing_credentials` AS `_personal_credential`'
                . ' WHERE EXISTS (SELECT 1'
                . ' FROM `epo_signing_credential_suppliers` AS `_credential_scope`'
                . ' WHERE `_credential_scope`.`credential_id`'
                . ' = `_personal_credential`.`id`'
                . ' AND `_credential_scope`.`supplier_id` = ?)',
            [$supplierId],
            $definition->key,
        );
        return $this->aggregateCounts($row, $declarations, $definition->key);
    }

    /**
     * @param array<string,mixed> $row
     * @param list<CompanyBackupSecretDeclaration> $declarations
     * @return array<string,int>
     */
    private function aggregateCounts(
        array $row,
        array $declarations,
        string $registryKey,
    ): array {
        $expectedKeys = [];
        $counts = [];
        foreach ($declarations as $index => $declaration) {
            $key = '_secret_count_' . $index;
            $expectedKeys[] = $key;
            $counts[$declaration->signature()] = $this->countValue(
                $row[$key] ?? null,
                $registryKey,
            );
        }
        if (array_keys($row) !== $expectedKeys) {
            throw new CompanyBackupDataSourceException(
                'secret_count_row_invalid',
                $registryKey,
            );
        }
        return $counts;
    }

    /**
     * @param list<mixed> $params
     * @return array<string,mixed>
     */
    private function fetchOne(
        PDO $snapshot,
        string $sql,
        array $params,
        string $registryKey,
    ): array {
        $rows = $this->fetchAll($snapshot, $sql, $params, $registryKey);
        if (count($rows) !== 1) {
            throw new CompanyBackupDataSourceException(
                'secret_count_row_invalid',
                $registryKey,
            );
        }
        return $rows[0];
    }

    /**
     * @param list<mixed> $params
     * @return list<array<string,mixed>>
     */
    private function fetchAll(
        PDO $snapshot,
        string $sql,
        array $params,
        string $registryKey,
    ): array {
        $statement = null;
        try {
            $prepared = $snapshot->prepare($sql);
            if (!$prepared instanceof PDOStatement) {
                throw new CompanyBackupDataSourceException(
                    'secret_count_query_failed',
                    $registryKey,
                );
            }
            $statement = $prepared;
            if (!$statement->execute($params)) {
                throw new CompanyBackupDataSourceException(
                    'secret_count_query_failed',
                    $registryKey,
                );
            }
            $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
            if (!array_is_list($rows)) {
                throw new CompanyBackupDataSourceException(
                    'secret_count_query_failed',
                    $registryKey,
                );
            }
            $result = [];
            foreach ($rows as $row) {
                if (!is_array($row) || array_is_list($row)) {
                    throw new CompanyBackupDataSourceException(
                        'secret_count_row_invalid',
                        $registryKey,
                    );
                }
                $result[] = $row;
            }
            if (!$statement->closeCursor()) {
                throw new CompanyBackupDataSourceException(
                    'secret_count_query_failed',
                    $registryKey,
                );
            }
            $statement = null;
            return $result;
        } catch (CompanyBackupDataSourceException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new CompanyBackupDataSourceException(
                'secret_count_query_failed',
                $registryKey,
                previous: $e,
            );
        } finally {
            if ($statement instanceof PDOStatement) {
                try {
                    $statement->closeCursor();
                } catch (\Throwable) {
                    // Primární bezpečná chyba čtení má přednost před úklidem kurzoru.
                }
            }
        }
    }

    private function countValue(mixed $value, string $registryKey): int
    {
        if (is_int($value) && $value >= 0) {
            return $value;
        }
        if (!is_string($value)
            || preg_match('/^(?:0|[1-9][0-9]*)$/D', $value) !== 1
            || strlen($value) > strlen((string) PHP_INT_MAX)
            || strlen($value) === strlen((string) PHP_INT_MAX)
                && strcmp($value, (string) PHP_INT_MAX) > 0
        ) {
            throw new CompanyBackupDataSourceException(
                'secret_count_value_invalid',
                $registryKey,
            );
        }
        return (int) $value;
    }

    private function nullablePositiveInt(
        mixed $value,
        string $registryKey,
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
        throw new CompanyBackupDataSourceException(
            'secret_count_row_invalid',
            $registryKey,
            $column,
        );
    }

    private function nullableString(
        mixed $value,
        string $registryKey,
        string $column,
    ): ?string {
        if ($value === null || is_string($value)) {
            return $value;
        }
        throw new CompanyBackupDataSourceException(
            'secret_count_row_invalid',
            $registryKey,
            $column,
        );
    }
}
