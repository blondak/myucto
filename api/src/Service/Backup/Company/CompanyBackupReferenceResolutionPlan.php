<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

use MyInvoice\Service\Backup\CanonicalJson;

/** Kanonický výsledek read-only ověření všech cílových referencí. */
final readonly class CompanyBackupReferenceResolutionPlan
{
    public const FORMAT = 'myucto-company-reference-resolution-plan';
    public const VERSION = 1;

    /** @var list<CompanyBackupReferenceResolution> */
    private array $resolutions;

    /** @var array<string,CompanyBackupReferenceResolution> */
    private array $resolutionsByRequirement;

    public string $bindingSha256;

    /**
     * @param list<CompanyBackupReferenceResolution> $resolutions
     * @param array<string,CompanyBackupReferenceResolution> $resolutionsByRequirement
     */
    private function __construct(
        public string $decisionPlanBindingSha256,
        public string $targetRegistryFingerprint,
        public string $targetInstanceId,
        public int $restoreActorId,
        array $resolutions,
        array $resolutionsByRequirement,
    ) {
        $this->resolutions = $resolutions;
        $this->resolutionsByRequirement = $resolutionsByRequirement;
        $this->bindingSha256 = CanonicalJson::sha256([
            'format' => self::FORMAT,
            'version' => self::VERSION,
            'decision_plan_binding_sha256' => $decisionPlanBindingSha256,
            'target_registry_fingerprint' => $targetRegistryFingerprint,
            'target_instance_id' => $targetInstanceId,
            'restore_actor_id' => $restoreActorId,
            'resolutions' => array_map(
                static fn (CompanyBackupReferenceResolution $resolution): array =>
                    $resolution->toArray(),
                $resolutions,
            ),
        ]);
    }

    /** @param array<mixed> $resolutions */
    public static function fromResolutions(
        CompanyBackupReferenceDecisionPlan $decisionPlan,
        array $resolutions,
    ): self {
        if (!array_is_list($resolutions)) {
            throw new \LogicException(
                'Plán cílového mapování musí být seznam.',
            );
        }
        $decisions = [];
        foreach ($decisionPlan->decisions() as $decision) {
            $decisions[$decision->requirementId] = $decision;
        }
        $indexed = [];
        foreach ($resolutions as $resolution) {
            if (!$resolution instanceof CompanyBackupReferenceResolution) {
                throw new \LogicException(
                    'Plán cílového mapování obsahuje neplatný výsledek.',
                );
            }
            $requirementId = $resolution->decision->requirementId;
            $decision = $decisions[$requirementId] ?? null;
            if (!$decision instanceof CompanyBackupReferenceDecision
                || $decision->toArray() !== $resolution->decision->toArray()
                || isset($indexed[$requirementId])
            ) {
                throw new \LogicException(
                    'Plán cílového mapování neodpovídá rozhodnutím.',
                );
            }
            $indexed[$requirementId] = $resolution;
        }
        if (count($indexed) !== count($decisions)) {
            throw new \LogicException(
                'Plán cílového mapování není úplný.',
            );
        }
        ksort($indexed, SORT_STRING);
        return new self(
            $decisionPlan->bindingSha256,
            $decisionPlan->targetRegistryFingerprint,
            $decisionPlan->targetInstanceId,
            $decisionPlan->restoreActorId,
            array_values($indexed),
            $indexed,
        );
    }

    /** @return list<CompanyBackupReferenceResolution> */
    public function resolutions(): array
    {
        return $this->resolutions;
    }

    public function resolution(
        string $requirementId,
    ): ?CompanyBackupReferenceResolution {
        return $this->resolutionsByRequirement[$requirementId] ?? null;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'format' => self::FORMAT,
            'version' => self::VERSION,
            'decision_plan_binding_sha256' => $this->decisionPlanBindingSha256,
            'target_registry_fingerprint' => $this->targetRegistryFingerprint,
            'target_instance_id' => $this->targetInstanceId,
            'restore_actor_id' => $this->restoreActorId,
            'resolutions' => array_map(
                static fn (CompanyBackupReferenceResolution $resolution): array =>
                    $resolution->toArray(),
                $this->resolutions,
            ),
            'binding_sha256' => $this->bindingSha256,
        ];
    }
}
