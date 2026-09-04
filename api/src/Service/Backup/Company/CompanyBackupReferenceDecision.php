<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

use MyInvoice\Service\Backup\Registry\TenantDataDefinition;
use MyInvoice\Service\Backup\Registry\TenantDataObjectKind;
use MyInvoice\Service\Backup\Registry\TenantDataPolicy;
use MyInvoice\Service\Backup\Registry\TenantDataRegistrySnapshot;

/** Jedno typované rozhodnutí nad přesným požadavkem datového preflightu. */
final readonly class CompanyBackupReferenceDecision
{
    /** @var array<string,int|string>|null */
    public ?array $targetPrimaryKey;

    /** @param array<string,int|string>|null $targetPrimaryKey */
    private function __construct(
        public string $requirementId,
        public CompanyBackupReferenceMapping $mapping,
        public string $targetRegistryKey,
        public CompanyBackupReferenceDecisionAction $action,
        ?array $targetPrimaryKey,
    ) {
        $this->targetPrimaryKey = $targetPrimaryKey;
    }

    public static function fromArray(
        mixed $value,
        CompanyBackupExternalReferenceRequirement $requirement,
        TenantDataRegistrySnapshot $targetRegistry,
        CompanyBackupArchiveLimits $limits = new CompanyBackupArchiveLimits(),
    ): self {
        if (!is_array($value) || array_is_list($value)) {
            throw self::error('reference_decision_invalid', $requirement);
        }
        $keys = array_keys($value);
        sort($keys, SORT_STRING);
        if ($keys !== [
            'action',
            'mapping',
            'requirement_id',
            'target_primary_key',
            'target_registry_key',
        ]) {
            throw self::error('reference_decision_invalid', $requirement);
        }

        $requirementId = $value['requirement_id'];
        $mappingValue = $value['mapping'];
        $targetRegistryKey = $value['target_registry_key'];
        $actionValue = $value['action'];
        $mapping = is_string($mappingValue)
            ? CompanyBackupReferenceMapping::tryFrom($mappingValue)
            : null;
        $action = is_string($actionValue)
            ? CompanyBackupReferenceDecisionAction::tryFrom($actionValue)
            : null;
        if (!is_string($requirementId)
            || !is_string($targetRegistryKey)
            || $mapping === null
            || $action === null
        ) {
            throw self::error('reference_decision_invalid', $requirement);
        }
        if (!hash_equals($requirement->id, $requirementId)
            || $mapping !== $requirement->mapping
            || $targetRegistryKey !== $requirement->targetRegistryKey
        ) {
            throw self::error('reference_decision_scope_mismatch', $requirement);
        }

        $definition = $targetRegistry->registry->definition($targetRegistryKey);
        if (!$definition instanceof TenantDataDefinition
            || !$definition->hasProfile($targetRegistry->profile)
            || $definition->kind !== TenantDataObjectKind::Table
            || !self::targetContractMatches($requirement, $definition)
        ) {
            throw self::error(
                'reference_decision_target_contract_mismatch',
                $requirement,
            );
        }
        $primaryKeyColumns = self::keyColumns(
            $definition->details['primary_key'] ?? null,
            $requirement,
        );
        if (!self::actionAllowed($requirement, $action)) {
            throw self::error(
                'reference_decision_action_forbidden',
                $requirement,
            );
        }

        $targetPrimaryKey = $value['target_primary_key'];
        if ($action !== CompanyBackupReferenceDecisionAction::MapExisting) {
            if ($targetPrimaryKey !== null) {
                throw self::error(
                    'reference_decision_target_key_invalid',
                    $requirement,
                );
            }
            return new self(
                $requirement->id,
                $requirement->mapping,
                $requirement->targetRegistryKey,
                $action,
                null,
            );
        }

        if (!is_array($targetPrimaryKey) || array_is_list($targetPrimaryKey)) {
            throw self::error(
                'reference_decision_target_key_invalid',
                $requirement,
            );
        }
        $actualColumns = array_keys($targetPrimaryKey);
        sort($actualColumns, SORT_STRING);
        $expectedColumns = $primaryKeyColumns;
        sort($expectedColumns, SORT_STRING);
        if ($actualColumns !== $expectedColumns) {
            throw self::error(
                'reference_decision_target_key_invalid',
                $requirement,
            );
        }
        $orderedKey = [];
        foreach ($primaryKeyColumns as $column) {
            $orderedKey[$column] = $targetPrimaryKey[$column];
        }
        try {
            $targetKey = CompanyBackupSourceKey::fromValues(
                $targetRegistryKey,
                $orderedKey,
                $limits->maxSourceKeyBytes,
            );
        } catch (CompanyBackupPreflightException $e) {
            throw self::error(
                'reference_decision_target_key_invalid',
                $requirement,
                $e,
            );
        }

        return new self(
            $requirement->id,
            $requirement->mapping,
            $requirement->targetRegistryKey,
            $action,
            $targetKey->values,
        );
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'requirement_id' => $this->requirementId,
            'mapping' => $this->mapping->value,
            'target_registry_key' => $this->targetRegistryKey,
            'action' => $this->action->value,
            'target_primary_key' => $this->targetPrimaryKey,
        ];
    }

    private static function actionAllowed(
        CompanyBackupExternalReferenceRequirement $requirement,
        CompanyBackupReferenceDecisionAction $action,
    ): bool {
        return match ($requirement->mapping) {
            CompanyBackupReferenceMapping::GlobalNaturalKey =>
                $action === CompanyBackupReferenceDecisionAction::MapExisting,
            CompanyBackupReferenceMapping::Actor => match ($action) {
                CompanyBackupReferenceDecisionAction::MapExisting => true,
                CompanyBackupReferenceDecisionAction::UseRestoreActor =>
                    in_array('restore_actor', $requirement->fallbacks, true),
                CompanyBackupReferenceDecisionAction::SetNull =>
                    in_array('null', $requirement->fallbacks, true),
                default => false,
            },
            // Osobní credential zůstává vynechaný, dokud není rozhodnutí
            // rozšířeno o samostatný zdrojový i cílový consent kontrakt.
            CompanyBackupReferenceMapping::CredentialDecision =>
                $action === CompanyBackupReferenceDecisionAction::Omit,
            default => false,
        };
    }

    private static function targetContractMatches(
        CompanyBackupExternalReferenceRequirement $requirement,
        TenantDataDefinition $definition,
    ): bool {
        $policyMatches = match ($requirement->mapping) {
            CompanyBackupReferenceMapping::GlobalNaturalKey =>
                $definition->policy === TenantDataPolicy::GlobalReference,
            CompanyBackupReferenceMapping::Actor =>
                $definition->policy === TenantDataPolicy::InstanceOwned,
            CompanyBackupReferenceMapping::CredentialDecision =>
                $definition->policy === TenantDataPolicy::PersonalSecretAttachment,
            default => false,
        };
        if (!$policyMatches) {
            return false;
        }
        if ($requirement->mapping !== CompanyBackupReferenceMapping::GlobalNaturalKey) {
            return true;
        }
        $naturalKey = $definition->details['natural_key'] ?? null;
        if (!is_array($naturalKey) || !array_is_list($naturalKey)) {
            return false;
        }
        return $naturalKey === array_keys($requirement->sourceKey);
    }

    /** @return list<string> */
    private static function keyColumns(
        mixed $value,
        CompanyBackupExternalReferenceRequirement $requirement,
    ): array {
        if (!is_array($value)
            || !array_is_list($value)
            || $value === []
            || count($value) > 16
        ) {
            throw self::error(
                'reference_decision_target_contract_mismatch',
                $requirement,
            );
        }
        $columns = [];
        foreach ($value as $column) {
            if (!is_string($column)
                || preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $column) !== 1
                || isset($columns[$column])
            ) {
                throw self::error(
                    'reference_decision_target_contract_mismatch',
                    $requirement,
                );
            }
            $columns[$column] = true;
        }
        return array_keys($columns);
    }

    private static function error(
        string $errorCode,
        CompanyBackupExternalReferenceRequirement $requirement,
        ?\Throwable $previous = null,
    ): CompanyBackupReferenceDecisionException {
        return new CompanyBackupReferenceDecisionException(
            $errorCode,
            $requirement->id,
            $previous,
        );
    }
}
