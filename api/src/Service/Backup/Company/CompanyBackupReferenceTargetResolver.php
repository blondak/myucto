<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

use MyInvoice\Service\Backup\Registry\TenantDataDefinition;
use MyInvoice\Service\Backup\Registry\TenantDataObjectKind;
use MyInvoice\Service\Backup\Registry\TenantDataPolicy;
use MyInvoice\Service\Backup\Registry\TenantDataRegistry;
use MyInvoice\Service\Backup\Registry\TenantDataRegistrySnapshot;
use PDO;
use PDOStatement;

/**
 * Ověří plán proti aktuálním cílovým řádkům výhradně SELECT dotazy.
 * Import jej musí zopakovat ve své transakci, aby mezi preflightem a zápisem
 * nevzniklo TOCTOU okno.
 */
final readonly class CompanyBackupReferenceTargetResolver
{
    public function __construct(
        private PDO $database,
        private CompanyBackupArchiveLimits $limits =
            new CompanyBackupArchiveLimits(),
    ) {}

    public function resolve(
        CompanyBackupReferenceDecisionPlan $decisionPlan,
        CompanyBackupDataPreflightResult $preflight,
        TenantDataRegistrySnapshot $targetRegistry,
    ): CompanyBackupReferenceResolutionPlan {
        $this->assertContext($decisionPlan, $preflight, $targetRegistry);
        $restoreActorKey = $this->resolveRestoreActor(
            $decisionPlan,
            $targetRegistry,
        );

        $resolutions = [];
        foreach ($preflight->externalReferences->requirements as $requirement) {
            $decision = $decisionPlan->decision($requirement->id);
            if (!$decision instanceof CompanyBackupReferenceDecision) {
                throw self::error('reference_resolution_context_mismatch');
            }
            $definition = $targetRegistry->registry->definition(
                $requirement->targetRegistryKey,
            );
            if (!$definition instanceof TenantDataDefinition) {
                throw self::error(
                    'reference_target_contract_mismatch',
                    $requirement->id,
                );
            }
            $targetPrimaryKey = match ($requirement->mapping) {
                CompanyBackupReferenceMapping::GlobalNaturalKey =>
                    $this->resolveGlobal($requirement, $decision, $definition),
                CompanyBackupReferenceMapping::Actor =>
                    $this->resolveActor(
                        $requirement,
                        $decision,
                        $definition,
                        $restoreActorKey,
                    ),
                CompanyBackupReferenceMapping::CredentialDecision =>
                    $this->resolveCredential(
                        $requirement,
                        $decision,
                        $definition,
                    ),
                default => throw self::error(
                    'reference_target_contract_mismatch',
                    $requirement->id,
                ),
            };
            $resolutions[] = new CompanyBackupReferenceResolution(
                $decision,
                $targetPrimaryKey,
                $this->limits,
            );
        }

        return CompanyBackupReferenceResolutionPlan::fromResolutions(
            $decisionPlan,
            $resolutions,
        );
    }

    private function assertContext(
        CompanyBackupReferenceDecisionPlan $decisionPlan,
        CompanyBackupDataPreflightResult $preflight,
        TenantDataRegistrySnapshot $targetRegistry,
    ): void {
        if ($targetRegistry->profile !== TenantDataRegistry::COMPANY_BACKUP_PROFILE
            || !hash_equals(
                $decisionPlan->dataPreflightBindingSha256,
                $preflight->bindingSha256,
            )
            || !hash_equals(
                $decisionPlan->targetRegistryFingerprint,
                $targetRegistry->fingerprint,
            )
            || !hash_equals(
                $preflight->targetRegistryFingerprint,
                $targetRegistry->fingerprint,
            )
        ) {
            throw self::error('reference_resolution_context_mismatch');
        }
    }

    /** @return array{id:int|string} */
    private function resolveRestoreActor(
        CompanyBackupReferenceDecisionPlan $decisionPlan,
        TenantDataRegistrySnapshot $targetRegistry,
    ): array {
        $definition = $targetRegistry->registry->definition('table:users');
        if (!$definition instanceof TenantDataDefinition
            || $definition->kind !== TenantDataObjectKind::Table
            || $definition->policy !== TenantDataPolicy::InstanceOwned
            || $this->keyColumns($definition, 'primary_key', null) !== ['id']
        ) {
            throw self::error('reference_restore_actor_contract_mismatch');
        }
        $expected = ['id' => $decisionPlan->restoreActorId];
        $matches = $this->findPrimaryKeys($definition, $expected, null);
        if ($matches === []) {
            throw self::error('reference_restore_actor_missing');
        }
        if (count($matches) !== 1 || $matches[0] !== $expected) {
            throw self::error('reference_restore_actor_ambiguous');
        }
        return $expected;
    }

    /** @return array<string,int|string> */
    private function resolveGlobal(
        CompanyBackupExternalReferenceRequirement $requirement,
        CompanyBackupReferenceDecision $decision,
        TenantDataDefinition $definition,
    ): array {
        if ($definition->kind !== TenantDataObjectKind::Table
            || $definition->policy !== TenantDataPolicy::GlobalReference
            || $this->keyColumns(
                $definition,
                'natural_key',
                $requirement->id,
            ) !== array_keys($requirement->sourceKey)
            || $decision->action !== CompanyBackupReferenceDecisionAction::MapExisting
            || $decision->targetPrimaryKey === null
        ) {
            throw self::error(
                'reference_target_contract_mismatch',
                $requirement->id,
            );
        }
        $matches = $this->findPrimaryKeys(
            $definition,
            $requirement->sourceKey,
            $requirement->id,
        );
        $match = $this->singleMatch($matches, $requirement->id);
        if ($decision->targetPrimaryKey !== $match) {
            throw self::error(
                'reference_target_key_mismatch',
                $requirement->id,
            );
        }
        return $match;
    }

    /**
     * @param array{id:int|string} $restoreActorKey
     * @return array<string,int|string>|null
     */
    private function resolveActor(
        CompanyBackupExternalReferenceRequirement $requirement,
        CompanyBackupReferenceDecision $decision,
        TenantDataDefinition $definition,
        array $restoreActorKey,
    ): ?array {
        if ($definition->kind !== TenantDataObjectKind::Table
            || $definition->policy !== TenantDataPolicy::InstanceOwned
            || $definition->key !== 'table:users'
            || $this->keyColumns(
                $definition,
                'primary_key',
                $requirement->id,
            ) !== ['id']
        ) {
            throw self::error(
                'reference_target_contract_mismatch',
                $requirement->id,
            );
        }
        return match ($decision->action) {
            CompanyBackupReferenceDecisionAction::MapExisting =>
                $this->resolveMappedActor($requirement, $decision, $definition),
            CompanyBackupReferenceDecisionAction::UseRestoreActor =>
                $restoreActorKey,
            CompanyBackupReferenceDecisionAction::SetNull => null,
            default => throw self::error(
                'reference_target_contract_mismatch',
                $requirement->id,
            ),
        };
    }

    /** @return array<string,int|string> */
    private function resolveMappedActor(
        CompanyBackupExternalReferenceRequirement $requirement,
        CompanyBackupReferenceDecision $decision,
        TenantDataDefinition $definition,
    ): array {
        $targetPrimaryKey = $decision->targetPrimaryKey;
        if ($targetPrimaryKey === null) {
            throw self::error(
                'reference_target_contract_mismatch',
                $requirement->id,
            );
        }
        $matches = $this->findPrimaryKeys(
            $definition,
            $targetPrimaryKey,
            $requirement->id,
        );
        $match = $this->singleMatch($matches, $requirement->id);
        if ($match !== $targetPrimaryKey) {
            throw self::error(
                'reference_target_key_mismatch',
                $requirement->id,
            );
        }
        return $match;
    }

    private function resolveCredential(
        CompanyBackupExternalReferenceRequirement $requirement,
        CompanyBackupReferenceDecision $decision,
        TenantDataDefinition $definition,
    ): null {
        if ($definition->kind !== TenantDataObjectKind::Table
            || $definition->policy
                !== TenantDataPolicy::PersonalSecretAttachment
            || $decision->action !== CompanyBackupReferenceDecisionAction::Omit
        ) {
            throw self::error(
                'reference_target_contract_mismatch',
                $requirement->id,
            );
        }
        return null;
    }

    /**
     * @param array<string,int|string> $lookupKey
     * @return list<array<string,int|string>>
     */
    private function findPrimaryKeys(
        TenantDataDefinition $definition,
        array $lookupKey,
        ?string $requirementId,
    ): array {
        $primaryKey = $this->keyColumns(
            $definition,
            'primary_key',
            $requirementId,
        );
        try {
            $lookup = CompanyBackupSourceKey::fromValues(
                $definition->key,
                $lookupKey,
                $this->limits->maxSourceKeyBytes,
            );
            $table = CompanyBackupTenantSqlSelector::quoteIdentifier(
                $definition->name(),
                $definition->key,
            );
            $select = implode(', ', array_map(
                static fn (string $column): string =>
                    CompanyBackupTenantSqlSelector::quoteIdentifier(
                        $column,
                        $definition->key,
                    ),
                $primaryKey,
            ));
            $where = implode(' AND ', array_map(
                static fn (string $column): string =>
                    CompanyBackupTenantSqlSelector::quoteIdentifier(
                        $column,
                        $definition->key,
                    ) . ' = ?',
                $lookup->columns,
            ));
            $order = implode(', ', array_map(
                static fn (string $column): string =>
                    CompanyBackupTenantSqlSelector::quoteIdentifier(
                        $column,
                        $definition->key,
                    ),
                $primaryKey,
            ));
            $statement = $this->database->prepare(
                'SELECT ' . $select . ' FROM ' . $table
                . ' WHERE ' . $where . ' ORDER BY ' . $order . ' LIMIT 2',
            );
            if (!$statement instanceof PDOStatement) {
                throw new \RuntimeException('Cílový lookup nelze připravit.');
            }
            try {
                foreach (array_values($lookup->values) as $index => $value) {
                    if (!$statement->bindValue(
                        $index + 1,
                        $value,
                        is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR,
                    )) {
                        throw new \RuntimeException(
                            'Parametr cílového lookupu nelze navázat.',
                        );
                    }
                }
                if (!$statement->execute()) {
                    throw new \RuntimeException('Cílový lookup nelze provést.');
                }
                $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
                if (!array_is_list($rows)) {
                    throw new \RuntimeException('Cílový lookup nemá seznam řádků.');
                }
                if (!$statement->closeCursor()) {
                    throw new \RuntimeException('Cílový lookup nelze uzavřít.');
                }
                $statement = null;
            } finally {
                if ($statement instanceof PDOStatement) {
                    try {
                        $statement->closeCursor();
                    } catch (\Throwable) {
                        // Primární bezpečná chyba lookupu má přednost před úklidem.
                    }
                }
            }
        } catch (\Throwable $e) {
            throw self::error(
                'reference_target_query_failed',
                $requirementId,
                $e,
            );
        }

        $matches = [];
        foreach ($rows as $row) {
            if (!is_array($row)
                || array_is_list($row)
                || array_keys($row) !== $primaryKey
            ) {
                throw self::error(
                    'reference_target_row_invalid',
                    $requirementId,
                );
            }
            $values = [];
            foreach ($primaryKey as $column) {
                $value = $row[$column];
                if (!is_int($value) && !is_string($value)) {
                    throw self::error(
                        'reference_target_row_invalid',
                        $requirementId,
                    );
                }
                $values[$column] = $value;
            }
            try {
                $matches[] = CompanyBackupSourceKey::fromValues(
                    $definition->key,
                    $values,
                    $this->limits->maxSourceKeyBytes,
                )->values;
            } catch (CompanyBackupPreflightException $e) {
                throw self::error(
                    'reference_target_row_invalid',
                    $requirementId,
                    $e,
                );
            }
        }
        return $matches;
    }

    /**
     * @param list<array<string,int|string>> $matches
     * @return array<string,int|string>
     */
    private function singleMatch(array $matches, string $requirementId): array
    {
        if ($matches === []) {
            throw self::error('reference_target_missing', $requirementId);
        }
        if (count($matches) !== 1) {
            throw self::error('reference_target_ambiguous', $requirementId);
        }
        return $matches[0];
    }

    /** @return list<string> */
    private function keyColumns(
        TenantDataDefinition $definition,
        string $field,
        ?string $requirementId,
    ): array {
        $value = $definition->details[$field] ?? null;
        if (!is_array($value)
            || !array_is_list($value)
            || $value === []
            || count($value) > 16
        ) {
            throw self::error(
                'reference_target_contract_mismatch',
                $requirementId,
            );
        }
        $columns = [];
        foreach ($value as $column) {
            if (!is_string($column)
                || preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $column) !== 1
                || isset($columns[$column])
            ) {
                throw self::error(
                    'reference_target_contract_mismatch',
                    $requirementId,
                );
            }
            $columns[$column] = true;
        }
        return array_keys($columns);
    }

    private static function error(
        string $errorCode,
        ?string $requirementId = null,
        ?\Throwable $previous = null,
    ): CompanyBackupReferenceResolutionException {
        return new CompanyBackupReferenceResolutionException(
            $errorCode,
            $requirementId,
            $previous,
        );
    }
}
