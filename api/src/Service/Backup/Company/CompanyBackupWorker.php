<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Backup\Registry\IncompleteTenantDataRegistry;
use MyInvoice\Service\Backup\Registry\IncompleteTenantDataRegistryCoverage;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/** Vykoná přesně jeden perzistentní job a uzavře všechny chybové větve. */
final readonly class CompanyBackupWorker
{
    private const TOTAL_STEPS = 3;
    private const USER_AGENT = 'company-backup-worker';

    public function __construct(
        private CompanyBackupJobLifecycle $jobs,
        private CompanyBackupExportPipeline $pipeline,
        private CompanyBackupJobRetentionPolicy $retention,
        private ClockInterface $clock,
        private ActivityLogger $activity,
        private LoggerInterface $logger,
    ) {}

    public function run(string $backupId): CompanyBackupJobStatus
    {
        if (!CompanyBackupManifestHeader::isCanonicalBackupId($backupId)) {
            throw new \InvalidArgumentException(
                'Identifikátor workeru zálohy firmy není platný.',
            );
        }
        $job = $this->jobs->findForWorker($backupId);
        if ($job === null) {
            throw new CompanyBackupJobException('job_not_found');
        }
        $initialStatus = self::status($job);
        if ($initialStatus !== CompanyBackupJobStatus::Queued) {
            return $initialStatus;
        }
        if (!$this->jobs->startChecking($backupId)) {
            return $this->statusAfterLostClaim($backupId, $job);
        }

        $password = null;
        $artifact = null;
        try {
            $this->progress($backupId, CompanyBackupJobStatus::Checking, 0);
            $this->pipeline->check($job);
            $this->throwIfCancelled($backupId);

            $password = $this->jobs->passwordForWorker($backupId);
            if (!$this->jobs->startSnapshotting($backupId)) {
                $this->throwIfCancelled($backupId);
                throw new CompanyBackupJobException('state_conflict');
            }
            $this->progress($backupId, CompanyBackupJobStatus::Snapshotting, 1);

            $artifact = $this->pipeline->export(
                $job,
                $password,
                function () use ($backupId): void {
                    $this->throwIfCancelled($backupId);
                    if (!$this->jobs->startPackaging($backupId)) {
                        $this->throwIfCancelled($backupId);
                        throw new CompanyBackupJobException('state_conflict');
                    }
                    $this->progress(
                        $backupId,
                        CompanyBackupJobStatus::Packaging,
                        2,
                    );
                },
            );

            if (!$this->jobs->complete(
                $backupId,
                $artifact,
                $this->clock->now(),
                $this->retention,
            )) {
                $this->pipeline->discard($artifact);
                $artifact = null;
                $this->throwIfCancelled($backupId);
                throw new CompanyBackupJobException('state_conflict');
            }

            $this->audit($job, 'company_backup.completed', [
                'backup_id' => $backupId,
                'sha256' => $artifact->sha256,
                'size_bytes' => $artifact->bytes,
                'entry_count' => $artifact->entryCount,
            ]);
            return CompanyBackupJobStatus::Completed;
        } catch (CompanyBackupWorkerCancelled) {
            if ($artifact !== null) {
                try {
                    $this->pipeline->discard($artifact);
                    $artifact = null;
                } catch (\Throwable $e) {
                    return $this->fail($backupId, $job, $e);
                }
            }
            return $this->cancel($backupId, $job);
        } catch (\Throwable $e) {
            if ($artifact !== null) {
                try {
                    $this->pipeline->discard($artifact);
                } catch (\Throwable $cleanupError) {
                    $e = new CompanyBackupJobException(
                        'artifact_cleanup_failed',
                        $cleanupError,
                    );
                }
            }
            if ($this->jobs->isCancelRequested($backupId)) {
                return $this->cancel($backupId, $job);
            }
            return $this->fail($backupId, $job, $e);
        } finally {
            if (is_string($password) && function_exists('sodium_memzero')) {
                sodium_memzero($password);
            }
        }
    }

    /** @param array<string,mixed> $job */
    private function statusAfterLostClaim(
        string $backupId,
        array $job,
    ): CompanyBackupJobStatus {
        $fresh = $this->jobs->findForWorker($backupId);
        if ($fresh === null) {
            throw new CompanyBackupJobException('job_not_found');
        }
        $status = self::status($fresh);
        if ($status->isProcessing()
            && (bool) ($fresh['cancel_requested'] ?? false)
        ) {
            return $this->cancel($backupId, $job);
        }
        return $status;
    }

    private function progress(
        string $backupId,
        CompanyBackupJobStatus $status,
        int $step,
    ): void {
        if (!$this->jobs->updateProgress(
            $backupId,
            $status,
            $step,
            self::TOTAL_STEPS,
        )) {
            throw new CompanyBackupJobException('state_conflict');
        }
    }

    private function throwIfCancelled(string $backupId): void
    {
        if ($this->jobs->isCancelRequested($backupId)) {
            throw new CompanyBackupWorkerCancelled();
        }
    }

    /** @param array<string,mixed> $job */
    private function cancel(
        string $backupId,
        array $job,
    ): CompanyBackupJobStatus {
        if ($this->jobs->markCancelled($backupId)) {
            $this->audit($job, 'company_backup.cancelled', [
                'backup_id' => $backupId,
            ]);
            return CompanyBackupJobStatus::Cancelled;
        }
        return $this->currentStatus($backupId);
    }

    /** @param array<string,mixed> $job */
    private function fail(
        string $backupId,
        array $job,
        \Throwable $error,
    ): CompanyBackupJobStatus {
        $code = self::errorCode($error);
        $type = get_debug_type($error);
        $separator = strrpos($type, '\\');
        if ($separator !== false) {
            $type = substr($type, $separator + 1);
        }
        $changed = $this->jobs->markFailed(
            $backupId,
            $code,
            $type . ': ' . $code,
        );
        if ($changed) {
            $this->audit($job, 'company_backup.failed', [
                'backup_id' => $backupId,
                'error_code' => $code,
            ]);
        }
        $this->logger->error(
            'Worker zálohy firmy skončil bezpečnou chybou.',
            [
                'backup_id' => $backupId,
                'error_code' => $code,
                'exception' => $error,
            ],
        );
        return $changed
            ? CompanyBackupJobStatus::Failed
            : $this->currentStatus($backupId);
    }

    private function currentStatus(string $backupId): CompanyBackupJobStatus
    {
        $job = $this->jobs->findForWorker($backupId);
        if ($job === null) {
            throw new CompanyBackupJobException('job_not_found');
        }
        return self::status($job);
    }

    private static function errorCode(\Throwable $error): string
    {
        $code = match (true) {
            $error instanceof CompanyBackupArchiveWriteException,
            $error instanceof CompanyBackupArchiveException,
            $error instanceof CompanyBackupDataSourceException,
            $error instanceof CompanyBackupFormatException,
            $error instanceof CompanyBackupJobException,
            $error instanceof CompanyBackupSecretEnvelopeException,
            $error instanceof CompanyBackupSecretPayloadException,
            $error instanceof CompanyBackupSnapshotException,
            $error instanceof CompanyBackupTechnicalValidationException =>
                $error->errorCode,
            $error instanceof IncompleteTenantDataRegistry => 'registry_incomplete',
            $error instanceof IncompleteTenantDataRegistryCoverage =>
                'registry_coverage_incomplete',
            default => 'worker_failed',
        };
        return preg_match('/^[a-z][a-z0-9_]{2,63}$/D', $code) === 1
            ? $code
            : 'worker_failed';
    }

    /**
     * @param array<string,mixed> $job
     * @param array<string,mixed> $payload
     */
    private function audit(array $job, string $action, array $payload): void
    {
        $supplierId = (int) ($job['supplier_id'] ?? 0);
        $userId = (int) ($job['created_by'] ?? 0);
        try {
            $this->activity->log(
                $action,
                $userId > 0 ? $userId : null,
                'supplier',
                $supplierId > 0 ? $supplierId : null,
                $payload,
                null,
                self::USER_AGENT,
                $supplierId > 0 ? $supplierId : null,
            );
        } catch (\Throwable $e) {
            // Hotový či terminální job se kvůli výpadku auditu nesmí vrátit do
            // zpracování ani přijít o archiv; provozní log chybu zviditelní.
            $this->logger->error(
                'Audit workeru zálohy firmy selhal.',
                [
                    'backup_id' => $payload['backup_id'] ?? null,
                    'action' => $action,
                    'exception' => $e,
                ],
            );
        }
    }

    /** @param array<string,mixed> $job */
    private static function status(array $job): CompanyBackupJobStatus
    {
        return CompanyBackupJobStatus::tryFrom((string) ($job['status'] ?? ''))
            ?? throw new CompanyBackupJobException('job_state_invalid');
    }
}
