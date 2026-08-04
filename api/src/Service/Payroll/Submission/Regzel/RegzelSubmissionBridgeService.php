<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Regzel;

use MyInvoice\Repository\Payroll\PayrollSubmissionRepository;
use MyInvoice\Repository\Payroll\RegzelSubmissionBridgeRepository;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Submission\PayrollSubmissionService;

final readonly class RegzelSubmissionBridgeService
{
    public const SOURCE_EVENT_TYPE = 'regzel_payload_snapshot';
    public const AGENDA_CODE = 'REGZELDOPL25';
    private const CHANNEL = 'manual_upload';

    public function __construct(
        private RegzelSubmissionPayloadAssembler $assembler,
        private RegzelSubmissionBridgeRepository $bridgeRepository,
        private PayrollSubmissionRepository $submissionRepository,
        private PayrollSubmissionService $submissions,
    ) {}

    /**
     * @return array{
     *   submission_id:int,part_id:int,artifact_id:int,
     *   status:string,row_version:int,environment:string,
     *   source_snapshot_hash:string,artifact_sha256:string,created:bool
     * }
     */
    public function bridge(
        int $supplierId,
        int $snapshotId,
        int $obligationId,
        string $environment,
        ?int $createdBy = null,
    ): array {
        if ($supplierId <= 0
            || $snapshotId <= 0
            || $obligationId <= 0
            || ($createdBy !== null && $createdBy <= 0)
        ) {
            throw new \InvalidArgumentException(
                'Rozsah REGZEL bridge není platný.',
            );
        }
        $payload = $this->assembler->assemble(
            $supplierId,
            $snapshotId,
            $environment,
        );
        $keys = $this->idempotencyKeys($payload, $obligationId);

        return $this->submissionRepository->transaction(function () use (
            $supplierId,
            $snapshotId,
            $obligationId,
            $environment,
            $createdBy,
            $payload,
            $keys,
        ): array {
            if (!$this->submissionRepository->lockSupplier($supplierId)) {
                throw new \DomainException(
                    'Firma REGZEL podání nebyla nalezena.',
                );
            }
            $obligation = $this->bridgeRepository->lockVerifiedObligation(
                $supplierId,
                $obligationId,
                $environment,
            );
            $this->assertObligation(
                $obligation,
                $payload,
                $snapshotId,
            );

            $submission = $this->submissions->prepare(
                $supplierId,
                $obligationId,
                'regular',
                self::CHANNEL,
                $payload->sourceSnapshotHash,
                $keys['submission'],
                null,
                null,
                $createdBy,
                $environment,
            );
            if (!$submission['created']) {
                return $this->replayedResult(
                    $supplierId,
                    $payload,
                    $submission,
                    $keys['artifact'],
                );
            }

            $part = $this->submissions->addPart(
                $supplierId,
                $submission['id'],
                $submission['row_version'],
                "regzeldopl25:{$snapshotId}",
                self::AGENDA_CODE,
                self::officeReference($payload->officeId),
                'regzel_snapshot',
                self::sourceEventReference($snapshotId),
                $payload->sourceSnapshotHash,
            );
            $artifact = $this->submissions->storeArtifact(
                $supplierId,
                $submission['id'],
                $part['submission_row_version'],
                $part['id'],
                'outbound_xml',
                'outbound',
                'application/xml',
                $payload->xml,
                $payload->xsdVersion,
                $payload->mappingVersion,
                self::CHANNEL,
                $keys['artifact'],
                $createdBy,
            );
            if (!hash_equals(
                $payload->xmlSha256,
                $artifact['artifact_sha256'],
            )) {
                throw new \UnexpectedValueException(
                    'Otisk MZ-19 artefaktu neodpovídá přesnému REGZEL XML.',
                );
            }
            $validated = $this->submissions->transition(
                $supplierId,
                $submission['id'],
                $artifact['submission_row_version'],
                'validated',
            );
            $ready = $this->submissions->transition(
                $supplierId,
                $submission['id'],
                $validated['row_version'],
                'ready',
            );

            return [
                'submission_id' => $submission['id'],
                'part_id' => $part['id'],
                'artifact_id' => $artifact['id'],
                'status' => $ready['status'],
                'row_version' => $ready['row_version'],
                'environment' => $payload->environment,
                'source_snapshot_hash' => $payload->sourceSnapshotHash,
                'artifact_sha256' => $artifact['artifact_sha256'],
                'created' => true,
            ];
        });
    }

    public static function sourceEventReference(int $snapshotId): string
    {
        if ($snapshotId <= 0) {
            throw new \InvalidArgumentException(
                'REGZEL snapshot musí být kladné číslo.',
            );
        }

        return "regzel_snapshot:{$snapshotId}";
    }

    public static function officeReference(int $officeId): string
    {
        if ($officeId <= 0) {
            throw new \InvalidArgumentException(
                'Mzdová účtárna musí být kladné číslo.',
            );
        }

        return "office:{$officeId}";
    }

    /**
     * @param array<string,mixed>|null $obligation
     */
    private function assertObligation(
        ?array $obligation,
        RegzelSubmissionPayload $payload,
        int $snapshotId,
    ): void {
        if ($obligation === null) {
            throw new RegzelValidationException(
                'regzel_verified_obligation_required',
                'REGZEL podání vyžaduje předem evidovanou ověřenou povinnost a lhůtu.',
            );
        }
        if (($obligation['agenda_code'] ?? null) !== self::AGENDA_CODE
            || ($obligation['subject_type'] ?? null) !== 'office'
            || ($obligation['subject_reference'] ?? null)
                !== self::officeReference($payload->officeId)
            || ($obligation['obligation_kind'] ?? null) !== 'regular'
            || ($obligation['preferred_channel'] ?? null) !== self::CHANNEL
            || !in_array(
                $obligation['status'] ?? null,
                ['open', 'prepared'],
                true,
            )
            || ($obligation['source_event_type'] ?? null)
                !== self::SOURCE_EVENT_TYPE
            || ($obligation['source_event_reference'] ?? null)
                !== self::sourceEventReference($snapshotId)
            || !is_string($obligation['source_event_hash'] ?? null)
            || !hash_equals(
                $payload->sourceSnapshotHash,
                $obligation['source_event_hash'],
            )
            || ($obligation['deadline_kind'] ?? null) !== 'regular'
            || !is_string($obligation['trigger_event_hash'] ?? null)
            || !hash_equals(
                $payload->sourceSnapshotHash,
                $obligation['trigger_event_hash'],
            )
        ) {
            throw new RegzelValidationException(
                'regzel_obligation_scope_mismatch',
                'Ověřená povinnost REGZEL neodpovídá snapshotu, účtárně, prostředí nebo bezpečnému kanálu.',
            );
        }
    }

    /**
     * @param array{
     *   id:int,status:string,row_version:int,request_fingerprint:string,
     *   source_snapshot_hash:string,submission_kind:string,channel:string,
     *   environment:string,obligation_id:int,corrects_submission_id:?int,
     *   correlation_reference:?string,created:bool
     * } $submission
     * @return array{
     *   submission_id:int,part_id:int,artifact_id:int,
     *   status:string,row_version:int,environment:string,
     *   source_snapshot_hash:string,artifact_sha256:string,created:bool
     * }
     */
    private function replayedResult(
        int $supplierId,
        RegzelSubmissionPayload $payload,
        array $submission,
        string $artifactKey,
    ): array {
        if ($submission['status'] !== 'ready') {
            throw new RegzelValidationException(
                'regzel_submission_replay_state_invalid',
                'Existující REGZEL podání už není v idempotentním stavu ready.',
            );
        }
        $artifact = $this->submissionRepository
            ->findArtifactByIdempotencyForUpdate(
                $supplierId,
                hash('sha256', $artifactKey, true),
                $payload->environment,
            );
        if ($artifact === null
            || $artifact['submission_id'] !== $submission['id']
            || $artifact['part_id'] === null
            || $artifact['artifact_kind'] !== 'outbound_xml'
            || $artifact['direction'] !== 'outbound'
            || $artifact['mime_type'] !== 'application/xml'
            || $artifact['xsd_version'] !== $payload->xsdVersion
            || $artifact['catalog_version'] !== $payload->mappingVersion
            || $artifact['channel'] !== self::CHANNEL
            || $artifact['byte_size'] !== strlen($payload->xml)
            || !hash_equals(
                $payload->xmlSha256,
                $artifact['artifact_sha256'],
            )
            || !hash_equals(
                $payload->xml,
                $this->submissions->artifactBytes(
                    $supplierId,
                    $artifact['id'],
                ),
            )
        ) {
            throw new RegzelValidationException(
                'regzel_submission_replay_mismatch',
                'Existující MZ-19 artefakt neodpovídá přesnému REGZEL XML.',
            );
        }

        return [
            'submission_id' => $submission['id'],
            'part_id' => $artifact['part_id'],
            'artifact_id' => $artifact['id'],
            'status' => $submission['status'],
            'row_version' => $submission['row_version'],
            'environment' => $payload->environment,
            'source_snapshot_hash' => $payload->sourceSnapshotHash,
            'artifact_sha256' => $artifact['artifact_sha256'],
            'created' => false,
        ];
    }

    /**
     * @return array{submission:string,artifact:string}
     */
    private function idempotencyKeys(
        RegzelSubmissionPayload $payload,
        int $obligationId,
    ): array {
        $fingerprint = hash(
            'sha256',
            CanonicalJson::encode([
                'schema_reference' => 'payroll-regzel-mz19-bridge.v1',
                'supplier_id' => $payload->supplierId,
                'environment' => $payload->environment,
                'snapshot_id' => $payload->snapshotId,
                'obligation_id' => $obligationId,
                'source_snapshot_hash' => $payload->sourceSnapshotHash,
                'xml_sha256' => $payload->xmlSha256,
            ]),
        );

        return [
            'submission' => "regzel-mz19-submission:{$fingerprint}",
            'artifact' => "regzel-mz19-artifact:{$fingerprint}",
        ];
    }
}
