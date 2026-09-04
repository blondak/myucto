<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

use MyInvoice\Service\Backup\CanonicalJson;
use MyInvoice\Service\Backup\Registry\TenantDataRegistry;
use MyInvoice\Service\Backup\Registry\TenantDataRegistrySnapshot;

/**
 * Úplný kanonický plán externích referencí svázaný s preflightem a
 * konkrétní cílovou instancí.
 */
final readonly class CompanyBackupReferenceDecisionPlan
{
    public const FORMAT = 'myucto-company-reference-decision-plan';
    public const VERSION = 1;

    /** @var list<CompanyBackupReferenceDecision> */
    private array $decisions;

    /** @var array<string,CompanyBackupReferenceDecision> */
    private array $decisionsByRequirement;

    public string $bindingSha256;

    /**
     * @param list<CompanyBackupReferenceDecision> $decisions
     * @param array<string,CompanyBackupReferenceDecision> $decisionsByRequirement
     */
    private function __construct(
        public string $dataPreflightBindingSha256,
        public string $targetRegistryFingerprint,
        public string $targetInstanceId,
        public int $restoreActorId,
        array $decisions,
        array $decisionsByRequirement,
    ) {
        $this->decisions = $decisions;
        $this->decisionsByRequirement = $decisionsByRequirement;
        $this->bindingSha256 = CanonicalJson::sha256([
            'format' => self::FORMAT,
            'version' => self::VERSION,
            'data_preflight_binding_sha256' => $dataPreflightBindingSha256,
            'target_registry_fingerprint' => $targetRegistryFingerprint,
            'target_instance_id' => $targetInstanceId,
            'restore_actor_id' => $restoreActorId,
            'decisions' => array_map(
                static fn (CompanyBackupReferenceDecision $decision): array =>
                    $decision->toArray(),
                $decisions,
            ),
        ]);
    }

    public static function fromArray(
        mixed $value,
        CompanyBackupDataPreflightResult $preflight,
        TenantDataRegistrySnapshot $targetRegistry,
        string $targetInstanceId,
        int $restoreActorId,
        CompanyBackupArchiveLimits $limits = new CompanyBackupArchiveLimits(),
    ): self {
        self::assertServerContext(
            $preflight,
            $targetRegistry,
            $targetInstanceId,
            $restoreActorId,
        );
        if (!is_array($value) || array_is_list($value)) {
            throw self::error('reference_decision_plan_invalid');
        }
        $keys = array_keys($value);
        sort($keys, SORT_STRING);
        if ($keys !== [
            'data_preflight_binding_sha256',
            'decisions',
            'format',
            'version',
        ]) {
            throw self::error('reference_decision_plan_invalid');
        }
        $preflightBinding = $value['data_preflight_binding_sha256'];
        $rawDecisions = $value['decisions'];
        if ($value['format'] !== self::FORMAT
            || $value['version'] !== self::VERSION
            || !is_string($preflightBinding)
            || preg_match('/^[0-9a-f]{64}$/D', $preflightBinding) !== 1
            || !is_array($rawDecisions)
            || !array_is_list($rawDecisions)
            || count($rawDecisions) > $limits->maxReferenceRequirements
        ) {
            throw self::error('reference_decision_plan_invalid');
        }
        if (!hash_equals($preflight->bindingSha256, $preflightBinding)) {
            throw self::error('reference_decision_context_mismatch');
        }

        $requirements = [];
        foreach ($preflight->externalReferences->requirements as $requirement) {
            $requirements[$requirement->id] = $requirement;
        }
        $decisions = [];
        foreach ($rawDecisions as $rawDecision) {
            $requirementId = is_array($rawDecision)
                ? ($rawDecision['requirement_id'] ?? null)
                : null;
            if (!is_string($requirementId)
                || preg_match('/^sha256:[0-9a-f]{64}$/D', $requirementId) !== 1
            ) {
                throw self::error('reference_decision_invalid');
            }
            $requirement = $requirements[$requirementId] ?? null;
            if (!$requirement instanceof CompanyBackupExternalReferenceRequirement) {
                throw self::error('reference_decision_scope_mismatch');
            }
            if (isset($decisions[$requirementId])) {
                throw self::error(
                    'reference_decision_duplicate',
                    $requirementId,
                );
            }
            $decisions[$requirementId] = CompanyBackupReferenceDecision::fromArray(
                $rawDecision,
                $requirement,
                $targetRegistry,
                $limits,
            );
        }

        $missing = array_diff_key($requirements, $decisions);
        if ($missing !== []) {
            throw self::error(
                'reference_decision_missing',
                array_key_first($missing),
            );
        }
        ksort($decisions, SORT_STRING);
        return new self(
            $preflightBinding,
            $targetRegistry->fingerprint,
            $targetInstanceId,
            $restoreActorId,
            array_values($decisions),
            $decisions,
        );
    }

    /** @return list<CompanyBackupReferenceDecision> */
    public function decisions(): array
    {
        return $this->decisions;
    }

    public function decision(
        string $requirementId,
    ): ?CompanyBackupReferenceDecision {
        return $this->decisionsByRequirement[$requirementId] ?? null;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'format' => self::FORMAT,
            'version' => self::VERSION,
            'data_preflight_binding_sha256' => $this->dataPreflightBindingSha256,
            'target_registry_fingerprint' => $this->targetRegistryFingerprint,
            'target_instance_id' => $this->targetInstanceId,
            'restore_actor_id' => $this->restoreActorId,
            'decisions' => array_map(
                static fn (CompanyBackupReferenceDecision $decision): array =>
                    $decision->toArray(),
                $this->decisions,
            ),
            'binding_sha256' => $this->bindingSha256,
        ];
    }

    private static function assertServerContext(
        CompanyBackupDataPreflightResult $preflight,
        TenantDataRegistrySnapshot $targetRegistry,
        string $targetInstanceId,
        int $restoreActorId,
    ): void {
        if ($targetRegistry->profile !== TenantDataRegistry::COMPANY_BACKUP_PROFILE
            || !hash_equals(
                $preflight->targetRegistryFingerprint,
                $targetRegistry->fingerprint,
            )
            || !CompanyBackupManifestHeader::isCanonicalBackupId($targetInstanceId)
            || $restoreActorId < 1
        ) {
            throw self::error('reference_decision_context_mismatch');
        }
    }

    private static function error(
        string $errorCode,
        ?string $requirementId = null,
    ): CompanyBackupReferenceDecisionException {
        return new CompanyBackupReferenceDecisionException(
            $errorCode,
            $requirementId,
        );
    }
}
