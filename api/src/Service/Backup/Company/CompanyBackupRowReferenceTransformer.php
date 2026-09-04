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
