<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

use MyInvoice\Service\Backup\CanonicalJson;

/** Úplný plán kolizí potvrzení svázaný s inventářem a cílem obnovy. */
final readonly class CompanyBackupApprovalReceiptDecisionPlan
{
    public const FORMAT = 'myucto-company-approval-receipt-decision-plan';
    public const VERSION = 1;

    /** @var list<array{source_invoice_id:int,action:'invalidate'|'reject'}> */
    private array $decisions;

    /** @var array<int,'invalidate'|'reject'> */
    private array $actionsById;

    public string $bindingSha256;

    /**
     * @param list<array{source_invoice_id:int,action:'invalidate'|'reject'}> $decisions
     * @param array<int,'invalidate'|'reject'> $actionsById
     */
    private function __construct(
        public CompanyBackupApprovalReceiptInventory $inventory,
        public string $dataPreflightBindingSha256,
        public string $targetRegistryFingerprint,
        public string $targetInstanceId,
        public int $restoreActorId,
        array $decisions,
        array $actionsById,
    ) {
        $this->decisions = $decisions;
        $this->actionsById = $actionsById;
        $this->bindingSha256 = CanonicalJson::sha256([
            'format' => self::FORMAT,
            'version' => self::VERSION,
            'receipt_inventory_sha256' => $inventory->sha256(),
            'data_preflight_binding_sha256' => $dataPreflightBindingSha256,
            'target_registry_fingerprint' => $targetRegistryFingerprint,
            'target_instance_id' => $targetInstanceId,
            'restore_actor_id' => $restoreActorId,
            'decisions' => $decisions,
        ]);
    }

    public static function fromArray(
        mixed $value,
        CompanyBackupApprovalReceiptInventory $inventory,
        string $dataPreflightBindingSha256,
        string $targetRegistryFingerprint,
        string $targetInstanceId,
        int $restoreActorId,
    ): self {
        self::validateContext(
            $dataPreflightBindingSha256,
            $targetRegistryFingerprint,
            $targetInstanceId,
            $restoreActorId,
        );
        if (!is_array($value) || array_is_list($value)) {
            throw self::error('approval_receipt_decision_plan_invalid');
        }
        $keys = array_keys($value);
        sort($keys, SORT_STRING);
        if ($keys !== ['data_preflight_binding_sha256', 'decisions']
            || !is_string($value['data_preflight_binding_sha256'])
            || !is_array($value['decisions'])
            || !array_is_list($value['decisions'])
            || count($value['decisions']) > $inventory->collisionCount()
        ) {
            throw self::error('approval_receipt_decision_plan_invalid');
        }
        if (!hash_equals($dataPreflightBindingSha256, $value['data_preflight_binding_sha256'])) {
            throw self::error('approval_receipt_decision_context_mismatch');
        }

        $actions = [];
        foreach ($value['decisions'] as $rawDecision) {
            if (!is_array($rawDecision) || array_is_list($rawDecision)) {
                throw self::error('approval_receipt_decision_invalid');
            }
            $decisionKeys = array_keys($rawDecision);
            sort($decisionKeys, SORT_STRING);
            if ($decisionKeys !== ['action', 'source_invoice_id']
                || !is_int($rawDecision['source_invoice_id'])
                || $rawDecision['source_invoice_id'] < 1
                || !in_array($rawDecision['action'], ['invalidate', 'reject'], true)
            ) {
                throw self::error('approval_receipt_decision_invalid');
            }
            $id = $rawDecision['source_invoice_id'];
            $entry = $inventory->entry($id);
            if ($entry === null || !$entry['collision']) {
                throw self::error('approval_receipt_decision_scope_mismatch');
            }
            if (isset($actions[$id])) {
                throw self::error('approval_receipt_decision_duplicate');
            }
            if ($rawDecision['action'] === 'reject') {
                throw self::error('approval_receipt_collision_rejected');
            }
            $actions[$id] = 'invalidate';
        }
        if (count($actions) !== $inventory->collisionCount()) {
            throw self::error('approval_receipt_decision_missing');
        }
        ksort($actions, SORT_NUMERIC);
        $decisions = [];
        foreach ($actions as $id => $action) {
            $decisions[] = ['source_invoice_id' => $id, 'action' => $action];
        }
        return new self(
            $inventory,
            $dataPreflightBindingSha256,
            $targetRegistryFingerprint,
            $targetInstanceId,
            $restoreActorId,
            $decisions,
            $actions,
        );
    }

    public function assertContext(
        CompanyBackupApprovalReceiptInventory $inventory,
        string $dataPreflightBindingSha256,
        string $targetRegistryFingerprint,
        string $targetInstanceId,
        int $restoreActorId,
    ): void {
        self::validateContext(
            $dataPreflightBindingSha256,
            $targetRegistryFingerprint,
            $targetInstanceId,
            $restoreActorId,
        );
        if (!hash_equals($this->inventory->sha256(), $inventory->sha256())
            || !hash_equals($this->dataPreflightBindingSha256, $dataPreflightBindingSha256)
            || !hash_equals($this->targetRegistryFingerprint, $targetRegistryFingerprint)
            || !hash_equals($this->targetInstanceId, $targetInstanceId)
            || $this->restoreActorId !== $restoreActorId
        ) {
            throw self::error('approval_receipt_decision_context_mismatch');
        }
    }

    /** $currentCollision zahrnuje kolizi v cíli i duplicity uvnitř archivu. */
    public function resolve(int $sourceId, #[\SensitiveParameter] mixed $hash, bool $currentCollision): ?string
    {
        $entry = $this->inventory->entry($sourceId);
        if ($entry === null || !is_string($hash)
            || !hash_equals($entry['receipt_fingerprint'], hash('sha256', $hash))
            || $entry['collision'] !== $currentCollision
        ) {
            throw self::error('approval_receipt_decision_stale');
        }
        return CompanyBackupApprovalReceiptPolicy::restoreHash(
            $hash,
            $currentCollision,
            $this->actionsById[$sourceId] ?? null,
        );
    }

    /** @return list<array{source_invoice_id:int,action:'invalidate'|'reject'}> */
    public function decisions(): array
    {
        return $this->decisions;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'format' => self::FORMAT,
            'version' => self::VERSION,
            'receipt_inventory_sha256' => $this->inventory->sha256(),
            'data_preflight_binding_sha256' => $this->dataPreflightBindingSha256,
            'target_registry_fingerprint' => $this->targetRegistryFingerprint,
            'target_instance_id' => $this->targetInstanceId,
            'restore_actor_id' => $this->restoreActorId,
            'decisions' => $this->decisions,
            'binding_sha256' => $this->bindingSha256,
        ];
    }

    private static function validateContext(
        string $dataPreflightBindingSha256,
        string $targetRegistryFingerprint,
        string $targetInstanceId,
        int $restoreActorId,
    ): void {
        if (preg_match('/\A[0-9a-f]{64}\z/D', $dataPreflightBindingSha256) !== 1
            || preg_match('/\A[0-9a-f]{64}\z/D', $targetRegistryFingerprint) !== 1
            || !CompanyBackupManifestHeader::isCanonicalBackupId($targetInstanceId)
            || $restoreActorId < 1
        ) {
            throw self::error('approval_receipt_decision_context_mismatch');
        }
    }

    private static function error(string $code): CompanyBackupPreflightException
    {
        return new CompanyBackupPreflightException(
            $code,
            CompanyBackupApprovalReceiptPolicy::REGISTRY_KEY,
            CompanyBackupApprovalReceiptPolicy::COLUMN,
        );
    }
}
