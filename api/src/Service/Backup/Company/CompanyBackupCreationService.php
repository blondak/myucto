<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Auth\MfaProtectedOperationService;
use MyInvoice\Service\Auth\MfaStepUpService;
use MyInvoice\Service\Backup\Registry\IncompleteTenantDataRegistry;
use Psr\Log\LoggerInterface;

/** Založí, zaudituje a naplánuje export bez mezery mezi proofem a DB jobem. */
final readonly class CompanyBackupCreationService implements CompanyBackupCreator
{
    public function __construct(
        private CompanyBackupRegistrySnapshotProvider $registry,
        private MfaProtectedOperationService $protectedOperations,
        private CompanyBackupJobLifecycle $jobs,
        private CompanyBackupWorkerLauncher $launcher,
        private ActivityLogger $activity,
        private LoggerInterface $logger,
    ) {}

    public function create(
        int $supplierId,
        int $userId,
        string $sessionToken,
        string $proofToken,
        #[\SensitiveParameter] string $password,
        ?string $ip,
        string $userAgent,
    ): string {
        if ($supplierId < 1
            || $userId < 1
            || trim($sessionToken) === ''
            || trim($proofToken) === ''
        ) {
            throw new \InvalidArgumentException(
                'Kontext vytvoření zálohy firmy není platný.',
            );
        }
        CompanyBackupPasswordPolicy::assertValid($password);

        try {
            // Neúplný registr je produktový preflight, ne autorizovaná mutace.
            // Musí proto selhat ještě před spotřebou jednorázového proofu.
            $registry = $this->registry->current();
        } catch (IncompleteTenantDataRegistry $e) {
            throw new CompanyBackupCreationException('registry_incomplete', $e);
        }

        try {
            $backupId = $this->protectedOperations->runWithStepUp(
                $userId,
                $sessionToken,
                $proofToken,
                MfaStepUpService::OPERATION_COMPANY_BACKUP_CREATE,
                function () use (
                    $supplierId,
                    $userId,
                    $registry,
                    $password,
                    $ip,
                    $userAgent,
                ): string {
                    $backupId = $this->jobs->create(
                        $supplierId,
                        $userId,
                        $registry->fingerprint,
                        $password,
                    );
                    $this->activity->log(
                        'company_backup.created',
                        $userId,
                        'supplier',
                        $supplierId,
                        [
                            'backup_id' => $backupId,
                            'registry_fingerprint' => $registry->fingerprint,
                        ],
                        $ip,
                        $userAgent,
                        $supplierId,
                    );
                    return $backupId;
                },
            );
        } catch (CompanyBackupJobException $e) {
            throw new CompanyBackupCreationException($e->errorCode, $e);
        }

        try {
            $spawned = $this->launcher->launch($backupId);
        } catch (\Throwable $e) {
            $this->logger->error(
                'Launcher workeru zálohy firmy skončil výjimkou.',
                ['backup_id' => $backupId, 'exception' => $e],
            );
            $spawned = false;
        }
        if (!$spawned) {
            $this->failUnscheduledJob($backupId, $supplierId, $userId, $ip, $userAgent);
            throw new CompanyBackupCreationException('worker_unavailable');
        }

        return $backupId;
    }

    private function failUnscheduledJob(
        string $backupId,
        int $supplierId,
        int $userId,
        ?string $ip,
        string $userAgent,
    ): void {
        try {
            if (!$this->jobs->markFailed(
                $backupId,
                'worker_unavailable',
                'Job nebylo možné předat procesu na pozadí.',
            )) {
                $this->logger->warning(
                    'Nenaplánovaný job zálohy už nebyl v aktivním stavu.',
                    ['backup_id' => $backupId],
                );
                return;
            }
            $this->activity->log(
                'company_backup.failed',
                $userId,
                'supplier',
                $supplierId,
                ['backup_id' => $backupId, 'error_code' => 'worker_unavailable'],
                $ip,
                $userAgent,
                $supplierId,
            );
        } catch (\Throwable $e) {
            $this->logger->error(
                'Nenaplánovaný job zálohy se nepodařilo uzavřít nebo auditovat.',
                ['backup_id' => $backupId, 'exception' => $e],
            );
        }
    }
}
