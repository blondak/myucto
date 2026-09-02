<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup\Company;

use MyInvoice\Service\Backup\Company\CompanyBackupJobStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CompanyBackupJobStatusTest extends TestCase
{
    #[DataProvider('allowedTransitions')]
    public function testAllowsOnlyDeclaredLifecycleTransitions(
        CompanyBackupJobStatus $from,
        CompanyBackupJobStatus $to,
    ): void {
        self::assertTrue($from->canTransitionTo($to));
    }

    /** @return iterable<string,array{CompanyBackupJobStatus,CompanyBackupJobStatus}> */
    public static function allowedTransitions(): iterable
    {
        yield 'queued starts checks' => [
            CompanyBackupJobStatus::Queued,
            CompanyBackupJobStatus::Checking,
        ];
        yield 'checks start snapshot' => [
            CompanyBackupJobStatus::Checking,
            CompanyBackupJobStatus::Snapshotting,
        ];
        yield 'snapshot starts packaging' => [
            CompanyBackupJobStatus::Snapshotting,
            CompanyBackupJobStatus::Packaging,
        ];
        yield 'package becomes downloadable' => [
            CompanyBackupJobStatus::Packaging,
            CompanyBackupJobStatus::Completed,
        ];
        yield 'completed artifact expires' => [
            CompanyBackupJobStatus::Completed,
            CompanyBackupJobStatus::Expired,
        ];

        foreach (self::processingStatuses() as $status) {
            yield $status->value . ' fails' => [$status, CompanyBackupJobStatus::Failed];
            yield $status->value . ' is cancelled' => [
                $status,
                CompanyBackupJobStatus::Cancelled,
            ];
            yield $status->value . ' expires' => [$status, CompanyBackupJobStatus::Expired];
        }
    }

    public function testRejectsSkippedRepeatedAndPostTerminalTransitions(): void
    {
        foreach (CompanyBackupJobStatus::cases() as $from) {
            foreach (CompanyBackupJobStatus::cases() as $to) {
                if ($from->canTransitionTo($to)) {
                    continue;
                }

                self::assertFalse(
                    $from->canTransitionTo($to),
                    $from->value . ' nesmí přejít na ' . $to->value,
                );
            }
        }

        self::assertFalse(
            CompanyBackupJobStatus::Queued->canTransitionTo(
                CompanyBackupJobStatus::Snapshotting,
            ),
        );
        self::assertFalse(
            CompanyBackupJobStatus::Completed->canTransitionTo(
                CompanyBackupJobStatus::Failed,
            ),
        );
    }

    public function testSeparatesWorkerStatesFromDownloadableArtifact(): void
    {
        foreach (self::processingStatuses() as $status) {
            self::assertTrue($status->isProcessing());
            self::assertFalse($status->isDownloadable());
        }

        self::assertTrue(CompanyBackupJobStatus::Completed->isDownloadable());
        self::assertFalse(CompanyBackupJobStatus::Completed->isProcessing());

        foreach ([
            CompanyBackupJobStatus::Failed,
            CompanyBackupJobStatus::Cancelled,
            CompanyBackupJobStatus::Expired,
        ] as $status) {
            self::assertFalse($status->isProcessing());
            self::assertFalse($status->isDownloadable());
        }
    }

    /** @return list<CompanyBackupJobStatus> */
    private static function processingStatuses(): array
    {
        return [
            CompanyBackupJobStatus::Queued,
            CompanyBackupJobStatus::Checking,
            CompanyBackupJobStatus::Snapshotting,
            CompanyBackupJobStatus::Packaging,
        ];
    }
}
