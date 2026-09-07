<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup\Company;

use MyInvoice\Service\Backup\Company\CompanyBackupPayrollStatutoryResultSetAssembler;
use MyInvoice\Service\Backup\Company\CompanyBackupPayrollStatutoryResultSetResealer;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Run\PayrollStatutoryResultSetHash;
use PHPUnit\Framework\TestCase;

final class CompanyBackupPayrollStatutoryResultSetResealerTest extends TestCase
{
    public function testResealsMappedRootBeforeImmutableInsert(): void
    {
        $person = $this->person();
        $relationship = $this->relationship();
        $source = $this->header();
        $source['result_set_hash'] =
            CompanyBackupPayrollStatutoryResultSetAssembler::calculate(
                $source,
                [$person],
                [$relationship],
            );
        $target = $source;
        $target['id'] = 131;
        $target['supplier_id'] = 107;
        $target['revision_id'] = 151;
        $target['input_snapshot_json'] = CanonicalJson::encode([
            'employee_id' => 117,
        ]);
        $target['result_snapshot_json'] = CanonicalJson::encode([
            'person_reference' => 'employee:117',
        ]);

        $restored = CompanyBackupPayrollStatutoryResultSetResealer::reseal(
            $source,
            $target,
            [$person],
            [$relationship],
            static function (array $row): array {
                $row['employee_id'] = 117;
                $row['input_snapshot_json'] = CanonicalJson::encode([
                    'employee_id' => 117,
                ]);
                $row['result_snapshot_json'] = CanonicalJson::encode([
                    'person_reference' => 'employee:117',
                ]);
                return $row;
            },
            static function (array $row): array {
                $row['employee_id'] = 117;
                $row['employment_id'] = 119;
                $row['input_snapshot_json'] = CanonicalJson::encode([
                    'employment_id' => 119,
                ]);
                $row['result_snapshot_json'] = CanonicalJson::encode([
                    'relationship_reference' => 'employment:119',
                ]);
                return $row;
            },
        );
        $expectedPeople = [[
            'employee_id' => 117,
            'input_snapshot' => ['employee_id' => 117],
            'relationships' => [[
                'employment_id' => 119,
                'input_snapshot' => ['employment_id' => 119],
                'result_snapshot' => [
                    'relationship_reference' => 'employment:119',
                ],
                'result_status' => 'calculated',
            ]],
            'result_snapshot' => ['person_reference' => 'employee:117'],
            'result_status' => 'calculated',
        ]];

        self::assertSame(
            PayrollStatutoryResultSetHash::calculate(
                'social_insurance',
                ['employee_id' => 117],
                $expectedPeople,
                ['person_reference' => 'employee:117'],
                'calculated',
                str_repeat('a', 64),
                'cz-social-2026.1',
                'payroll-social-result.v1',
            ),
            $restored['header']['result_set_hash'],
        );
        self::assertSame(131, $restored['header']['id']);
        self::assertSame(107, $restored['header']['supplier_id']);
        self::assertSame(131, $restored['people'][0]['statutory_result_id']);
        self::assertSame(107, $restored['people'][0]['supplier_id']);
        self::assertSame(
            131,
            $restored['relationships'][0]['statutory_result_id'],
        );
        self::assertSame(
            151,
            $restored['relationships'][0]['revision_id'],
        );
    }

    public function testChecksSourceAggregateBeforeCallingMappers(): void
    {
        $calls = 0;
        $source = $this->header();
        $source['result_set_hash'] = str_repeat('f', 64);

        try {
            CompanyBackupPayrollStatutoryResultSetResealer::reseal(
                $source,
                $source,
                [$this->person()],
                [$this->relationship()],
                static function (array $row) use (&$calls): array {
                    $calls++;
                    return $row;
                },
                static function (array $row) use (&$calls): array {
                    $calls++;
                    return $row;
                },
            );
            self::fail('Neplatný zdrojový agregát nesmí být přemapován.');
        } catch (\RuntimeException) {
            self::assertSame(0, $calls);
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
            'input_snapshot_json' => CanonicalJson::encode(['employee_id' => 17]),
            'result_snapshot_json' => CanonicalJson::encode([
                'person_reference' => 'employee:17',
            ]),
            'result_set_hash' => str_repeat('0', 64),
        ];
    }

    /** @return array<string,mixed> */
    private function person(): array
    {
        return [
            'id' => 41,
            'supplier_id' => 7,
            'statutory_result_id' => 31,
            'revision_id' => 51,
            'calculation_kind' => 'social_insurance',
            'employee_id' => 17,
            'result_status' => 'calculated',
            'input_snapshot_json' => CanonicalJson::encode(['employee_id' => 17]),
            'result_snapshot_json' => CanonicalJson::encode([
                'person_reference' => 'employee:17',
            ]),
        ];
    }

    /** @return array<string,mixed> */
    private function relationship(): array
    {
        return [
            'id' => 61,
            'supplier_id' => 7,
            'statutory_result_id' => 31,
            'person_result_id' => 41,
            'revision_id' => 51,
            'calculation_kind' => 'social_insurance',
            'employee_id' => 17,
            'employment_id' => 19,
            'result_status' => 'calculated',
            'input_snapshot_json' => CanonicalJson::encode([
                'employment_id' => 19,
            ]),
            'result_snapshot_json' => CanonicalJson::encode([
                'relationship_reference' => 'employment:19',
            ]),
        ];
    }
}
