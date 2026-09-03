<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

use DateTimeImmutable;

/** Perzistentní port používaný bezpečnostní orchestrace a workerem. */
interface CompanyBackupJobLifecycle
{
    public function create(
        int $supplierId,
        int $createdBy,
        string $registryFingerprint,
        #[\SensitiveParameter] string $password,
    ): string;

    /** @return array<string,mixed>|null */
    public function findForWorker(string $backupId): ?array;

    public function passwordForWorker(string $backupId): string;

    public function startChecking(string $backupId): bool;

    public function startSnapshotting(string $backupId): bool;

    public function startPackaging(string $backupId): bool;

    public function updateProgress(
        string $backupId,
        CompanyBackupJobStatus $status,
        int $processedSteps,
        ?int $totalSteps,
    ): bool;

    public function isCancelRequested(string $backupId): bool;

    public function complete(
        string $backupId,
        CompanyBackupStoredArtifact $artifact,
        DateTimeImmutable $completedAt,
        CompanyBackupJobRetentionPolicy $retention,
    ): bool;

    public function markFailed(
        string $backupId,
        string $errorCode,
        string $message,
    ): bool;

    public function markCancelled(string $backupId): bool;
}
