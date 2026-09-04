<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup\Company;

use MyInvoice\Service\Backup\Company\CompanyBackupAutoIncrementColumn;
use MyInvoice\Service\Backup\Company\CompanyBackupImportWriteException;
use MyInvoice\Service\Backup\Company\CompanyBackupSqlPrimaryKeyReservation;
use MyInvoice\Service\Backup\Company\CompanyBackupTableProjection;
use MyInvoice\Service\Backup\Registry\TenantDataDefinition;
use MyInvoice\Service\Backup\Registry\TenantDataObjectKind;
use MyInvoice\Service\Backup\Registry\TenantDataPolicy;
use MyInvoice\Service\Backup\Registry\TenantDataRegistry;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;

final class CompanyBackupSqlPrimaryKeyReservationTest extends TestCase
{
    private PDO $database;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite není dostupné pro izolovaný SQL test.');
        }
        $this->database = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ]);
        $this->database->exec(
            'CREATE TABLE synthetic_records ('
                . 'id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)',
        );
        $this->database->exec(
            "INSERT INTO synthetic_records (id, name) VALUES (1, 'one'), (7, 'seven')",
        );
    }

    public function testReservesContiguousRangeWithoutWritingOrEndingTransaction(): void
    {
        self::assertTrue($this->database->beginTransaction());

        $reservation = CompanyBackupSqlPrimaryKeyReservation::reserve(
            $this->database,
            $this->projection(),
            new CompanyBackupAutoIncrementColumn('id', 255),
            2,
        );

        self::assertSame(8, $reservation->next());
        self::assertSame(9, $reservation->next());
        self::assertSame(0, $reservation->remaining());
        $reservation->finish();
        self::assertSame(2, $this->rowCount());
        self::assertTrue($this->database->inTransaction());
        self::assertTrue($this->database->rollBack());
    }

    public function testRejectsMissingTransactionAndExhaustedColumnRange(): void
    {
        try {
            CompanyBackupSqlPrimaryKeyReservation::reserve(
                $this->database,
                $this->projection(),
                new CompanyBackupAutoIncrementColumn('id', 255),
                1,
            );
            self::fail('Rezervace mimo zapisovací transakci nesmí vzniknout.');
        } catch (CompanyBackupImportWriteException $e) {
            self::assertSame('import_transaction_required', $e->errorCode);
            self::assertSame('table:synthetic_records', $e->registryKey);
            self::assertNull($e->column);
        }

        self::assertTrue($this->database->beginTransaction());
        try {
            CompanyBackupSqlPrimaryKeyReservation::reserve(
                $this->database,
                $this->projection(),
                new CompanyBackupAutoIncrementColumn('id', 8),
                2,
            );
            self::fail('Rozsah za maximem fyzického typu nesmí přetéct.');
        } catch (CompanyBackupImportWriteException $e) {
            self::assertSame('import_primary_key_range_exhausted', $e->errorCode);
            self::assertSame('id', $e->column);
        }
        self::assertTrue($this->database->rollBack());
    }

    public function testEnforcesExactReservationConsumption(): void
    {
        self::assertTrue($this->database->beginTransaction());
        $reservation = CompanyBackupSqlPrimaryKeyReservation::reserve(
            $this->database,
            $this->projection(),
            new CompanyBackupAutoIncrementColumn('id', 255),
            2,
        );

        self::assertSame(8, $reservation->next());
        try {
            $reservation->finish();
            self::fail('Nevyčerpaná rezervace nesmí potvrdit počet řádků.');
        } catch (CompanyBackupImportWriteException $e) {
            self::assertSame('import_primary_key_reservation_incomplete', $e->errorCode);
        }
        self::assertSame(9, $reservation->next());
        $reservation->finish();
        try {
            $reservation->next();
            self::fail('Uzavřená rezervace nesmí vydat další ID.');
        } catch (CompanyBackupImportWriteException $e) {
            self::assertSame('import_primary_key_reservation_closed', $e->errorCode);
        }
        self::assertTrue($this->database->rollBack());
    }

    public function testMysqlPathChecksIsolationAndLocksUpperPrimaryKeyGap(): void
    {
        $isolation = $this->scalarStatement('REPEATABLE-READ');
        $maximum = $this->scalarStatement('7');
        $queries = [];
        $database = $this->createMock(PDO::class);
        $database->expects(self::exactly(4))
            ->method('inTransaction')
            ->willReturn(true);
        $database->expects(self::once())
            ->method('getAttribute')
            ->with(PDO::ATTR_DRIVER_NAME)
            ->willReturn('mysql');
        $database->expects(self::exactly(2))
            ->method('prepare')
            ->willReturnCallback(static function (string $sql) use (
                &$queries,
                $isolation,
                $maximum,
            ): PDOStatement {
                $queries[] = $sql;
                return count($queries) === 1 ? $isolation : $maximum;
            });

        $reservation = CompanyBackupSqlPrimaryKeyReservation::reserve(
            $database,
            $this->projection(),
            new CompanyBackupAutoIncrementColumn('id', 255),
            2,
        );

        self::assertSame(8, $reservation->next());
        self::assertSame(9, $reservation->next());
        $reservation->finish();
        self::assertSame([
            'SELECT @@transaction_isolation',
            'SELECT `id` FROM `synthetic_records`'
                . ' ORDER BY `id` DESC LIMIT 1 FOR UPDATE',
        ], $queries);
    }

    public function testStopsIssuingIdsAfterCallerLosesTransaction(): void
    {
        self::assertTrue($this->database->beginTransaction());
        $reservation = CompanyBackupSqlPrimaryKeyReservation::reserve(
            $this->database,
            $this->projection(),
            new CompanyBackupAutoIncrementColumn('id', 255),
            1,
        );
        self::assertTrue($this->database->rollBack());

        try {
            $reservation->next();
            self::fail('Klíč po ztrátě chránící transakce nesmí být vydán.');
        } catch (CompanyBackupImportWriteException $e) {
            self::assertSame('import_transaction_lost', $e->errorCode);
        }
    }

    public function testIssuesMaximumPhpIntegerWithoutOverflowingInternalCounter(): void
    {
        $maximum = $this->scalarStatement((string) (PHP_INT_MAX - 1));
        $database = $this->createMock(PDO::class);
        $database->expects(self::exactly(3))
            ->method('inTransaction')
            ->willReturn(true);
        $database->expects(self::once())
            ->method('getAttribute')
            ->with(PDO::ATTR_DRIVER_NAME)
            ->willReturn('sqlite');
        $database->expects(self::once())
            ->method('prepare')
            ->willReturn($maximum);
        $reservation = CompanyBackupSqlPrimaryKeyReservation::reserve(
            $database,
            $this->projection(),
            new CompanyBackupAutoIncrementColumn('id', PHP_INT_MAX),
            1,
        );

        self::assertSame(PHP_INT_MAX, $reservation->next());
        $reservation->finish();
    }

    private function projection(): CompanyBackupTableProjection
    {
        return CompanyBackupTableProjection::fromDefinition(
            new TenantDataDefinition(
                'table:synthetic_records',
                TenantDataObjectKind::Table,
                TenantDataPolicy::TenantRoot,
                [TenantDataRegistry::COMPANY_BACKUP_PROFILE],
                [
                    'primary_key' => ['id'],
                    'ownership' => [
                        'strategy' => 'selected_supplier',
                        'column' => 'id',
                    ],
                    'secrets' => [],
                    'company_backup' => [
                        'data_columns' => ['id', 'name'],
                        'embedded_references' => [],
                        'generated_columns' => [],
                        'omit_columns' => [],
                        'references' => [],
                        'restore_overrides' => [],
                    ],
                ],
            ),
        );
    }

    private function rowCount(): int
    {
        $statement = $this->database->query(
            'SELECT COUNT(*) FROM synthetic_records',
        );
        if ($statement === false) {
            throw new \RuntimeException('Kontrolní počet řádků nelze načíst.');
        }
        $value = $statement->fetchColumn();
        return is_int($value) ? $value : (int) $value;
    }

    private function scalarStatement(int|string|false $value): PDOStatement
    {
        $statement = $this->createMock(PDOStatement::class);
        $statement->expects(self::once())->method('execute')->willReturn(true);
        $statement->expects(self::once())->method('fetchColumn')->willReturn($value);
        $statement->expects(self::once())->method('closeCursor')->willReturn(true);
        return $statement;
    }
}
