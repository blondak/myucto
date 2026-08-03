<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Document;

use MyInvoice\Repository\Payroll\PayrollDocumentRepository;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;

final class PayrollDocumentService
{
    public function __construct(
        private readonly PayrollDocumentRepository $documents,
        private readonly PayrollDocumentStorage $storage,
        private readonly PayslipPdfRenderer $payslips,
        private readonly MonthlyPayrollBundleBuilder $bundles,
    ) {}

    /** @return array<string,mixed> */
    public function generatePayslip(
        int $supplierId,
        int $runId,
        int $revisionId,
        int $employeeId,
        PayslipDocumentData $data,
        string $idempotencyKey,
        ?int $actorUserId,
        ?int $supersedesDocumentId = null,
    ): array {
        $rendered = $this->payslips->render($data);
        return $this->archive(
            $supplierId,
            $runId,
            $revisionId,
            $employeeId,
            new PayrollArtifact(
                PayrollDocumentKind::Payslip,
                $rendered->pdfBytes,
                $rendered->mimeType,
                $rendered->suggestedFilename,
                $rendered->sourceSnapshotSha256,
                PayslipPdfRenderer::VERSION,
                $rendered->rendererVersion,
            ),
            $idempotencyKey,
            $actorUserId,
            $supersedesDocumentId,
        );
    }

    /** @return array<string,mixed> */
    public function archivePdf(
        int $supplierId,
        int $runId,
        int $revisionId,
        ?int $employeeId,
        PayrollDocumentKind $kind,
        string $pdfBytes,
        string $sourceSnapshotHash,
        string $templateVersion,
        string $rendererVersion,
        string $idempotencyKey,
        ?int $actorUserId,
        ?int $supersedesDocumentId = null,
    ): array {
        if (in_array($kind, [PayrollDocumentKind::Payslip, PayrollDocumentKind::MonthlyBundle], true)) {
            throw new \InvalidArgumentException('Use the dedicated payroll document generator.');
        }
        $filename = $kind->value . '-' . substr(hash('sha256', $pdfBytes), 0, 12) . '.pdf';
        return $this->archive(
            $supplierId,
            $runId,
            $revisionId,
            $employeeId,
            new PayrollArtifact(
                $kind,
                $pdfBytes,
                'application/pdf',
                $filename,
                $sourceSnapshotHash,
                $templateVersion,
                $rendererVersion,
            ),
            $idempotencyKey,
            $actorUserId,
            $supersedesDocumentId,
        );
    }

    /** @return array<string,mixed> */
    public function generateMonthlyBundle(
        int $supplierId,
        int $runId,
        int $revisionId,
        string $idempotencyKey,
        ?int $actorUserId,
    ): array {
        if ($idempotencyKey === '' || strlen($idempotencyKey) > 200) {
            throw new \InvalidArgumentException('Payroll document idempotency key is invalid.');
        }
        $revision = $this->requireApprovedRevision($supplierId, $runId, $revisionId);
        $entries = [];
        foreach ($this->documents->forRevision($supplierId, $revisionId) as $document) {
            $entries[] = new PayrollBundleEntry(
                (int) $document['id'],
                PayrollDocumentKind::from((string) $document['document_kind']),
                $this->storage->readVerified($supplierId, (string) $document['storage_key']),
                (string) $document['file_sha256'],
                (string) $document['mime_type'],
            );
        }
        $artifact = $this->bundles->build(
            substr((string) $revision['period_start'], 0, 7),
            (string) $revisionId,
            (string) $revision['result_snapshot_hash'],
            $entries,
        );
        $previous = $this->documents->latestForRevisionKind(
            $supplierId,
            $revisionId,
            null,
            PayrollDocumentKind::MonthlyBundle->value,
        );
        if (
            $previous !== null
            && hash_equals(
                (string) $previous['source_snapshot_hash'],
                $artifact->sourceSnapshotHash,
            )
        ) {
            return $previous;
        }
        return $this->archive(
            $supplierId,
            $runId,
            $revisionId,
            null,
            $artifact,
            'bundle:' . hash(
                'sha256',
                implode("\0", [
                    (string) $supplierId,
                    (string) $runId,
                    (string) $revisionId,
                    $artifact->sourceSnapshotHash,
                ]),
            ),
            $actorUserId,
            $previous === null ? null : (int) $previous['id'],
        );
    }

    /**
     * @return array{token:string,expires_at:string}
     */
    public function issueDownloadGrant(
        int $supplierId,
        int $documentId,
        int $userId,
        int $ttlSeconds = 300,
    ): array {
        if ($ttlSeconds < 30 || $ttlSeconds > 900 || $this->documents->find($supplierId, $documentId) === null) {
            throw new \InvalidArgumentException('Payroll document download grant is invalid.');
        }
        $token = bin2hex(random_bytes(32));
        $expiresAt = (new \DateTimeImmutable())
            ->modify("+{$ttlSeconds} seconds")
            ->format('Y-m-d H:i:s');
        $this->documents->createDownloadGrant(
            $supplierId,
            $documentId,
            $userId,
            hash('sha256', $token),
            $expiresAt,
        );
        return ['token' => $token, 'expires_at' => $expiresAt];
    }

    /**
     * @return array{
     *   revisions:list<array<string,mixed>>,
     *   items:list<array<string,mixed>>
     * }
     */
    public function listForPeriod(int $supplierId, string $period): array
    {
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D', $period) !== 1) {
            throw new \InvalidArgumentException('Payroll period must use YYYY-MM.');
        }
        $periodStart = $period . '-01';
        return [
            'revisions' => $this->documents->approvedRevisionsForPeriod(
                $supplierId,
                $periodStart,
            ),
            'items' => $this->documents->listForPeriod($supplierId, $periodStart),
        ];
    }

    /**
     * @return array{document:array<string,mixed>,bytes:string}
     */
    public function consumeDownload(
        int $supplierId,
        int $documentId,
        int $userId,
        string $token,
    ): array {
        if (
            preg_match('/^[a-f0-9]{64}$/D', $token) !== 1
            || !$this->documents->consumeDownloadGrant(
                $supplierId,
                $documentId,
                $userId,
                hash('sha256', $token),
            )
        ) {
            throw new \RuntimeException('Payroll document download grant is invalid or expired.');
        }
        $document = $this->documents->find($supplierId, $documentId)
            ?? throw new \RuntimeException('Payroll document was not found.');
        return [
            'document' => $document,
            'bytes' => $this->storage->readVerified(
                $supplierId,
                (string) $document['storage_key'],
            ),
        ];
    }

    /** @return array<string,mixed> */
    private function archive(
        int $supplierId,
        int $runId,
        int $revisionId,
        ?int $employeeId,
        PayrollArtifact $artifact,
        string $idempotencyKey,
        ?int $actorUserId,
        ?int $supersedesDocumentId = null,
    ): array {
        if ($idempotencyKey === '' || strlen($idempotencyKey) > 200) {
            throw new \InvalidArgumentException('Payroll document idempotency key is invalid.');
        }
        $idempotencyKeyHash = hash('sha256', $idempotencyKey);
        $existing = $this->documents->findByIdempotency(
            $supplierId,
            $idempotencyKeyHash,
        );
        if ($existing !== null) {
            if (
                (int) $existing['run_id'] !== $runId
                || (int) $existing['revision_id'] !== $revisionId
                || $existing['employee_id'] !== $employeeId
                || $existing['document_kind'] !== $artifact->kind->value
                || $existing['source_snapshot_hash'] !== $artifact->sourceSnapshotHash
            ) {
                throw new \RuntimeException(
                    'Payroll document idempotency key was reused for another request.',
                );
            }
            return $existing;
        }
        $revision = $this->requireApprovedRevision($supplierId, $runId, $revisionId);
        if (
            $employeeId !== null
            && !$this->documents->employeeBelongsToRevision(
                $supplierId,
                $revisionId,
                $employeeId,
            )
        ) {
            throw new \RuntimeException(
                'Payroll document employee is not part of the approved revision.',
            );
        }
        $latest = $this->documents->latestForRunKind(
            $supplierId,
            $runId,
            $employeeId,
            $artifact->kind->value,
        );
        if (
            $supersedesDocumentId === null
            && $latest !== null
            && (int) $latest['revision_id'] !== $revisionId
        ) {
            $supersedesDocumentId = (int) $latest['id'];
        }
        $documentRevisionNo = 1;
        if ($supersedesDocumentId !== null) {
            $previous = $this->documents->find($supplierId, $supersedesDocumentId);
            if (
                $previous === null
                || $latest === null
                || (int) $latest['id'] !== $supersedesDocumentId
                || $previous['document_kind'] !== $artifact->kind->value
                || $previous['employee_id'] !== $employeeId
                || (int) $previous['run_id'] !== $runId
            ) {
                throw new \RuntimeException('Superseded payroll document is incompatible.');
            }
            $sameRevision = (int) $previous['revision_id'] === $revisionId;
            if ($sameRevision && $artifact->kind !== PayrollDocumentKind::MonthlyBundle) {
                throw new \RuntimeException('Superseded payroll document is incompatible.');
            }
            if (!$sameRevision) {
                $previousRevision = $this->requireApprovedRevision(
                    $supplierId,
                    $runId,
                    (int) $previous['revision_id'],
                );
                if ((int) $previousRevision['revision_no'] >= (int) $revision['revision_no']) {
                    throw new \RuntimeException('Superseded payroll document revision is not older.');
                }
            }
            $documentRevisionNo = (int) $previous['document_revision_no'] + 1;
        }
        $stored = $this->storage->store($supplierId, $artifact->bytes);
        return $this->documents->insertOrGet([
            'supplier_id' => $supplierId,
            'run_id' => $runId,
            'revision_id' => $revisionId,
            'employee_id' => $employeeId,
            'document_kind' => $artifact->kind->value,
            'document_revision_no' => $documentRevisionNo,
            'supersedes_document_id' => $supersedesDocumentId,
            'source_snapshot_hash' => $artifact->sourceSnapshotHash,
            'revision_snapshot_hash' => $revision['result_snapshot_hash'],
            'template_version' => $artifact->templateVersion,
            'renderer_version' => $artifact->rendererVersion,
            'file_sha256' => $stored['file_sha256'],
            'size_bytes' => $stored['size_bytes'],
            'mime_type' => $artifact->mimeType,
            'storage_key' => $stored['storage_key'],
            'suggested_filename' => $artifact->suggestedFilename,
            'manifest_json' => $artifact->manifest === null
                ? null
                : CanonicalJson::encode($artifact->manifest),
            'idempotency_key_hash' => $idempotencyKeyHash,
            'created_by' => $actorUserId,
        ]);
    }

    /** @return array<string,mixed> */
    private function requireApprovedRevision(
        int $supplierId,
        int $runId,
        int $revisionId,
    ): array {
        return $this->documents->approvedRevision($supplierId, $runId, $revisionId)
            ?? throw new \RuntimeException('Only an approved payroll revision can produce documents.');
    }
}
