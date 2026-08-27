<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup\Company;

use MyInvoice\Service\Backup\Company\CompanyBackupSnapshotException;
use MyInvoice\Service\Backup\Company\CompanyBackupSnapshotTransaction;
use PDO;
use PHPUnit\Framework\TestCase;

final class CompanyBackupSnapshotTransactionTest extends TestCase
{
    public function testRunsCallbackInReadOnlyRepeatableReadConsistentSnapshot(): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->expects(self::exactly(3))
            ->method('inTransaction')
            ->willReturnOnConsecutiveCalls(false, true, true);
        $commands = [];
        $pdo->expects(self::exactly(2))
            ->method('exec')
            ->willReturnCallback(static function (string $sql) use (&$commands): int {
                $commands[] = $sql;
                return 0;
            });
        $pdo->expects(self::once())->method('commit')->willReturn(true);
        $pdo->expects(self::never())->method('rollBack');

        $result = (new CompanyBackupSnapshotTransaction())->run(
            $pdo,
            static function (PDO $snapshot) use ($pdo): string {
                self::assertSame($pdo, $snapshot);
                return 'synthetic-result';
            },
        );

        self::assertSame('synthetic-result', $result);
        self::assertSame([
            'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ',
            'START TRANSACTION READ ONLY, WITH CONSISTENT SNAPSHOT',
        ], $commands);
    }

    public function testCallbackFailureRollsBackAndPreservesDomainException(): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->expects(self::exactly(3))
            ->method('inTransaction')
            ->willReturnOnConsecutiveCalls(false, true, true);
        $pdo->expects(self::exactly(2))->method('exec')->willReturn(0);
        $pdo->expects(self::never())->method('commit');
        $pdo->expects(self::once())->method('rollBack')->willReturn(true);
        $domain = new \DomainException('synthetic source failure');

        $caught = null;
        try {
            (new CompanyBackupSnapshotTransaction())->run(
                $pdo,
                static function () use ($domain): void {
                    throw $domain;
                },
            );
        } catch (\DomainException $e) {
            $caught = $e;
        }
        self::assertSame($domain, $caught);
    }

    public function testExistingTransactionIsRejectedWithoutChangingIt(): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->expects(self::once())->method('inTransaction')->willReturn(true);
        $pdo->expects(self::never())->method('exec');
        $pdo->expects(self::never())->method('commit');
        $pdo->expects(self::never())->method('rollBack');

        try {
            (new CompanyBackupSnapshotTransaction())->run($pdo, static fn (): null => null);
            self::fail('Snapshot nesmí zdědit cizí transakci s neznámou izolací.');
        } catch (CompanyBackupSnapshotException $e) {
            self::assertSame('snapshot_transaction_nested', $e->errorCode);
        }
    }

    public function testCallbackCannotCommitSnapshotBehindCoordinator(): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->expects(self::exactly(4))
            ->method('inTransaction')
            ->willReturnOnConsecutiveCalls(false, true, false, false);
        $pdo->expects(self::exactly(2))->method('exec')->willReturn(0);
        $pdo->expects(self::never())->method('commit');
        $pdo->expects(self::never())->method('rollBack');

        try {
            (new CompanyBackupSnapshotTransaction())->run(
                $pdo,
                static fn (): string => 'transaction-was-closed',
            );
            self::fail('Zdroj nesmí ukončit konzistentní snapshot před koordinátorem.');
        } catch (CompanyBackupSnapshotException $e) {
            self::assertSame('snapshot_transaction_lost', $e->errorCode);
        }
    }

    public function testFailedStartHasStableInfrastructureError(): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->expects(self::once())->method('inTransaction')->willReturn(false);
        $pdo->expects(self::exactly(2))
            ->method('exec')
            ->willReturnOnConsecutiveCalls(0, false);
        $pdo->expects(self::never())->method('commit');
        $pdo->expects(self::never())->method('rollBack');

        try {
            (new CompanyBackupSnapshotTransaction())->run($pdo, static fn (): null => null);
            self::fail('Nezahájený snapshot nesmí spustit exportní callback.');
        } catch (CompanyBackupSnapshotException $e) {
            self::assertSame('snapshot_start_failed', $e->errorCode);
        }
    }
}
