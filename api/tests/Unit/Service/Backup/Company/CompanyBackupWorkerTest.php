<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup\Company;

use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Backup\Company\CompanyBackupExportPipeline;
use MyInvoice\Service\Backup\Company\CompanyBackupJobLifecycle;
use MyInvoice\Service\Backup\Company\CompanyBackupJobRetentionPolicy;
use MyInvoice\Service\Backup\Company\CompanyBackupJobStatus;
use MyInvoice\Service\Backup\Company\CompanyBackupSnapshotException;
use MyInvoice\Service\Backup\Company\CompanyBackupStoredArtifact;
use MyInvoice\Service\Backup\Company\CompanyBackupWorker;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;

final class CompanyBackupWorkerTest extends TestCase
{
    private const BACKUP_ID = '0191f7a0-7c22-7bd1-8cd4-6e18cb55b8a1';
    private const PASSWORD = 'Synthetic-backup-password-42';

    public function testWorkerAdvancesOrderedLifecycleAndAuditsCompletedArtifact(): void
    {
        $job = $this->job();
        $artifact = $this->artifact();
        $jobs = $this->createMock(CompanyBackupJobLifecycle::class);
        $jobs->method('findForWorker')->willReturn($job);
        $jobs->expects(self::once())->method('startChecking')->willReturn(true);
        $jobs->expects(self::once())->method('passwordForWorker')->willReturn(self::PASSWORD);
        $jobs->expects(self::once())->method('startSnapshotting')->willReturn(true);
        $jobs->expects(self::once())->method('startPackaging')->willReturn(true);
        $jobs->expects(self::exactly(2))->method('isCancelRequested')->willReturn(false);
        $progress = [];
        $jobs->expects(self::exactly(3))
            ->method('updateProgress')
            ->willReturnCallback(
                static function (
                    string $backupId,
                    CompanyBackupJobStatus $status,
                    int $processed,
                    ?int $total,
                ) use (&$progress): bool {
                    $progress[] = [$backupId, $status->value, $processed, $total];
                    return true;
                },
            );
        $jobs->expects(self::once())
            ->method('complete')
            ->with(
                self::BACKUP_ID,
                $artifact,
                self::isInstanceOf(\DateTimeImmutable::class),
                self::isInstanceOf(CompanyBackupJobRetentionPolicy::class),
            )
            ->willReturn(true);
        $pipeline = $this->createMock(CompanyBackupExportPipeline::class);
        $pipeline->expects(self::once())->method('check')->with($job);
        $pipeline->expects(self::once())
            ->method('export')
            ->with($job, self::PASSWORD, self::isInstanceOf(\Closure::class))
            ->willReturnCallback(
                static function (
                    array $_job,
                    string $_password,
                    \Closure $beforePackaging,
                ) use ($artifact): CompanyBackupStoredArtifact {
                    $beforePackaging();
                    return $artifact;
                },
            );
        $pipeline->expects(self::never())->method('discard');
        $activity = $this->createMock(ActivityLogger::class);
        $activity->expects(self::once())
            ->method('log')
            ->with(
                'company_backup.completed',
                17,
                'supplier',
                41,
                [
                    'backup_id' => self::BACKUP_ID,
                    'sha256' => str_repeat('a', 64),
                    'size_bytes' => 12_345,
                    'entry_count' => 7,
                ],
                null,
                'company-backup-worker',
                41,
            );

        $status = $this->worker($jobs, $pipeline, $activity)->run(self::BACKUP_ID);

        self::assertSame(CompanyBackupJobStatus::Completed, $status);
        self::assertSame([
            [self::BACKUP_ID, 'checking', 0, 3],
            [self::BACKUP_ID, 'snapshotting', 1, 3],
            [self::BACKUP_ID, 'packaging', 2, 3],
        ], $progress);
    }

    public function testCancellationAfterCheckStopsBeforePasswordAndSnapshot(): void
    {
        $job = $this->job();
        $jobs = $this->createMock(CompanyBackupJobLifecycle::class);
        $jobs->method('findForWorker')->willReturn($job);
        $jobs->method('startChecking')->willReturn(true);
        $jobs->method('updateProgress')->willReturn(true);
        $jobs->expects(self::once())->method('isCancelRequested')->willReturn(true);
        $jobs->expects(self::never())->method('passwordForWorker');
        $jobs->expects(self::once())->method('markCancelled')->willReturn(true);
        $pipeline = $this->createMock(CompanyBackupExportPipeline::class);
        $pipeline->expects(self::once())->method('check')->with($job);
        $pipeline->expects(self::never())->method('export');
        $activity = $this->createMock(ActivityLogger::class);
        $activity->expects(self::once())
            ->method('log')
            ->with(
                'company_backup.cancelled',
                17,
                'supplier',
                41,
                ['backup_id' => self::BACKUP_ID],
                null,
                'company-backup-worker',
                41,
            );

        self::assertSame(
            CompanyBackupJobStatus::Cancelled,
            $this->worker($jobs, $pipeline, $activity)->run(self::BACKUP_ID),
        );
    }

    public function testPipelineFailureUsesStableCodeAndNeverReturnsInternalMessage(): void
    {
        $job = $this->job();
        $jobs = $this->createMock(CompanyBackupJobLifecycle::class);
        $jobs->method('findForWorker')->willReturn($job);
        $jobs->method('startChecking')->willReturn(true);
        $jobs->method('updateProgress')->willReturn(true);
        $jobs->method('isCancelRequested')->willReturn(false);
        $jobs->method('passwordForWorker')->willReturn(self::PASSWORD);
        $jobs->method('startSnapshotting')->willReturn(true);
        $jobs->expects(self::once())
            ->method('markFailed')
            ->with(
                self::BACKUP_ID,
                'snapshot_source_failed',
                'CompanyBackupSnapshotException: snapshot_source_failed',
            )
            ->willReturn(true);
        $pipeline = $this->createStub(CompanyBackupExportPipeline::class);
        $pipeline->method('check');
        $pipeline->method('export')->willThrowException(
            new CompanyBackupSnapshotException('snapshot_source_failed'),
        );
        $activity = $this->createMock(ActivityLogger::class);
        $activity->expects(self::once())
            ->method('log')
            ->with(
                'company_backup.failed',
                17,
                'supplier',
                41,
                [
                    'backup_id' => self::BACKUP_ID,
                    'error_code' => 'snapshot_source_failed',
                ],
                null,
                'company-backup-worker',
                41,
            );

        self::assertSame(
            CompanyBackupJobStatus::Failed,
            $this->worker($jobs, $pipeline, $activity)->run(self::BACKUP_ID),
        );
    }

    public function testCancellationRaceDiscardsPublishedArtifact(): void
    {
        $job = $this->job();
        $artifact = $this->artifact();
        $jobs = $this->createMock(CompanyBackupJobLifecycle::class);
        $jobs->method('findForWorker')->willReturn($job);
        $jobs->method('startChecking')->willReturn(true);
        $jobs->method('updateProgress')->willReturn(true);
        $jobs->method('passwordForWorker')->willReturn(self::PASSWORD);
        $jobs->method('startSnapshotting')->willReturn(true);
        $jobs->method('startPackaging')->willReturn(true);
        $jobs->expects(self::exactly(3))
            ->method('isCancelRequested')
            ->willReturn(false, false, true);
        $jobs->expects(self::once())->method('complete')->willReturn(false);
        $jobs->expects(self::once())->method('markCancelled')->willReturn(true);
        $pipeline = $this->createMock(CompanyBackupExportPipeline::class);
        $pipeline->method('export')->willReturnCallback(
            static function (
                array $_job,
                string $_password,
                \Closure $beforePackaging,
            ) use ($artifact): CompanyBackupStoredArtifact {
                $beforePackaging();
                return $artifact;
            },
        );
        $pipeline->expects(self::once())->method('discard')->with($artifact);
        $activity = $this->createStub(ActivityLogger::class);

        self::assertSame(
            CompanyBackupJobStatus::Cancelled,
            $this->worker($jobs, $pipeline, $activity)->run(self::BACKUP_ID),
        );
    }

    public function testCancellationReportsConcurrentTerminalState(): void
    {
        $job = $this->job();
        $failed = $job;
        $failed['status'] = CompanyBackupJobStatus::Failed->value;
        $jobs = $this->createMock(CompanyBackupJobLifecycle::class);
        $jobs->expects(self::exactly(2))
            ->method('findForWorker')
            ->willReturn($job, $failed);
        $jobs->method('startChecking')->willReturn(true);
        $jobs->method('updateProgress')->willReturn(true);
        $jobs->expects(self::once())->method('isCancelRequested')->willReturn(true);
        $jobs->expects(self::once())->method('markCancelled')->willReturn(false);
        $pipeline = $this->createMock(CompanyBackupExportPipeline::class);
        $pipeline->expects(self::once())->method('check')->with($job);
        $pipeline->expects(self::never())->method('export');
        $activity = $this->createMock(ActivityLogger::class);
        $activity->expects(self::never())->method('log');

        self::assertSame(
            CompanyBackupJobStatus::Failed,
            $this->worker($jobs, $pipeline, $activity)->run(self::BACKUP_ID),
        );
    }

    public function testFailureReportsConcurrentTerminalState(): void
    {
        $job = $this->job();
        $cancelled = $job;
        $cancelled['status'] = CompanyBackupJobStatus::Cancelled->value;
        $jobs = $this->createMock(CompanyBackupJobLifecycle::class);
        $jobs->expects(self::exactly(2))
            ->method('findForWorker')
            ->willReturn($job, $cancelled);
        $jobs->method('startChecking')->willReturn(true);
        $jobs->method('updateProgress')->willReturn(true);
        $jobs->expects(self::once())->method('isCancelRequested')->willReturn(false);
        $jobs->expects(self::once())->method('markFailed')->willReturn(false);
        $pipeline = $this->createStub(CompanyBackupExportPipeline::class);
        $pipeline->method('check')->willThrowException(
            new CompanyBackupSnapshotException('snapshot_source_failed'),
        );
        $activity = $this->createMock(ActivityLogger::class);
        $activity->expects(self::never())->method('log');

        self::assertSame(
            CompanyBackupJobStatus::Cancelled,
            $this->worker($jobs, $pipeline, $activity)->run(self::BACKUP_ID),
        );
    }

    private function worker(
        CompanyBackupJobLifecycle $jobs,
        CompanyBackupExportPipeline $pipeline,
        ActivityLogger $activity,
    ): CompanyBackupWorker {
        return new CompanyBackupWorker(
            $jobs,
            $pipeline,
            new CompanyBackupJobRetentionPolicy(24),
            new MockClock('2026-09-03T10:00:00+00:00'),
            $activity,
            new NullLogger(),
        );
    }

    /** @return array<string,mixed> */
    private function job(): array
    {
        return [
            'backup_id' => self::BACKUP_ID,
            'supplier_id' => 41,
            'created_by' => 17,
            'status' => 'queued',
            'registry_fingerprint' => 'sha256:' . str_repeat('b', 64),
            'cancel_requested' => false,
        ];
    }

    private function artifact(): CompanyBackupStoredArtifact
    {
        return new CompanyBackupStoredArtifact(
            41,
            self::BACKUP_ID,
            'sup-41/' . self::BACKUP_ID . '.zip',
            'myucto-company-backup-' . self::BACKUP_ID . '.zip',
            12_345,
            str_repeat('a', 64),
            7,
        );
    }
}
