<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup\Company;

use MyInvoice\Service\Backup\CanonicalJson;
use MyInvoice\Service\Backup\Company\CompanyBackupDataSourceException;
use MyInvoice\Service\Backup\Company\CompanyBackupEmbeddedHashSet;
use PHPUnit\Framework\TestCase;

final class CompanyBackupEmbeddedHashSetTest extends TestCase
{
    public function testRefreshesNestedHashChainInDependencyOrder(): void
    {
        $hashes = CompanyBackupEmbeddedHashSet::fromArray(
            [
                [
                    'algorithm' => 'sha256_canonical_json',
                    'column' => 'payload_json',
                    'dependencies' => [],
                    'hash_path' => [
                        'people', '*', 'inputs', '*', 'component_snapshot_hash',
                    ],
                    'name' => 'component_snapshot',
                    'nullable' => false,
                    'omit_paths' => [],
                    'source_path' => ['people', '*', 'inputs', '*', 'component'],
                ],
                [
                    'algorithm' => 'sha256_canonical_json',
                    'column' => 'payload_json',
                    'dependencies' => ['jmhz_source_snapshot'],
                    'hash_path' => ['people', '*', 'work_summary', 'summary_sha256'],
                    'name' => 'jmhz_final_summary',
                    'nullable' => true,
                    'omit_paths' => [
                        ['id'],
                        ['source_snapshot_json'],
                        ['summary_sha256'],
                        ['time_month_revision_no'],
                    ],
                    'source_path' => ['people', '*', 'work_summary'],
                ],
                [
                    'algorithm' => 'sha256_exact_string',
                    'column' => 'payload_json',
                    'dependencies' => [],
                    'hash_path' => [
                        'people', '*', 'work_summary', 'source_snapshot_sha256',
                    ],
                    'name' => 'jmhz_source_snapshot',
                    'nullable' => true,
                    'omit_paths' => [],
                    'source_path' => [
                        'people', '*', 'work_summary', 'source_snapshot_json',
                    ],
                ],
            ],
            'table:payroll_run_revisions',
            ['payload_json'],
        );
        $component = ['code' => 'base_wage', 'component_id' => 17];
        $sourceJson = CanonicalJson::encode([
            'employment' => ['id' => 19],
            'schema_version' => 'jmhz-work-month.v2',
        ]);
        $sourceHash = hash('sha256', $sourceJson);
        $summaryPayload = [
            'derivation_version' => 'jmhz-work-month.v2',
            'source_snapshot_sha256' => $sourceHash,
            'values' => ['worked_millihours' => 160000],
        ];
        $summary = $summaryPayload + [
            'id' => 23,
            'source_snapshot_json' => $sourceJson,
            'summary_sha256' => hash('sha256', CanonicalJson::encode($summaryPayload)),
            'time_month_revision_no' => 1,
        ];
        $payload = CanonicalJson::encode([
            'people' => [[
                'inputs' => [[
                    'component' => $component,
                    'component_snapshot_hash' => hash(
                        'sha256',
                        CanonicalJson::encode($component),
                    ),
                ]],
                'work_summary' => $summary,
            ]],
        ]);
        $row = ['payload_json' => $payload];

        $hashes->assertSourceRow($row);
        $restored = $hashes->transform(
            $row,
            static function (array $changed): array {
                $payload = json_decode(
                    (string) $changed['payload_json'],
                    true,
                    flags: JSON_THROW_ON_ERROR,
                );
                $payload['people'][0]['inputs'][0]['component']['component_id'] = 117;
                $source = json_decode(
                    $payload['people'][0]['work_summary']['source_snapshot_json'],
                    true,
                    flags: JSON_THROW_ON_ERROR,
                );
                $source['employment']['id'] = 119;
                $payload['people'][0]['work_summary']['source_snapshot_json'] =
                    CanonicalJson::encode($source);
                $changed['payload_json'] = CanonicalJson::encode($payload);
                return $changed;
            },
        );

        $result = json_decode(
            (string) $restored['payload_json'],
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $input = $result['people'][0]['inputs'][0];
        $workSummary = $result['people'][0]['work_summary'];
        self::assertSame(
            hash('sha256', CanonicalJson::encode($input['component'])),
            $input['component_snapshot_hash'],
        );
        self::assertSame(
            hash('sha256', $workSummary['source_snapshot_json']),
            $workSummary['source_snapshot_sha256'],
        );
        $expectedSummary = $workSummary;
        unset(
            $expectedSummary['id'],
            $expectedSummary['source_snapshot_json'],
            $expectedSummary['summary_sha256'],
            $expectedSummary['time_month_revision_no'],
        );
        self::assertSame(
            hash('sha256', CanonicalJson::encode($expectedSummary)),
            $workSummary['summary_sha256'],
        );
    }

    public function testValidatesHashOfObjectWithoutItsOwnSeal(): void
    {
        $hashes = CompanyBackupEmbeddedHashSet::fromArray(
            [[
                'algorithm' => 'sha256_canonical_json',
                'column' => 'payload_json',
                'dependencies' => [],
                'hash_path' => ['state', 'snapshot_hash'],
                'name' => 'statutory_accumulator_state',
                'nullable' => false,
                'omit_paths' => [['snapshot_hash']],
                'source_path' => ['state'],
            ]],
            'table:payroll_run_revisions',
            ['payload_json'],
        );
        $state = [
            'employee_id' => 17,
            'schema_version' => 'payroll-statutory-accumulator-state.v1',
        ];
        $sealed = $state + [
            'snapshot_hash' => hash('sha256', CanonicalJson::encode($state)),
        ];

        $hashes->assertSourceRow([
            'payload_json' => CanonicalJson::encode(['state' => $sealed]),
        ]);
        self::addToAssertionCount(1);
    }

    public function testRefreshesCanonicalProjectionFromAncestorAndEntry(): void
    {
        $hashes = CompanyBackupEmbeddedHashSet::fromArray(
            [[
                'algorithm' => 'sha256_canonical_projection',
                'column' => 'payload_json',
                'dependencies' => [],
                'hash_path' => ['records', '*', 'record_hash'],
                'name' => 'synthetic_record',
                'nullable' => false,
                'omit_paths' => [],
                'projection' => [
                    [
                        'key' => 'record_id',
                        'path' => ['records', '*', 'id'],
                    ],
                    [
                        'key' => 'schema_version',
                        'literal' => 'synthetic-record.v1',
                    ],
                    [
                        'key' => 'supplier_id',
                        'path' => ['supplier_id'],
                    ],
                    [
                        'key' => 'values',
                        'path' => ['records', '*', 'values'],
                    ],
                ],
                'source_path' => ['records', '*'],
            ]],
            'table:payroll_run_revisions',
            ['payload_json'],
        );
        $recordPayload = [
            'record_id' => 17,
            'schema_version' => 'synthetic-record.v1',
            'supplier_id' => 7,
            'values' => ['amount_minor' => 1500],
        ];
        $row = [
            'payload_json' => CanonicalJson::encode([
                'records' => [[
                    'created_at' => '2026-06-01 10:00:00',
                    'id' => 17,
                    'record_hash' => hash(
                        'sha256',
                        CanonicalJson::encode($recordPayload),
                    ),
                    'values' => $recordPayload['values'],
                ]],
                'supplier_id' => 7,
            ]),
        ];

        $hashes->assertSourceRow($row);
        $restored = $hashes->transform(
            $row,
            static function (array $changed): array {
                $payload = json_decode(
                    (string) $changed['payload_json'],
                    true,
                    flags: JSON_THROW_ON_ERROR,
                );
                $payload['supplier_id'] = 107;
                $payload['records'][0]['id'] = 117;
                $changed['payload_json'] = CanonicalJson::encode($payload);
                return $changed;
            },
        );

        $payload = json_decode(
            (string) $restored['payload_json'],
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        self::assertSame(
            hash('sha256', CanonicalJson::encode([
                ...$recordPayload,
                'record_id' => 117,
                'supplier_id' => 107,
            ])),
            $payload['records'][0]['record_hash'],
        );
    }

    public function testAllowsCompleteNullablePairsAndRejectsOrphanSeal(): void
    {
        $hashes = CompanyBackupEmbeddedHashSet::fromArray(
            [[
                'algorithm' => 'sha256_exact_string',
                'column' => 'payload_json',
                'dependencies' => [],
                'hash_path' => ['people', '*', 'summary', 'source_hash'],
                'name' => 'source_snapshot',
                'nullable' => true,
                'omit_paths' => [],
                'source_path' => ['people', '*', 'summary', 'source_json'],
            ]],
            'table:payroll_run_revisions',
            ['payload_json'],
        );

        foreach (
            [
                ['people' => [['summary' => null]]],
                ['people' => [[]]],
                ['people' => [['summary' => [
                    'source_hash' => null,
                    'source_json' => null,
                ]]]],
            ] as $payload
        ) {
            $hashes->assertSourceRow([
                'payload_json' => CanonicalJson::encode($payload),
            ]);
            self::addToAssertionCount(1);
        }

        try {
            $hashes->assertSourceRow([
                'payload_json' => CanonicalJson::encode([
                    'people' => [['summary' => [
                        'source_hash' => str_repeat('a', 64),
                    ]]],
                ]),
            ]);
            self::fail('Samostatná nullable pečeť bez zdroje nesmí projít exportem.');
        } catch (CompanyBackupDataSourceException $e) {
            self::assertSame('data_embedded_hash_value_invalid', $e->errorCode);
        }
    }

    public function testRejectsNonCanonicalOuterJsonAndInvalidHashSourceType(): void
    {
        $hashes = CompanyBackupEmbeddedHashSet::fromArray(
            [[
                'algorithm' => 'sha256_canonical_json',
                'column' => 'payload_json',
                'dependencies' => [],
                'hash_path' => ['component_hash'],
                'name' => 'component',
                'nullable' => false,
                'omit_paths' => [],
                'source_path' => ['component'],
            ]],
            'table:payroll_run_revisions',
            ['payload_json'],
        );
        foreach (
            [
                '{"component":{"component_id":17}, "component_hash":"'
                    . str_repeat('a', 64) . '"}',
                CanonicalJson::encode([
                    'component' => 17,
                    'component_hash' => str_repeat('a', 64),
                ]),
            ] as $payload
        ) {
            try {
                $hashes->assertSourceRow(['payload_json' => $payload]);
                self::fail('Nekanonický nebo netypovaný zdroj pečeti nesmí projít.');
            } catch (CompanyBackupDataSourceException $e) {
                self::assertSame('data_embedded_hash_value_invalid', $e->errorCode);
            }
        }
    }

    public function testRejectsAmbiguousCanonicalProjectionMetadata(): void
    {
        $valid = [
            'algorithm' => 'sha256_canonical_projection',
            'column' => 'payload_json',
            'dependencies' => [],
            'hash_path' => ['records', '*', 'record_hash'],
            'name' => 'synthetic_record',
            'nullable' => false,
            'omit_paths' => [],
            'projection' => [
                [
                    'key' => 'schema_version',
                    'literal' => 'synthetic-record.v1',
                ],
                [
                    'key' => 'supplier_id',
                    'path' => ['supplier_id'],
                ],
            ],
            'source_path' => ['records', '*'],
        ];
        $withoutProjection = $valid;
        unset($withoutProjection['projection']);

        foreach (
            [
                $withoutProjection,
                [...$valid, 'algorithm' => 'sha256_canonical_json'],
                [...$valid, 'omit_paths' => [['record_hash']]],
                [...$valid, 'projection' => array_reverse($valid['projection'])],
                [...$valid, 'projection' => [
                    $valid['projection'][0],
                    $valid['projection'][0],
                ]],
                [...$valid, 'projection' => [[
                    'key' => 'record',
                    'path' => ['records', '*'],
                ]]],
                [...$valid, 'projection' => [[
                    'key' => 'foreign_id',
                    'path' => ['other_records', '*', 'id'],
                ]]],
                [...$valid, 'projection' => [[
                    'key' => 'schema_version',
                    'literal' => ['synthetic-record.v1'],
                ]]],
            ] as $metadata
        ) {
            try {
                CompanyBackupEmbeddedHashSet::fromArray(
                    [$metadata],
                    'table:payroll_run_revisions',
                    ['payload_json'],
                );
                self::fail('Kanonická projekce musí mít přesný bezpečný kontrakt.');
            } catch (CompanyBackupDataSourceException $e) {
                self::assertSame('data_embedded_hash_metadata_invalid', $e->errorCode);
            }
        }
    }

    public function testRejectsTamperedNestedSealAndInvalidMetadataGraph(): void
    {
        $valid = [
            'algorithm' => 'sha256_canonical_json',
            'column' => 'payload_json',
            'dependencies' => [],
            'hash_path' => ['component_hash'],
            'name' => 'component',
            'nullable' => false,
            'omit_paths' => [],
            'source_path' => ['component'],
        ];
        $hashes = CompanyBackupEmbeddedHashSet::fromArray(
            [$valid],
            'table:payroll_run_revisions',
            ['payload_json'],
        );
        $component = ['component_id' => 17];
        try {
            $hashes->assertSourceRow([
                'payload_json' => CanonicalJson::encode([
                    'component' => $component,
                    'component_hash' => str_repeat('0', 64),
                ]),
            ]);
            self::fail('Lokálně poškozená vnořená pečeť nesmí projít exportem.');
        } catch (CompanyBackupDataSourceException $e) {
            self::assertSame('data_embedded_hash_value_invalid', $e->errorCode);
            self::assertSame('payload_json', $e->column);
        }

        $dependent = [
            ...$valid,
            'dependencies' => ['missing'],
            'hash_path' => ['result_hash'],
            'name' => 'result',
            'source_path' => ['result'],
        ];
        foreach (
            [
                [[$dependent], ['payload_json']],
                [[
                    [...$valid, 'dependencies' => ['result']],
                    [...$dependent, 'dependencies' => ['component']],
                ], ['payload_json']],
                [[$valid], ['other_column']],
                [[[
                    ...$valid,
                    'hash_path' => ['component', 'component_hash'],
                ]], ['payload_json']],
                [[[
                    ...$valid,
                    'hash_path' => ['components', '*', 'component_hash'],
                ]], ['payload_json']],
                [[[
                    ...$valid,
                    'hash_path' => ['groups', '*', 'component_hash'],
                    'source_path' => ['components', '*', 'component'],
                ]], ['payload_json']],
                [[[
                    ...$valid,
                    'algorithm' => 'sha256_exact_string',
                    'hash_path' => ['component', 'component_hash'],
                    'omit_paths' => [['component_hash']],
                ]], ['payload_json']],
            ] as [$metadata, $columns]
        ) {
            try {
                CompanyBackupEmbeddedHashSet::fromArray(
                    $metadata,
                    'table:payroll_run_revisions',
                    $columns,
                );
                self::fail('Vnořené pečetě musí mít úplný acyklický kontrakt.');
            } catch (CompanyBackupDataSourceException $e) {
                self::assertSame('data_embedded_hash_metadata_invalid', $e->errorCode);
            }
        }
    }
}
