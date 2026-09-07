<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup\Company;

use MyInvoice\Service\Backup\Company\CompanyBackupDataSourceException;
use MyInvoice\Service\Backup\Company\CompanyBackupPayrollStatutoryResultSetAssembler;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Run\PayrollStatutoryResultSetHash;
use PHPUnit\Framework\TestCase;

final class CompanyBackupPayrollStatutoryResultSetAssemblerTest extends TestCase
{
    public function testRebuildsRepositoryPayloadInBusinessIdentityOrder(): void
    {
        $header = $this->header();
        $people = [
            $this->person(42, 18, 1_800),
            $this->person(41, 17, 1_700),
        ];
        $relationships = [
            $this->relationship(62, 42, 18, 22, 220),
            $this->relationship(61, 41, 17, 21, 210),
            $this->relationship(60, 41, 17, 19, 190),
        ];
        $expectedPeople = [
            [
                'employee_id' => 17,
                'input_snapshot' => ['employee_id' => 17],
                'relationships' => [
                    [
                        'employment_id' => 19,
                        'input_snapshot' => ['employment_id' => 19],
                        'result_snapshot' => ['amount_minor' => 190],
                        'result_status' => 'calculated',
                    ],
                    [
                        'employment_id' => 21,
                        'input_snapshot' => ['employment_id' => 21],
                        'result_snapshot' => ['amount_minor' => 210],
                        'result_status' => 'calculated',
                    ],
                ],
                'result_snapshot' => ['amount_minor' => 1_700],
                'result_status' => 'calculated',
            ],
            [
                'employee_id' => 18,
                'input_snapshot' => ['employee_id' => 18],
                'relationships' => [[
                    'employment_id' => 22,
                    'input_snapshot' => ['employment_id' => 22],
                    'result_snapshot' => ['amount_minor' => 220],
                    'result_status' => 'calculated',
                ]],
                'result_snapshot' => ['amount_minor' => 1_800],
                'result_status' => 'calculated',
            ],
        ];
        $expected = PayrollStatutoryResultSetHash::calculate(
            'social_insurance',
            ['period' => '2026-06'],
            $expectedPeople,
            ['amount_minor' => 2_500],
            'calculated',
            str_repeat('a', 64),
            'cz-social-2026.1',
            'payroll-social-result.v1',
        );
        $header['result_set_hash'] = $expected;

        self::assertSame(
            $expected,
            CompanyBackupPayrollStatutoryResultSetAssembler::calculate(
                $header,
                $people,
                $relationships,
            ),
        );
        CompanyBackupPayrollStatutoryResultSetAssembler::assertSource(
            $header,
            $people,
            $relationships,
        );
        self::addToAssertionCount(1);
    }

    public function testRejectsRelationshipOutsideDeclaredPerson(): void
    {
        $header = $this->header();
        $people = [$this->person(41, 17, 1_700)];
        $relationship = $this->relationship(60, 41, 18, 19, 190);

        try {
            CompanyBackupPayrollStatutoryResultSetAssembler::calculate(
                $header,
                $people,
                [$relationship],
            );
            self::fail('Vztah jiné osoby nesmí vstoupit do kořenové pečeti.');
        } catch (CompanyBackupDataSourceException $e) {
            self::assertSame('data_aggregate_hash_graph_invalid', $e->errorCode);
            self::assertSame('result_set_hash', $e->column);
        }
    }

    public function testRejectsStoredHashThatDoesNotMatchChildRows(): void
    {
        $header = $this->header();
        $header['result_set_hash'] = str_repeat('f', 64);

        try {
            CompanyBackupPayrollStatutoryResultSetAssembler::assertSource(
                $header,
                [$this->person(41, 17, 1_700)],
                [$this->relationship(60, 41, 17, 19, 190)],
            );
            self::fail('Neodpovídající kořenová pečeť musí být odmítnuta.');
        } catch (CompanyBackupDataSourceException $e) {
            self::assertSame('data_aggregate_hash_value_invalid', $e->errorCode);
            self::assertSame('result_set_hash', $e->column);
        }
    }

    /** @return array<string,mixed> */
    private function header(): array
    {
        return [
            'id' => 31,
            'supplier_id' => 7,
            'revision_id' => 51,
            'calculation_kind' => 'social_insurance',
            'schema_version' => 'payroll-social-result.v1',
            'result_status' => 'calculated',
            'ruleset_id' => 'cz-social-2026.1',
            'ruleset_hash' => str_repeat('a', 64),
            'input_snapshot_json' => CanonicalJson::encode([
                'period' => '2026-06',
            ]),
            'result_snapshot_json' => CanonicalJson::encode([
                'amount_minor' => 2_500,
            ]),
            'result_set_hash' => str_repeat('0', 64),
        ];
    }

    /** @return array<string,mixed> */
    private function person(int $id, int $employeeId, int $amount): array
    {
        return [
            'id' => $id,
            'supplier_id' => 7,
            'statutory_result_id' => 31,
            'revision_id' => 51,
            'calculation_kind' => 'social_insurance',
            'employee_id' => $employeeId,
            'result_status' => 'calculated',
            'input_snapshot_json' => CanonicalJson::encode([
                'employee_id' => $employeeId,
            ]),
            'result_snapshot_json' => CanonicalJson::encode([
                'amount_minor' => $amount,
            ]),
        ];
    }

    /** @return array<string,mixed> */
    private function relationship(
        int $id,
        int $personResultId,
        int $employeeId,
        int $employmentId,
        int $amount,
    ): array {
        return [
            'id' => $id,
            'supplier_id' => 7,
            'statutory_result_id' => 31,
            'person_result_id' => $personResultId,
            'revision_id' => 51,
            'calculation_kind' => 'social_insurance',
            'employee_id' => $employeeId,
            'employment_id' => $employmentId,
            'result_status' => 'calculated',
            'input_snapshot_json' => CanonicalJson::encode([
                'employment_id' => $employmentId,
            ]),
            'result_snapshot_json' => CanonicalJson::encode([
                'amount_minor' => $amount,
            ]),
        ];
    }
}
