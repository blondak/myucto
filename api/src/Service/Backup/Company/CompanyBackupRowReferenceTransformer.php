<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/**
 * Read-only transformace referencí jednoho ověřeného zdrojového řádku.
 * Interní a globální cíle čte z již sestavené identity mapy, externí actor
 * rozhodnutí pouze z cílovou databází ověřeného resolution plánu.
 */
final readonly class CompanyBackupRowReferenceTransformer
{
    public function __construct(
        private CompanyBackupTargetIdentityMap $identities,
        private CompanyBackupReferenceResolutionPlan $resolutions,
        private CompanyBackupArchiveLimits $limits =
            new CompanyBackupArchiveLimits(),
    ) {}

    /**
     * @param array<string,mixed> $row
     * @param null|callable(CompanyBackupEmbeddedHashReference,string):mixed $hashMapper
     * @return array<string,mixed>
     */
    public function transform(
        CompanyBackupTableProjection $projection,
        array $row,
        ?callable $hashMapper = null,
    ): array {
        $remapped = $projection->remapReferences(
            $row,
            fn (CompanyBackupReferenceOccurrence $occurrence): ?CompanyBackupSourceKey =>
                $this->resolve($occurrence),
            $hashMapper,
        );
        return $projection->restoreOverrides->apply($remapped);
    }

    /**
     * Připraví první INSERT bez lookupu referencí výslovně odložených plánem.
     * Zdrojový řádek musí zůstat dostupný pro finální druhý průchod.
     *
     * @param array<string,mixed> $row
     * @param null|callable(CompanyBackupEmbeddedHashReference,string):mixed $hashMapper
     * @return array<string,mixed>
     */
    public function transformForInsert(
        CompanyBackupTableProjection $projection,
        array $row,
        CompanyBackupImportDependencyPlan $plan,
        ?callable $hashMapper = null,
    ): array {
        if (!$plan->containsInsertRegistryKey($projection->registryKey)) {
            throw new CompanyBackupRowTransformException(
                'row_import_plan_mismatch',
                $projection->registryKey,
            );
        }

        $remapped = $projection->remapReferences(
            $row,
            function (
                CompanyBackupReferenceOccurrence $occurrence,
            ) use ($plan): CompanyBackupSourceKey|CompanyBackupReferenceRemapDirective|null {
                if (!$this->isInternalMapping($occurrence->mapping)) {
                    return $this->resolve($occurrence);
                }
                $kind = CompanyBackupImportDependencyKind::tryFrom(
                    $occurrence->sourceKind,
                );
                $dependency = $kind === null
                    ? null
                    : $plan->dependency(
                        $occurrence->sourceRegistryKey,
                        $occurrence->targetRegistryKey,
                        $kind,
                        $occurrence->signature,
                    );
                if (!$dependency instanceof CompanyBackupImportDependency) {
                    throw $this->error(
                        'row_import_plan_mismatch',
                        $occurrence,
                    );
                }
                return $dependency->deferred
                    ? CompanyBackupReferenceRemapDirective::Defer
                    : $this->resolve($occurrence);
            },
            function (
                CompanyBackupEmbeddedHashReference $reference,
                string $hash,
            ) use ($projection, $plan, $hashMapper): mixed {
                $dependency = $plan->dependency(
                    $projection->registryKey,
                    $reference->target,
                    CompanyBackupImportDependencyKind::EmbeddedHash,
                    $reference->signature(),
                );
                if (!$dependency instanceof CompanyBackupImportDependency) {
                    throw new CompanyBackupRowTransformException(
                        'row_import_plan_mismatch',
                        $projection->registryKey,
                        $reference->column,
                    );
                }
                if ($dependency->deferred) {
                    return CompanyBackupReferenceRemapDirective::Defer;
                }
                if ($hashMapper === null) {
                    throw new CompanyBackupRowTransformException(
                        'row_hash_reference_mapper_missing',
                        $projection->registryKey,
                        $reference->column,
                    );
                }
                return $hashMapper($reference, $hash);
            },
        );
        return $projection->restoreOverrides->apply($remapped);
    }

    private function resolve(
        CompanyBackupReferenceOccurrence $occurrence,
    ): ?CompanyBackupSourceKey {
        if (in_array($occurrence->mapping, [
            CompanyBackupReferenceMapping::Actor,
            CompanyBackupReferenceMapping::CredentialDecision,
        ], true)) {
            return $this->externalResolution($occurrence);
        }

        try {
            $sourceKey = CompanyBackupSourceKey::fromValues(
                $occurrence->targetRegistryKey,
                $occurrence->sourceKey,
                $this->limits->maxSourceKeyBytes,
            );
            $match = $this->identities->findMatch($sourceKey);
        } catch (CompanyBackupIdentityMapException|CompanyBackupPreflightException $e) {
            throw $this->error('row_reference_lookup_failed', $occurrence, $e);
        }
        if ($match === null) {
            throw $this->error('row_reference_unresolved', $occurrence);
        }
        if ($occurrence->mapping
            === CompanyBackupReferenceMapping::GlobalNaturalKey
        ) {
            $this->assertGlobalResolution($occurrence, $match);
        }
        return $match->mappedKey;
    }

    private function isInternalMapping(
        CompanyBackupReferenceMapping $mapping,
    ): bool {
        return in_array($mapping, [
            CompanyBackupReferenceMapping::TenantId,
            CompanyBackupReferenceMapping::TenantIdOrZero,
            CompanyBackupReferenceMapping::TenantReferenceKey,
            CompanyBackupReferenceMapping::TenantNaturalKey,
        ], true);
    }

    private function assertGlobalResolution(
        CompanyBackupReferenceOccurrence $occurrence,
        CompanyBackupTargetIdentityMatch $match,
    ): void {
        $requirementId = $match->externalRequirementId;
        $resolution = $requirementId === null
            ? null
            : $this->resolutions->resolution($requirementId);
        if ($resolution === null
            || $resolution->decision->requirementId !== $requirementId
            || $resolution->decision->mapping
                !== CompanyBackupReferenceMapping::GlobalNaturalKey
            || $resolution->decision->targetRegistryKey
                !== $occurrence->targetRegistryKey
            || $resolution->decision->action
                !== CompanyBackupReferenceDecisionAction::MapExisting
            || $resolution->targetPrimaryKey
                !== $match->targetPrimaryKey->values
        ) {
            throw $this->error(
                'row_reference_decision_invalid',
                $occurrence,
            );
        }
    }

    private function externalResolution(
        CompanyBackupReferenceOccurrence $occurrence,
    ): ?CompanyBackupSourceKey {
        $requirementId = CompanyBackupExternalReferenceRequirement::idFor(
            $occurrence->mapping,
            $occurrence->targetRegistryKey,
            $occurrence->sourceKey,
        );
        $resolution = $this->resolutions->resolution($requirementId);
        if ($resolution === null) {
            throw $this->error(
                'row_reference_decision_missing',
                $occurrence,
            );
        }
        $decision = $resolution->decision;
        if ($decision->mapping !== $occurrence->mapping
            || $decision->targetRegistryKey !== $occurrence->targetRegistryKey
        ) {
            throw $this->error(
                'row_reference_decision_invalid',
                $occurrence,
            );
        }

        if ($decision->action === CompanyBackupReferenceDecisionAction::SetNull) {
            return null;
        }
        if (!in_array($decision->action, [
            CompanyBackupReferenceDecisionAction::MapExisting,
            CompanyBackupReferenceDecisionAction::UseRestoreActor,
        ], true) || $resolution->targetPrimaryKey === null) {
            throw $this->error(
                'row_reference_action_unsupported',
                $occurrence,
            );
        }

        try {
            return CompanyBackupSourceKey::fromValues(
                $occurrence->targetRegistryKey,
                $resolution->targetPrimaryKey,
                $this->limits->maxSourceKeyBytes,
            );
        } catch (CompanyBackupPreflightException $e) {
            throw $this->error(
                'row_reference_decision_invalid',
                $occurrence,
                $e,
            );
        }
    }

    private function error(
        string $errorCode,
        CompanyBackupReferenceOccurrence $occurrence,
        ?\Throwable $previous = null,
    ): CompanyBackupRowTransformException {
        return new CompanyBackupRowTransformException(
            $errorCode,
            $occurrence->sourceRegistryKey,
            $occurrence->sourceColumn,
            $previous,
        );
    }
}
