<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Registration;

use MyInvoice\Repository\Payroll\PayrollRegistrationIdentitySnapshotRepository;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveData;

final readonly class PayrollRegistrationIdentitySnapshotService
{
    public function __construct(
        private PayrollRegistrationIdentitySnapshotRepository $repository,
        private PayrollRegistrationIdentityService $identities,
        private PayrollRegistrationIdentitySnapshotBuilder $builder,
        private PayrollSensitiveData $sensitiveData,
        private SecretEncryption $encryption,
    ) {}

    /**
     * @return array{
     *   id:int,supplier_id:int,environment:string,submission_id:int,
     *   source_revision_id:int,employee_id:int,employment_id:int,
     *   agenda_code:string,effective_on:string,schema_reference:string,
     *   source_manifest_hash:string,snapshot_fingerprint:string,
     *   request_fingerprint:string,workflow_status:string,
     *   official_submission_supported:false,created:bool
     * }
     */
    public function freeze(
        int $supplierId,
        int $submissionId,
        int $sourceRevisionId,
        int $employeeId,
        int $employmentId,
        string $environment,
        string $effectiveOn,
        string $idempotencyKey,
        ?int $createdBy,
    ): array {
        foreach ([
            'Firma' => $supplierId,
            'Podání' => $submissionId,
            'Zdrojová revize' => $sourceRevisionId,
            'Osoba' => $employeeId,
            'Pracovní vztah' => $employmentId,
        ] as $label => $value) {
            if ($value <= 0) {
                throw new \InvalidArgumentException(
                    "{$label} musí být kladné číslo.",
                );
            }
        }
        if ($createdBy !== null && $createdBy <= 0) {
            throw new \InvalidArgumentException(
                'Uživatel snapshotu musí být kladné číslo.',
            );
        }
        if (!in_array($environment, ['production', 'test'], true)) {
            throw new PayrollRegistrationIdentitySnapshotException(
                'registration_identity_environment_invalid',
                'Prostředí snapshotu registrační identity není platné.',
            );
        }
        $this->date($effectiveOn, 'Rozhodné datum');
        $idempotencyKey = trim($idempotencyKey);
        if ($idempotencyKey === '' || strlen($idempotencyKey) > 190) {
            throw new \InvalidArgumentException(
                'Idempotency klíč snapshotu musí mít 1 až 190 bajtů.',
            );
        }
        $idempotencyHash = hash('sha256', $idempotencyKey, true);

        return $this->repository->transaction(function () use (
            $supplierId,
            $submissionId,
            $sourceRevisionId,
            $employeeId,
            $employmentId,
            $environment,
            $effectiveOn,
            $idempotencyHash,
            $createdBy,
        ): array {
            $idempotent = $this->repository->findByIdempotencyForUpdate(
                $supplierId,
                $environment,
                $idempotencyHash,
            );
            if ($idempotent !== null) {
                if (!$this->isExactReplayScope(
                    $idempotent,
                    $supplierId,
                    $submissionId,
                    $sourceRevisionId,
                    $employeeId,
                    $employmentId,
                    $environment,
                    $effectiveOn,
                )) {
                    throw new PayrollRegistrationIdentitySnapshotException(
                        'registration_identity_idempotent_replay_scope_mismatch',
                        'Idempotentní opakování neodpovídá původnímu rozsahu snapshotu.',
                    );
                }
                $this->verifyStoredSnapshot($idempotent);

                return $this->result($idempotent, false);
            }
            $existing = $this->repository->findByScopeForUpdate(
                $supplierId,
                $environment,
                $submissionId,
                $sourceRevisionId,
                $employmentId,
            );
            if ($existing !== null) {
                throw new PayrollRegistrationIdentitySnapshotException(
                    'registration_identity_scope_already_frozen',
                    'Tento rozsah už má snapshot pod jiným idempotency klíčem.',
                );
            }
            $registrationScope = $this->repository->lockScope(
                $supplierId,
                $submissionId,
                $sourceRevisionId,
                $employeeId,
                $employmentId,
            );
            if ($registrationScope === null) {
                throw new PayrollRegistrationIdentitySnapshotException(
                    'registration_identity_submission_scope_mismatch',
                    'Podání a zdrojová revize nebyly nalezeny ve stejné firmě.',
                );
            }
            $agenda = $registrationScope['agenda_code'];
            if ($registrationScope['environment'] !== $environment
                || !in_array($agenda, ['PREZEC26', 'REGZEC25'], true)
                || $registrationScope['subject_type'] !== 'employment'
                || $registrationScope['subject_reference']
                    !== "employment:{$employmentId}"
            ) {
                throw new PayrollRegistrationIdentitySnapshotException(
                    'registration_identity_submission_scope_mismatch',
                    'Podání neodpovídá prostředí, agendě nebo pracovnímu vztahu.',
                );
            }
            if ($registrationScope['period_start'] !== $effectiveOn
                || $registrationScope['period_end'] !== $effectiveOn
            ) {
                throw new PayrollRegistrationIdentitySnapshotException(
                    'registration_identity_effective_date_mismatch',
                    'Rozhodné datum musí přesně odpovídat jednodennímu období registrační události.',
                );
            }
            if ($registrationScope['revision_status'] !== 'approved'
                || $registrationScope['revision_no']
                    !== $registrationScope['current_revision_no']
            ) {
                throw new PayrollRegistrationIdentitySnapshotException(
                    'registration_identity_revision_not_current_approved',
                    'Snapshot vyžaduje aktuální schválenou zdrojovou revizi.',
                );
            }
            if ($registrationScope['submission_status'] !== 'draft') {
                throw new PayrollRegistrationIdentitySnapshotException(
                    'registration_identity_submission_not_draft',
                    'Nový snapshot lze zmrazit pouze v konceptu podání.',
                );
            }

            $snapshot = $this->builder->build(
                [
                    'supplier_id' => $supplierId,
                    'submission_id' => $submissionId,
                    'source_revision_id' => $sourceRevisionId,
                    'employee_id' => $employeeId,
                    'employment_id' => $employmentId,
                    'environment' => $environment,
                    'agenda_code' => $agenda,
                    'effective_on' => $effectiveOn,
                ],
                $this->identities->sensitiveSnapshotSourceAt(
                    $supplierId,
                    $employeeId,
                    $employmentId,
                    $environment,
                    $effectiveOn,
                ),
            );
            $snapshotJson = $snapshot->canonicalJson();
            $snapshotFingerprint = $this->sensitiveData->keyedFingerprint(
                $snapshotJson,
                'registration-identity-snapshot',
                $supplierId,
            );
            $manifestJson = CanonicalJson::encode([
                'schema_reference' =>
                    'payroll-registration-identity-source-manifest.v1',
                'scope' => $snapshot->scope,
                'source_revision_input_hash' =>
                    $registrationScope['revision_input_hash'],
                'source_versions' => $snapshot->sourceVersions,
                'snapshot_fingerprint' => $snapshotFingerprint,
            ]);
            $manifestHash = hash('sha256', $manifestJson);
            $requestFingerprint = hash(
                'sha256',
                CanonicalJson::encode([
                    'schema_reference' =>
                        'payroll-registration-identity-freeze-request.v1',
                    'scope' => $snapshot->scope,
                    'source_manifest_hash' => $manifestHash,
                    'snapshot_fingerprint' => $snapshotFingerprint,
                ]),
            );
            $ciphertext = $this->encryption->encryptFor(
                $snapshotJson,
                $this->encryptionContext(
                    $snapshot->scope,
                    $snapshotFingerprint,
                    $manifestHash,
                ),
            );
            $id = $this->repository->insert([
                'supplier_id' => $supplierId,
                'environment' => $environment,
                'submission_id' => $submissionId,
                'source_revision_id' => $sourceRevisionId,
                'employee_id' => $employeeId,
                'employment_id' => $employmentId,
                'agenda_code' => $agenda,
                'effective_on' => $effectiveOn,
                'schema_reference' =>
                    PayrollRegistrationIdentitySnapshot::SCHEMA_REFERENCE,
                'source_manifest_json' => $manifestJson,
                'source_manifest_hash' => $manifestHash,
                'snapshot_ciphertext' => $ciphertext,
                'snapshot_fingerprint' => $snapshotFingerprint,
                'request_fingerprint' => $requestFingerprint,
                'idempotency_key_hash' => $idempotencyHash,
                'created_by' => $createdBy,
            ]);
            $stored = $this->repository->find(
                $supplierId,
                $id,
                $environment,
            );
            if ($stored === null) {
                throw new \RuntimeException(
                    'Uložený snapshot registrační identity nelze načíst.',
                );
            }
            $this->verifyStoredSnapshot($stored);

            return $this->result($stored, true);
        });
    }

    /**
     * Interní citlivé čtení pro budoucí builder. Není určeno pro listovací API.
     *
     * @return array<string,mixed>
     */
    public function sensitiveSnapshot(
        int $supplierId,
        int $snapshotId,
        string $environment,
    ): array {
        if ($supplierId <= 0 || $snapshotId <= 0) {
            throw new \InvalidArgumentException(
                'Rozsah snapshotu identity není platný.',
            );
        }
        if (!in_array($environment, ['production', 'test'], true)) {
            throw new PayrollRegistrationIdentitySnapshotException(
                'registration_identity_environment_invalid',
                'Prostředí snapshotu registrační identity není platné.',
            );
        }
        $stored = $this->repository->find(
            $supplierId,
            $snapshotId,
            $environment,
        );
        if ($stored === null) {
            throw new \OutOfBoundsException(
                'Snapshot registrační identity nebyl nalezen.',
            );
        }

        return $this->verifyStoredSnapshot($stored);
    }

    /**
     * @param array{
     *   id:int,supplier_id:int,environment:string,submission_id:int,
     *   source_revision_id:int,employee_id:int,employment_id:int,
     *   agenda_code:string,effective_on:string,schema_reference:string,
     *   source_manifest_json:string,source_manifest_hash:string,
     *   snapshot_ciphertext:string,snapshot_fingerprint:string,
     *   request_fingerprint:string,idempotency_key_hash:string,
     *   created_by:?int,created_at:string
     * } $stored
     * @return array<string,mixed>
     */
    private function verifyStoredSnapshot(array $stored): array
    {
        if ($stored['schema_reference']
            !== PayrollRegistrationIdentitySnapshot::SCHEMA_REFERENCE
        ) {
            throw new \RuntimeException(
                'Snapshot identity má nepodporované schéma.',
            );
        }
        if (!hash_equals(
            $stored['source_manifest_hash'],
            hash('sha256', $stored['source_manifest_json']),
        )) {
            throw new \RuntimeException(
                'Veřejný manifest snapshotu identity má neplatný otisk.',
            );
        }
        $manifest = $this->canonicalObject(
            $stored['source_manifest_json'],
            'manifestu snapshotu identity',
        );
        $scope = $this->storedScope($stored);
        $manifestScope = $this->object(
            $manifest['scope'] ?? null,
            'Rozsah manifestu snapshotu identity',
        );
        $manifestSourceVersions = $this->object(
            $manifest['source_versions'] ?? null,
            'Zdrojové verze manifestu snapshotu identity',
        );
        $manifestRevisionHash =
            $manifest['source_revision_input_hash'] ?? null;
        $storedRevisionHash = $this->repository->revisionInputHash(
            $stored['supplier_id'],
            $stored['source_revision_id'],
        );
        if (($manifest['schema_reference'] ?? null)
                !== 'payroll-registration-identity-source-manifest.v1'
            || CanonicalJson::encode($manifestScope)
                !== CanonicalJson::encode($scope)
            || ($manifest['snapshot_fingerprint'] ?? null)
                !== $stored['snapshot_fingerprint']
            || !is_string($manifestRevisionHash)
            || preg_match('/^[a-f0-9]{64}$/D', $manifestRevisionHash) !== 1
            || $storedRevisionHash === null
            || !hash_equals($storedRevisionHash, $manifestRevisionHash)
        ) {
            throw new \RuntimeException(
                'Manifest snapshotu identity neodpovídá archivním metadatům.',
            );
        }
        $plaintext = $this->encryption->decryptFor(
            $stored['snapshot_ciphertext'],
            $this->encryptionContext(
                $scope,
                $stored['snapshot_fingerprint'],
                $stored['source_manifest_hash'],
            ),
        );
        $snapshot = $this->canonicalObject(
            $plaintext,
            'citlivého snapshotu identity',
        );
        $fingerprint = $this->sensitiveData->keyedFingerprint(
            $plaintext,
            'registration-identity-snapshot',
            $stored['supplier_id'],
        );
        $snapshotScope = $this->object(
            $snapshot['scope'] ?? null,
            'Rozsah citlivého snapshotu identity',
        );
        $officialSubmission = $this->object(
            $snapshot['official_submission'] ?? null,
            'Stav oficiálního podání snapshotu identity',
        );
        $snapshotSourceVersions = $this->object(
            $snapshot['source_versions'] ?? null,
            'Zdrojové verze citlivého snapshotu identity',
        );
        if (!hash_equals($stored['snapshot_fingerprint'], $fingerprint)
            || ($snapshot['schema_reference'] ?? null)
                !== PayrollRegistrationIdentitySnapshot::SCHEMA_REFERENCE
            || CanonicalJson::encode($snapshotScope)
                !== CanonicalJson::encode($scope)
            || ($officialSubmission['supported'] ?? null)
                !== false
            || CanonicalJson::encode($snapshotSourceVersions)
                !== CanonicalJson::encode($manifestSourceVersions)
        ) {
            throw new \RuntimeException(
                'Citlivý snapshot identity neodpovídá archivním metadatům.',
            );
        }
        $expectedRequest = hash(
            'sha256',
            CanonicalJson::encode([
                'schema_reference' =>
                    'payroll-registration-identity-freeze-request.v1',
                'scope' => $scope,
                'source_manifest_hash' => $stored['source_manifest_hash'],
                'snapshot_fingerprint' =>
                    $stored['snapshot_fingerprint'],
            ]),
        );
        if (!hash_equals(
            $stored['request_fingerprint'],
            $expectedRequest,
        )) {
            throw new \RuntimeException(
                'Request fingerprint snapshotu identity nesouhlasí.',
            );
        }

        return $snapshot;
    }

    /**
     * @param array{
     *   supplier_id:int,submission_id:int,source_revision_id:int,
     *   employee_id:int,employment_id:int,environment:string,
     *   agenda_code:string,effective_on:string
     * } $scope
     */
    private function encryptionContext(
        array $scope,
        string $snapshotFingerprint,
        string $sourceManifestHash,
    ): string {
        return implode('|', [
            'payroll-registration-identity-snapshot.v1',
            (string) $scope['supplier_id'],
            $scope['environment'],
            (string) $scope['submission_id'],
            (string) $scope['source_revision_id'],
            (string) $scope['employee_id'],
            (string) $scope['employment_id'],
            $snapshotFingerprint,
            $sourceManifestHash,
        ]);
    }

    /**
     * @param array{
     *   id:int,supplier_id:int,environment:string,submission_id:int,
     *   source_revision_id:int,employee_id:int,employment_id:int,
     *   agenda_code:string,effective_on:string,schema_reference:string,
     *   source_manifest_json:string,source_manifest_hash:string,
     *   snapshot_ciphertext:string,snapshot_fingerprint:string,
     *   request_fingerprint:string,idempotency_key_hash:string,
     *   created_by:?int,created_at:string
     * } $stored
     */
    private function isExactReplayScope(
        array $stored,
        int $supplierId,
        int $submissionId,
        int $sourceRevisionId,
        int $employeeId,
        int $employmentId,
        string $environment,
        string $effectiveOn,
    ): bool {
        return $stored['supplier_id'] === $supplierId
            && $stored['submission_id'] === $submissionId
            && $stored['source_revision_id'] === $sourceRevisionId
            && $stored['employee_id'] === $employeeId
            && $stored['employment_id'] === $employmentId
            && $stored['environment'] === $environment
            && $stored['effective_on'] === $effectiveOn;
    }

    /**
     * @param array{
     *   id:int,supplier_id:int,environment:string,submission_id:int,
     *   source_revision_id:int,employee_id:int,employment_id:int,
     *   agenda_code:string,effective_on:string,schema_reference:string,
     *   source_manifest_json:string,source_manifest_hash:string,
     *   snapshot_ciphertext:string,snapshot_fingerprint:string,
     *   request_fingerprint:string,idempotency_key_hash:string,
     *   created_by:?int,created_at:string
     * } $stored
     * @return array{
     *   supplier_id:int,submission_id:int,source_revision_id:int,
     *   employee_id:int,employment_id:int,environment:string,
     *   agenda_code:string,effective_on:string
     * }
     */
    private function storedScope(array $stored): array
    {
        return [
            'supplier_id' => $stored['supplier_id'],
            'submission_id' => $stored['submission_id'],
            'source_revision_id' => $stored['source_revision_id'],
            'employee_id' => $stored['employee_id'],
            'employment_id' => $stored['employment_id'],
            'environment' => $stored['environment'],
            'agenda_code' => $stored['agenda_code'],
            'effective_on' => $stored['effective_on'],
        ];
    }

    /** @return array<string,mixed> */
    private function canonicalObject(string $json, string $label): array
    {
        try {
            $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \RuntimeException(
                "Kanonický JSON {$label} není platný.",
                previous: $exception,
            );
        }
        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new \RuntimeException(
                "Kanonický JSON {$label} není objekt.",
            );
        }
        $object = [];
        foreach ($decoded as $key => $value) {
            if (!is_string($key)) {
                throw new \RuntimeException(
                    "Kanonický JSON {$label} má neplatný klíč.",
                );
            }
            $object[$key] = $value;
        }
        if (CanonicalJson::encode($object) !== $json) {
            throw new \RuntimeException(
                "JSON {$label} není kanonický.",
            );
        }

        return $object;
    }

    /** @return array<string,mixed> */
    private function object(mixed $value, string $label): array
    {
        if (!is_array($value) || array_is_list($value)) {
            throw new \RuntimeException("{$label} není objekt.");
        }
        $object = [];
        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                throw new \RuntimeException(
                    "{$label} má neplatný klíč.",
                );
            }
            $object[$key] = $item;
        }

        return $object;
    }

    /**
     * @param array{
     *   id:int,supplier_id:int,environment:string,submission_id:int,
     *   source_revision_id:int,employee_id:int,employment_id:int,
     *   agenda_code:string,effective_on:string,schema_reference:string,
     *   source_manifest_json:string,source_manifest_hash:string,
     *   snapshot_ciphertext:string,snapshot_fingerprint:string,
     *   request_fingerprint:string,idempotency_key_hash:string,
     *   created_by:?int,created_at:string
     * } $stored
     * @return array{
     *   id:int,supplier_id:int,environment:string,submission_id:int,
     *   source_revision_id:int,employee_id:int,employment_id:int,
     *   agenda_code:string,effective_on:string,schema_reference:string,
     *   source_manifest_hash:string,snapshot_fingerprint:string,
     *   request_fingerprint:string,workflow_status:string,
     *   official_submission_supported:false,created:bool
     * }
     */
    private function result(array $stored, bool $created): array
    {
        return [
            'id' => $stored['id'],
            'supplier_id' => $stored['supplier_id'],
            'environment' => $stored['environment'],
            'submission_id' => $stored['submission_id'],
            'source_revision_id' => $stored['source_revision_id'],
            'employee_id' => $stored['employee_id'],
            'employment_id' => $stored['employment_id'],
            'agenda_code' => $stored['agenda_code'],
            'effective_on' => $stored['effective_on'],
            'schema_reference' => $stored['schema_reference'],
            'source_manifest_hash' => $stored['source_manifest_hash'],
            'snapshot_fingerprint' => $stored['snapshot_fingerprint'],
            'request_fingerprint' => $stored['request_fingerprint'],
            'workflow_status' => 'identity_frozen_only',
            'official_submission_supported' => false,
            'created' => $created,
        ];
    }

    private function date(string $value, string $field): void
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new \InvalidArgumentException(
                "{$field} není platné datum.",
            );
        }
    }
}

