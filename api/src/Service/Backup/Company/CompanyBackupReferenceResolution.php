<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/** Jedno cílovou databází ověřené externí mapování. */
final readonly class CompanyBackupReferenceResolution
{
    /** @var array<string,int|string>|null */
    public ?array $targetPrimaryKey;

    /** @param array<string,int|string>|null $targetPrimaryKey */
    public function __construct(
        public CompanyBackupReferenceDecision $decision,
        ?array $targetPrimaryKey,
        CompanyBackupArchiveLimits $limits = new CompanyBackupArchiveLimits(),
    ) {
        $requiresTarget = in_array($decision->action, [
            CompanyBackupReferenceDecisionAction::MapExisting,
            CompanyBackupReferenceDecisionAction::UseRestoreActor,
        ], true);
        if ($requiresTarget !== ($targetPrimaryKey !== null)) {
            throw new \LogicException(
                'Výsledek mapování neodpovídá zvolené akci.',
            );
        }
        if ($targetPrimaryKey === null) {
            $this->targetPrimaryKey = null;
            return;
        }

        try {
            $targetKey = CompanyBackupSourceKey::fromValues(
                $decision->targetRegistryKey,
                $targetPrimaryKey,
                $limits->maxSourceKeyBytes,
            );
        } catch (CompanyBackupPreflightException $e) {
            throw new \LogicException('Výsledek mapování nemá platný klíč.', 0, $e);
        }
        if ($decision->action === CompanyBackupReferenceDecisionAction::MapExisting
            && $decision->targetPrimaryKey !== $targetKey->values
        ) {
            throw new \LogicException(
                'Výsledek mapování neodpovídá rozhodnutému cíli.',
            );
        }
        $this->targetPrimaryKey = $targetKey->values;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'requirement_id' => $this->decision->requirementId,
            'mapping' => $this->decision->mapping->value,
            'target_registry_key' => $this->decision->targetRegistryKey,
            'action' => $this->decision->action->value,
            'target_primary_key' => $this->targetPrimaryKey,
        ];
    }
}
