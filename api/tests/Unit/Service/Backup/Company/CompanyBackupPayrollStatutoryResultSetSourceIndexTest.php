<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup\Company;

use MyInvoice\Service\Backup\Company\CompanyBackupPayrollStatutoryResultSetAssembler;
use MyInvoice\Service\Backup\Company\CompanyBackupPayrollStatutoryResultSetSourceIndex;
use MyInvoice\Service\Backup\Company\CompanyBackupPreflightException;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use PDO;
use PHPUnit\Framework\TestCase;

final class CompanyBackupPayrollStatutoryResultSetSourceIndexTest extends TestCase
{
    private PDO $database;

    protected function setUp(): void
    {
        $this->database = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        $this->database->beginTransaction();
    }

    protected function tearDown(): void
    {
        if ($this->database->inTransaction()) {
            $this->database->rollBack();
        }
    }

    public function testValidatesRootsFromDiskBackedChildIndex(): void
    {
        $index = new CompanyBackupPayrollStatutoryResultSetSourceIndex(
            $this->database,
        );
        $person = $this->person(41, 31, 17);
        $relationship = $this->relationship(61, 31, 41, 17, 19);
        $header = $this->header(31);
        $header['result_set_hash'] =
            CompanyBackupPayrollStatutoryResultSetAssembler::calculate(
                $header,
                [$person],
                [$relationship],
            );

        $index->addPerson($person);
        $index->addRelationship($relationship);
        $index->seal();
        $index->assertSourceHeader($header);
        $index->finish(1);

        self::assertSame(2, $index->rowCount());
        self::assertGreaterThan(0, $index->indexedBytes());
        self::assertTrue($this->database->inTransaction());
        $index->close();
        self::assertTrue($this->database->inTransaction());
    }

    public function testRejectsChildRowsWithoutClaimedRoot(): void
    {
        $index = new CompanyBackupPayrollStatutoryResultSetSourceIndex(
            $this->database,
        );
        $index->addPerson($this->person(41, 31, 17));
        $index->seal();
        $emptyHeader = $this->header(32);
        $emptyHeader['result_set_hash'] =
            CompanyBackupPayrollStatutoryResultSetAssembler::calculate(
                $emptyHeader,
                [],
                [],
            );
        $index->assertSourceHeader($emptyHeader);

        try {
            $index->finish(1);
            self::fail('Potomek bez exportované hlavičky musí být odmítnut.');
        } catch (CompanyBackupPreflightException $e) {
            self::assertSame('source_aggregate_graph_incomplete', $e->errorCode);
            self::assertSame(
                'table:payroll_statutory_results',
                $e->registryKey,
            );
        } finally {
            $index->close();
        }
    }

    public function testRejectsRepeatedRootClaim(): void
    {
        $index = new CompanyBackupPayrollStatutoryResultSetSourceIndex(
            $this->database,
        );
        $header = $this->header(31);
        $header['result_set_hash'] =
            CompanyBackupPayrollStatutoryResultSetAssembler::calculate(
                $header,
                [],
                [],
            );
        $index->seal();
        $index->assertSourceHeader($header);

        try {
            $index->assertSourceHeader($header);
            self::fail('Jedna immutable hlavička se smí ověřit jen jednou.');
        } catch (CompanyBackupPreflightException $e) {
            self::assertSame('source_aggregate_root_duplicate', $e->errorCode);
        } finally {
            $index->close();
        }
    }

    public function testCloseRemovesIndexAfterFailedRootValidation(): void
    {
        $index = new CompanyBackupPayrollStatutoryResultSetSourceIndex(
            $this->database,
        );
        $index->addPerson($this->person(41, 31, 17));
        $index->addRelationship($this->relationship(61, 31, 41, 17, 19));
        $header = $this->header(31);
        $header['result_set_hash'] = str_repeat('f', 64);
        $index->seal();

        try {
            $index->assertSourceHeader($header);
            self::fail('Neplatná pečeť musí zastavit validaci kořene.');
        } catch (CompanyBackupPreflightException $e) {
            self::assertSame('data_aggregate_hash_value_invalid', $e->errorCode);
        } finally {
            $index->close();
        }

        $tables = $this->database->query(
            "SELECT name FROM sqlite_temp_master"
                . " WHERE type = 'table'"
                . " AND name LIKE 'company_backup_statutory_%'",
        );
        self::assertNotFalse($tables);
        self::assertSame([], $tables->fetchAll(PDO::FETCH_COLUMN));
    }

    /** @return array<string,mixed> */
    private function header(int $id): array
    {
        return [
            'id' => $id,
            'supplier_id' => 7,
            'revision_id' => 51,
            'calculation_kind' => 'social_insurance',
            'schema_version' => 'payroll-social-result.v1',
            'result_status' => 'calculated',
            'ruleset_id' => 'cz-social-2026.1',
            'ruleset_hash' => str_repeat('a', 64),
            'input_snapshot_json' => CanonicalJson::encode(['period' => '2026-06']),
            'result_snapshot_json' => CanonicalJson::encode(['amount_minor' => 2_500]),
            'result_set_hash' => str_repeat('0', 64),
        ];
    }

    /** @return array<string,mixed> */
    private function person(int $id, int $rootId, int $employeeId): array
    {
        return [
            'id' => $id,
            'supplier_id' => 7,
            'statutory_result_id' => $rootId,
            'revision_id' => 51,
            'calculation_kind' => 'social_insurance',
            'employee_id' => $employeeId,
            'result_status' => 'calculated',
            'input_snapshot_json' => CanonicalJson::encode([
                'employee_id' => $employeeId,
            ]),
            'result_snapshot_json' => CanonicalJson::encode([
                'amount_minor' => 1_700,
            ]),
        ];
    }

    /** @return array<string,mixed> */
    private function relationship(
        int $id,
        int $rootId,
        int $personId,
        int $employeeId,
        int $employmentId,
    ): array {
        return [
            'id' => $id,
            'supplier_id' => 7,
            'statutory_result_id' => $rootId,
            'person_result_id' => $personId,
            'revision_id' => 51,
            'calculation_kind' => 'social_insurance',
            'employee_id' => $employeeId,
            'employment_id' => $employmentId,
            'result_status' => 'calculated',
            'input_snapshot_json' => CanonicalJson::encode([
                'employment_id' => $employmentId,
            ]),
            'result_snapshot_json' => CanonicalJson::encode([
                'amount_minor' => 190,
            ]),
        ];
    }
}
