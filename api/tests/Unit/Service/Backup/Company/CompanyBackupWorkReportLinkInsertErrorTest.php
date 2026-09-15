<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup\Company;

use MyInvoice\Service\Backup\Company\CompanyBackupImportWriteException;
use MyInvoice\Service\Backup\Company\CompanyBackupPreparedImportRow;
use MyInvoice\Service\Backup\Company\CompanyBackupSourceIdentityProjection;
use MyInvoice\Service\Backup\Company\CompanyBackupSqlInsertWriter;
use MyInvoice\Service\Backup\Company\CompanyBackupTableSchema;
use MyInvoice\Service\Backup\Company\CompanyBackupWorkReportLinksProjection;
use MyInvoice\Service\Backup\Registry\CompanyBackupWorkReportLinksDefinition;
use PDO;
use PDOException;
use PDOStatement;
use PHPUnit\Framework\TestCase;

final class CompanyBackupWorkReportLinkInsertErrorTest extends TestCase
{
    public function testRealUniqueCollisionDoesNotExposeProtectedTokenInExceptionChain(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite není dostupné pro izolovaný SQL test.');
        }
        $database = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        $database->exec('CREATE TABLE work_report_links ('
            . 'id INTEGER PRIMARY KEY, supplier_id INTEGER NOT NULL, scope TEXT NOT NULL,'
            . 'client_id INTEGER NOT NULL, project_id INTEGER NULL, token TEXT NOT NULL UNIQUE,'
            . 'created_by_user_id INTEGER NULL, created_at TEXT NOT NULL, last_sent_at TEXT NULL,'
            . 'last_viewed_at TEXT NULL, revoked_at TEXT NULL)');
        $token = str_repeat('a', 48);
        $insert = $database->prepare('INSERT INTO work_report_links '
            . '(id, supplier_id, scope, client_id, token, created_at) VALUES (?, ?, ?, ?, ?, ?)');
        self::assertInstanceOf(PDOStatement::class, $insert);
        self::assertTrue($insert->execute([1, 7, 'client', 8, $token, '2026-09-15 10:00:00']));
        self::assertTrue($database->beginTransaction());

        $writer = new CompanyBackupSqlInsertWriter(
            $database,
            CompanyBackupWorkReportLinksDefinition::definition(),
            $this->schema(),
            1,
        );
        try {
            $writer->insert($this->prepared(), ['token' => $token]);
            self::fail('Globální UNIQUE kolize měla INSERT odmítnout.');
        } catch (CompanyBackupImportWriteException $failure) {
            self::assertSame('import_row_insert_failed', $failure->errorCode);
            $this->assertNoTokenInChain($failure, $token);
        } finally {
            self::assertTrue($database->rollBack());
        }
    }

    public function testPdoMessageAndErrorInfoAreNotRetainedAsPrevious(): void
    {
        $token = str_repeat('b', 48);
        $pdoFailure = new PDOException('synthetic SQL failure containing ' . $token);
        $pdoFailure->errorInfo = ['HY000', 9999, 'driver details containing ' . $token];
        $statement = $this->createMock(PDOStatement::class);
        $statement->method('bindValue')->willReturn(true);
        $statement->expects(self::once())->method('execute')->willThrowException($pdoFailure);
        $statement->method('closeCursor')->willReturn(true);
        $database = $this->createMock(PDO::class);
        $database->method('inTransaction')->willReturn(true);
        $database->expects(self::once())->method('getAttribute')
            ->with(PDO::ATTR_DRIVER_NAME)->willReturn('sqlite');
        $database->method('prepare')->willReturn($statement);

        $writer = new CompanyBackupSqlInsertWriter(
            $database,
            CompanyBackupWorkReportLinksDefinition::definition(),
            $this->schema(),
            1,
        );
        try {
            $writer->insert($this->prepared(), ['token' => $token]);
            self::fail('Syntetická PDO chyba měla INSERT odmítnout.');
        } catch (CompanyBackupImportWriteException $failure) {
            self::assertSame('import_row_insert_failed', $failure->errorCode);
            $this->assertNoTokenInChain($failure, $token);
        }
    }

    private function schema(): CompanyBackupTableSchema
    {
        return new CompanyBackupTableSchema(
            [...CompanyBackupWorkReportLinksProjection::dataColumns(), 'token'],
            [],
            ['id'],
            [],
        );
    }

    private function prepared(): CompanyBackupPreparedImportRow
    {
        $definition = CompanyBackupWorkReportLinksDefinition::definition();
        $identity = CompanyBackupSourceIdentityProjection::fromDefinition($definition);
        $source = $this->row(11, 7, 8);
        $target = $this->row(101, 71, 81);
        return new CompanyBackupPreparedImportRow(
            $target,
            $identity->identityForRow($source),
            $identity->identityForRow($target),
        );
    }

    /** @return array<string,mixed> */
    private function row(int $id, int $supplierId, int $clientId): array
    {
        return [
            'id' => $id,
            'supplier_id' => $supplierId,
            'scope' => 'client',
            'client_id' => $clientId,
            'project_id' => null,
            'created_by_user_id' => null,
            'created_at' => '2026-09-15 10:00:00',
            'last_sent_at' => null,
            'last_viewed_at' => null,
            'revoked_at' => null,
        ];
    }

    private function assertNoTokenInChain(\Throwable $failure, string $token): void
    {
        for ($current = $failure; $current !== null; $current = $current->getPrevious()) {
            self::assertStringNotContainsString($token, (string) $current);
            self::assertStringNotContainsString($token, $current->getTraceAsString());
            if ($current instanceof PDOException) {
                self::assertStringNotContainsString($token, var_export($current->errorInfo, true));
            }
        }
        self::assertNull($failure->getPrevious());
    }
}
