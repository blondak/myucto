<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup\Company;

use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Auth\MfaProtectedOperationService;
use MyInvoice\Service\Auth\MfaStepUpService;
use MyInvoice\Service\Backup\Company\CompanyBackupCreationException;
use MyInvoice\Service\Backup\Company\CompanyBackupCreationService;
use MyInvoice\Service\Backup\Company\CompanyBackupJobException;
use MyInvoice\Service\Backup\Company\CompanyBackupJobLifecycle;
use MyInvoice\Service\Backup\Company\CompanyBackupRegistrySnapshotProvider;
use MyInvoice\Service\Backup\Company\CompanyBackupWorkerLauncher;
use MyInvoice\Service\Backup\Registry\IncompleteTenantDataRegistry;
use MyInvoice\Service\Backup\Registry\TenantDataDefinition;
use MyInvoice\Service\Backup\Registry\TenantDataObjectKind;
use MyInvoice\Service\Backup\Registry\TenantDataPolicy;
use MyInvoice\Service\Backup\Registry\TenantDataRegistry;
use MyInvoice\Service\Backup\Registry\TenantDataRegistrySnapshot;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class CompanyBackupCreationServiceTest extends TestCase
{
    private const BACKUP_ID = '0191f7a0-7c22-7bd1-8cd4-6e18cb55b8a1';
    private const PASSWORD = 'Synthetic-backup-password-42';

    public function testIncompleteRegistryFailsBeforeProofAndJob(): void
    {
        $registry = $this->createMock(CompanyBackupRegistrySnapshotProvider::class);
        $registry->expects(self::once())
            ->method('current')
            ->willThrowException(new IncompleteTenantDataRegistry('synthetic'));
        $protected = $this->createMock(MfaProtectedOperationService::class);
        $protected->expects(self::never())->method('runWithStepUp');
        $jobs = $this->createMock(CompanyBackupJobLifecycle::class);
        $jobs->expects(self::never())->method('create');

        $service = new CompanyBackupCreationService(
            $registry,
            $protected,
            $jobs,
            $this->createStub(CompanyBackupWorkerLauncher::class),
            $this->createStub(ActivityLogger::class),
            new NullLogger(),
        );

        $this->expectException(CompanyBackupCreationException::class);
        $this->expectExceptionMessage('registry_incomplete');
        $service->create(41, 17, 'session', 'proof', self::PASSWORD, null, 'PHPUnit');
    }

    public function testCreatesJobAndAuditInsideProtectedCallbackBeforeLaunch(): void
    {
        $snapshot = self::snapshot();
        $registry = $this->createStub(CompanyBackupRegistrySnapshotProvider::class);
        $registry->method('current')->willReturn($snapshot);
        $jobs = $this->createMock(CompanyBackupJobLifecycle::class);
        $jobs->expects(self::once())
            ->method('create')
            ->with(41, 17, $snapshot->fingerprint, self::PASSWORD)
            ->willReturn(self::BACKUP_ID);
        $activity = $this->createMock(ActivityLogger::class);
        $activity->expects(self::once())
            ->method('log')
            ->with(
                'company_backup.created',
                17,
                'supplier',
                41,
                [
                    'backup_id' => self::BACKUP_ID,
                    'registry_fingerprint' => $snapshot->fingerprint,
                ],
                '127.0.0.1',
                'PHPUnit',
                41,
            );
        $protected = $this->protectedRunner();
        $launcher = $this->createMock(CompanyBackupWorkerLauncher::class);
        $launcher->expects(self::once())
            ->method('launch')
            ->with(self::BACKUP_ID)
            ->willReturn(true);

        $created = (new CompanyBackupCreationService(
            $registry,
            $protected,
            $jobs,
            $launcher,
            $activity,
            new NullLogger(),
        ))->create(
            41,
            17,
            'session',
            'proof',
            self::PASSWORD,
            '127.0.0.1',
            'PHPUnit',
        );

        self::assertSame(self::BACKUP_ID, $created);
    }

    public function testJobFailureDoesNotLaunchWorkerAndKeepsStableCode(): void
    {
        $registry = $this->createStub(CompanyBackupRegistrySnapshotProvider::class);
        $registry->method('current')->willReturn(self::snapshot());
        $jobs = $this->createStub(CompanyBackupJobLifecycle::class);
        $jobs->method('create')->willThrowException(
            new CompanyBackupJobException('already_running'),
        );
        $launcher = $this->createMock(CompanyBackupWorkerLauncher::class);
        $launcher->expects(self::never())->method('launch');
        $service = new CompanyBackupCreationService(
            $registry,
            $this->protectedRunner(),
            $jobs,
            $launcher,
            $this->createStub(ActivityLogger::class),
            new NullLogger(),
        );

        try {
            $service->create(41, 17, 'session', 'proof', self::PASSWORD, null, 'PHPUnit');
            self::fail('Aktivní job stejné firmy musí nový export odmítnout.');
        } catch (CompanyBackupCreationException $e) {
            self::assertSame('already_running', $e->errorCode);
        }
    }

    public function testFailedLaunchTerminatesQueuedJobAndAuditsFailure(): void
    {
        $snapshot = self::snapshot();
        $registry = $this->createStub(CompanyBackupRegistrySnapshotProvider::class);
        $registry->method('current')->willReturn($snapshot);
        $jobs = $this->createMock(CompanyBackupJobLifecycle::class);
        $jobs->method('create')->willReturn(self::BACKUP_ID);
        $jobs->expects(self::once())
            ->method('markFailed')
            ->with(
                self::BACKUP_ID,
                'worker_unavailable',
                'Job nebylo možné předat procesu na pozadí.',
            )
            ->willReturn(true);
        $activity = $this->createMock(ActivityLogger::class);
        $actions = [];
        $activity->expects(self::exactly(2))
            ->method('log')
            ->willReturnCallback(
                static function (string $action) use (&$actions): void {
                    $actions[] = $action;
                },
            );
        $launcher = $this->createStub(CompanyBackupWorkerLauncher::class);
        $launcher->method('launch')->willReturn(false);
        $service = new CompanyBackupCreationService(
            $registry,
            $this->protectedRunner(),
            $jobs,
            $launcher,
            $activity,
            new NullLogger(),
        );

        try {
            $service->create(41, 17, 'session', 'proof', self::PASSWORD, null, 'PHPUnit');
            self::fail('Nenaplánovaný job nesmí být vrácen jako úspěšný.');
        } catch (CompanyBackupCreationException $e) {
            self::assertSame('worker_unavailable', $e->errorCode);
        }
        self::assertSame(
            ['company_backup.created', 'company_backup.failed'],
            $actions,
        );
    }

    private function protectedRunner(): MfaProtectedOperationService
    {
        $protected = $this->createMock(MfaProtectedOperationService::class);
        $protected->expects(self::once())
            ->method('runWithStepUp')
            ->with(
                17,
                'session',
                'proof',
                MfaStepUpService::OPERATION_COMPANY_BACKUP_CREATE,
                self::isInstanceOf(\Closure::class),
            )
            ->willReturnCallback(
                static fn (
                    int $_userId,
                    string $_session,
                    string $_proof,
                    string $_purpose,
                    \Closure $operation,
                ): mixed => $operation(),
            );
        return $protected;
    }

    private static function snapshot(): TenantDataRegistrySnapshot
    {
        $profile = TenantDataRegistry::COMPANY_BACKUP_PROFILE;
        $registry = new TenantDataRegistry(
            1,
            [new TenantDataDefinition(
                'logical:synthetic-ready',
                TenantDataObjectKind::LogicalObject,
                TenantDataPolicy::RuntimeDerived,
                [$profile],
                [],
            )],
            [$profile],
        );
        return TenantDataRegistrySnapshot::fromRegistry($registry, $profile);
    }
}
