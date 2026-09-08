<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup\Company;

use MyInvoice\Service\Backup\Company\CompanyBackupImportWriteException;
use MyInvoice\Service\Backup\Company\CompanyBackupSupplierCurrencyCycle;
use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CompanyBackupSupplierCurrencyCycleTest extends TestCase
{
    public function testRequiresTransactionBeforeChangingSessionOrWriting(): void
    {
        $database = $this->createMock(PDO::class);
        $database->expects(self::once())->method('inTransaction')->willReturn(false);
        $database->expects(self::never())->method('exec');
        $this->expectExceptionMessage('import_supplier_cycle_transaction_required');
        CompanyBackupSupplierCurrencyCycle::insert($database,
            static fn () => self::fail('Bez transakce se nesmí zapisovat.'));
    }

    #[DataProvider('restoreFailures')]
    public function testFailedReenableRollsBackBeforeReportingFailure(string $failure): void
    {
        $database = $this->createMock(PDO::class);
        $database->method('inTransaction')->willReturn(true);
        $database->expects(self::once())->method('getAttribute')->with(PDO::ATTR_DRIVER_NAME)->willReturn('mysql');
        $statement = $this->createStub(PDOStatement::class);
        $statement->method('fetchColumn')->willReturnOnConsecutiveCalls(1, 0);
        $statement->method('closeCursor')->willReturn(true);
        $database->expects(self::exactly($failure === 'unchanged' ? 2 : 1))
            ->method('query')->with('SELECT @@SESSION.foreign_key_checks')->willReturn($statement);
        $commands = [];
        $database->expects(self::exactly(2))->method('exec')
            ->willReturnCallback(static function (string $sql) use (&$commands, $failure): int|false {
                $commands[] = $sql;
                if (count($commands) === 2) {
                    if ($failure === 'exception') {
                        throw new \RuntimeException('Synthetic session failure');
                    }
                    if ($failure === 'false') {
                        return false;
                    }
                }
                return 0;
            });
        $database->expects(self::once())->method('rollBack')->willReturn(true);
        $inserted = false;
        try {
            CompanyBackupSupplierCurrencyCycle::insert($database,
                static function () use (&$inserted): void { $inserted = true; });
            self::fail('Neobnovené kontroly musí import zastavit.');
        } catch (CompanyBackupImportWriteException $e) {
            self::assertSame('import_supplier_cycle_checks_restore_failed', $e->errorCode);
        }
        self::assertTrue($inserted);
        self::assertSame([
            'SET SESSION FOREIGN_KEY_CHECKS = 0',
            'SET SESSION FOREIGN_KEY_CHECKS = 1',
        ], $commands);
    }

    /** @return iterable<string,array{string}> */
    public static function restoreFailures(): iterable
    {
        yield 'PDO exception' => ['exception'];
        yield 'PDO silent error' => ['false'];
        yield 'session flag unchanged' => ['unchanged'];
    }
}
