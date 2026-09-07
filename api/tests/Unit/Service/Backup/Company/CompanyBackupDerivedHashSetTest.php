<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup\Company;

use MyInvoice\Service\Backup\CanonicalJson;
use MyInvoice\Service\Backup\Company\CompanyBackupDataSourceException;
use MyInvoice\Service\Backup\Company\CompanyBackupDerivedHashSet;
use PHPUnit\Framework\TestCase;

final class CompanyBackupDerivedHashSetTest extends TestCase
{
    public function testValidatesAndRefreshesCanonicalJsonHashAfterRemap(): void
    {
        $hashes = CompanyBackupDerivedHashSet::fromArray(
            [[
                'algorithm' => 'sha256_canonical_json',
                'hash_column' => 'payload_hash',
                'nullable' => false,
                'source_column' => 'payload_json',
            ]],
            'table:synthetic_snapshots',
            ['id', 'payload_json', 'payload_hash'],
        );
        $json = CanonicalJson::encode([
            'employee_id' => 17,
            'schema_version' => 'synthetic.v1',
        ]);
        $source = [
            'id' => 3,
            'payload_json' => $json,
            'payload_hash' => hash('sha256', $json),
        ];

        $hashes->assertSourceRow($source);
        $restored = $hashes->transform(
            $source,
            static function (array $changed): array {
                $changed['payload_json'] = CanonicalJson::encode([
                    'employee_id' => 117,
                    'schema_version' => 'synthetic.v1',
                ]);
                return $changed;
            },
        );

        self::assertSame(
            hash('sha256', (string) $restored['payload_json']),
            $restored['payload_hash'],
        );
        self::assertSame(117, json_decode(
            (string) $restored['payload_json'],
            true,
            flags: JSON_THROW_ON_ERROR,
        )['employee_id']);
        self::assertSame(hash('sha256', $json), $source['payload_hash']);
    }

    public function testRefreshesCrossColumnHashDependencyInTopologicalOrder(): void
    {
        $hashes = CompanyBackupDerivedHashSet::fromArray(
            [
                [
                    'algorithm' => 'sha256_canonical_json',
                    'hash_column' => 'input_snapshot_hash',
                    'nullable' => false,
                    'source_column' => 'input_snapshot_json',
                ],
                [
                    'algorithm' => 'sha256_canonical_json',
                    'dependencies' => [[
                        'path' => ['source_snapshot_hash'],
                        'source_hash_column' => 'input_snapshot_hash',
                    ]],
                    'hash_column' => 'result_snapshot_hash',
                    'nullable' => true,
                    'source_column' => 'result_snapshot_json',
                ],
            ],
            'table:payroll_run_revisions',
            [
                'input_snapshot_json',
                'input_snapshot_hash',
                'result_snapshot_json',
                'result_snapshot_hash',
            ],
        );
        $input = CanonicalJson::encode(['employee_id' => 17]);
        $inputHash = hash('sha256', $input);
        $result = CanonicalJson::encode([
            'employee_id' => 17,
            'source_snapshot_hash' => $inputHash,
        ]);
        $source = [
            'input_snapshot_json' => $input,
            'input_snapshot_hash' => $inputHash,
            'result_snapshot_json' => $result,
            'result_snapshot_hash' => hash('sha256', $result),
        ];

        $hashes->assertSourceRow($source);
        $restored = $hashes->transform(
            $source,
            static function (array $changed): array {
                $changed['input_snapshot_json'] = CanonicalJson::encode([
                    'employee_id' => 117,
                ]);
                $changed['result_snapshot_json'] = CanonicalJson::encode([
                    'employee_id' => 117,
                    'source_snapshot_hash' => $changed['input_snapshot_hash'],
                ]);
                return $changed;
            },
        );

        $newInputHash = hash('sha256', (string) $restored['input_snapshot_json']);
        $decodedResult = json_decode(
            (string) $restored['result_snapshot_json'],
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        self::assertSame($newInputHash, $restored['input_snapshot_hash']);
        self::assertSame($newInputHash, $decodedResult['source_snapshot_hash']);
        self::assertSame(
            hash('sha256', (string) $restored['result_snapshot_json']),
            $restored['result_snapshot_hash'],
        );

        $tampered = $source;
        $tamperedResult = CanonicalJson::encode([
            'employee_id' => 17,
            'source_snapshot_hash' => str_repeat('0', 64),
        ]);
        $tampered['result_snapshot_json'] = $tamperedResult;
        $tampered['result_snapshot_hash'] = hash('sha256', $tamperedResult);
        try {
            $hashes->assertSourceRow($tampered);
            self::fail('Platná lokální pečeť nesmí skrýt porušenou závislost.');
        } catch (CompanyBackupDataSourceException $e) {
            self::assertSame('data_derived_hash_value_invalid', $e->errorCode);
            self::assertSame('result_snapshot_hash', $e->column);
        }
    }

    public function testValidatesAndRefreshesCanonicalRowProjectionAfterRemap(): void
    {
        $hashes = CompanyBackupDerivedHashSet::fromArray(
            [[
                'algorithm' => 'sha256_canonical_projection',
                'hash_column' => 'record_hash',
                'nullable' => false,
                'projection' => [
                    ['column' => 'employee_id', 'key' => 'employee_id'],
                    ['json_column' => 'evidence_json', 'key' => 'evidence'],
                    [
                        'key' => 'schema_version',
                        'literal' => 'synthetic-record.v1',
                    ],
                    ['column' => 'supplier_id', 'key' => 'supplier_id'],
                    ['json_column' => 'values_json', 'key' => 'values'],
                ],
            ]],
            'table:synthetic_records',
            [
                'supplier_id',
                'employee_id',
                'values_json',
                'evidence_json',
                'record_hash',
            ],
        );
        $values = ['base_minor' => 42_000];
        $evidence = ['confirmed' => true];
        $source = [
            'supplier_id' => 7,
            'employee_id' => 17,
            'values_json' => CanonicalJson::encode($values),
            'evidence_json' => CanonicalJson::encode($evidence),
            'record_hash' => CanonicalJson::sha256([
                'employee_id' => 17,
                'evidence' => $evidence,
                'schema_version' => 'synthetic-record.v1',
                'supplier_id' => 7,
                'values' => $values,
            ]),
        ];

        $hashes->assertSourceRow($source);
        $restored = $hashes->transform(
            $source,
            static function (array $changed): array {
                $changed['supplier_id'] = 107;
                $changed['employee_id'] = 117;
                $changed['values_json'] = CanonicalJson::encode([
                    'base_minor' => 84_000,
                ]);
                return $changed;
            },
        );

        self::assertSame(
            CanonicalJson::sha256([
                'employee_id' => 117,
                'evidence' => $evidence,
                'schema_version' => 'synthetic-record.v1',
                'supplier_id' => 107,
                'values' => ['base_minor' => 84_000],
            ]),
            $restored['record_hash'],
        );

        $tampered = $source;
        $tampered['employee_id'] = 18;
        try {
            $hashes->assertSourceRow($tampered);
            self::fail('Změněný řádek nesmí projít se starou pečetí.');
        } catch (CompanyBackupDataSourceException $e) {
            self::assertSame('data_derived_hash_value_invalid', $e->errorCode);
            self::assertSame('record_hash', $e->column);
        }

        $nonScalar = $source;
        $nonScalar['employee_id'] = ['unexpected' => 17];
        $nonScalar['record_hash'] = CanonicalJson::sha256([
            'employee_id' => ['unexpected' => 17],
            'evidence' => $evidence,
            'schema_version' => 'synthetic-record.v1',
            'supplier_id' => 7,
            'values' => $values,
        ]);
        try {
            $hashes->assertSourceRow($nonScalar);
            self::fail('Skalární pole nesmí přijmout strukturovanou hodnotu.');
        } catch (CompanyBackupDataSourceException $e) {
            self::assertSame('data_derived_hash_value_invalid', $e->errorCode);
            self::assertSame('record_hash', $e->column);
        }
    }

    public function testRefreshesRowProjectionAfterReferencedDerivedHash(): void
    {
        $hashes = CompanyBackupDerivedHashSet::fromArray(
            [
                [
                    'algorithm' => 'sha256_canonical_json',
                    'hash_column' => 'payload_hash',
                    'nullable' => false,
                    'source_column' => 'payload_json',
                ],
                [
                    'algorithm' => 'sha256_canonical_projection',
                    'hash_column' => 'record_hash',
                    'nullable' => false,
                    'projection' => [
                        ['json_column' => 'payload_json', 'key' => 'payload'],
                        ['column' => 'payload_hash', 'key' => 'payload_hash'],
                        ['key' => 'schema_version', 'literal' => 'outer.v1'],
                    ],
                ],
            ],
            'table:synthetic_snapshots',
            ['payload_json', 'payload_hash', 'record_hash'],
        );
        $payload = ['employee_id' => 17];
        $payloadJson = CanonicalJson::encode($payload);
        $payloadHash = hash('sha256', $payloadJson);
        $source = [
            'payload_json' => $payloadJson,
            'payload_hash' => $payloadHash,
            'record_hash' => CanonicalJson::sha256([
                'payload' => $payload,
                'payload_hash' => $payloadHash,
                'schema_version' => 'outer.v1',
            ]),
        ];

        $restored = $hashes->transform(
            $source,
            static function (array $changed): array {
                $changed['payload_json'] = CanonicalJson::encode([
                    'employee_id' => 117,
                ]);
                return $changed;
            },
        );

        $newPayloadHash = hash('sha256', (string) $restored['payload_json']);
        self::assertSame($newPayloadHash, $restored['payload_hash']);
        self::assertSame(
            CanonicalJson::sha256([
                'payload' => ['employee_id' => 117],
                'payload_hash' => $newPayloadHash,
                'schema_version' => 'outer.v1',
            ]),
            $restored['record_hash'],
        );
    }

    public function testRejectsTamperedNonCanonicalAndHalfNullablePairs(): void
    {
        $required = CompanyBackupDerivedHashSet::fromArray(
            [[
                'algorithm' => 'sha256_canonical_json',
                'hash_column' => 'payload_hash',
                'nullable' => false,
                'source_column' => 'payload_json',
            ]],
            'table:synthetic_snapshots',
            ['payload_json', 'payload_hash'],
        );
        $nullable = CompanyBackupDerivedHashSet::fromArray(
            [[
                'algorithm' => 'sha256_canonical_json',
                'hash_column' => 'result_hash',
                'nullable' => true,
                'source_column' => 'result_json',
            ]],
            'table:synthetic_snapshots',
            ['result_json', 'result_hash'],
        );

        foreach (
            [
                [$required, [
                    'payload_json' => '{"employee_id":17}',
                    'payload_hash' => str_repeat('0', 64),
                ]],
                [$required, [
                    'payload_json' => '{"schema_version":"v1","employee_id":17}',
                    'payload_hash' => hash(
                        'sha256',
                        '{"schema_version":"v1","employee_id":17}',
                    ),
                ]],
                [$nullable, ['result_json' => null, 'result_hash' => str_repeat('a', 64)]],
                [$nullable, ['result_json' => '{}', 'result_hash' => null]],
            ] as [$hashes, $row]
        ) {
            try {
                $hashes->assertSourceRow($row);
                self::fail('Poškozená odvozená pečeť nesmí projít exportem.');
            } catch (CompanyBackupDataSourceException $e) {
                self::assertSame('data_derived_hash_value_invalid', $e->errorCode);
            }
        }
    }

    public function testRejectsNonCanonicalOrUnboundMetadata(): void
    {
        $first = [
            'algorithm' => 'sha256_canonical_json',
            'hash_column' => 'a_hash',
            'nullable' => false,
            'source_column' => 'a_json',
        ];
        $second = [
            'algorithm' => 'sha256_canonical_json',
            'hash_column' => 'b_hash',
            'nullable' => true,
            'source_column' => 'b_json',
        ];

        foreach (
            [
                [[$second, $first], ['a_json', 'a_hash', 'b_json', 'b_hash']],
                [[$first, $first], ['a_json', 'a_hash']],
                [[$first], ['a_json']],
                [[[
                    ...$first,
                    'hash_column' => 'a_json',
                ]], ['a_json', 'a_hash']],
            ] as [$metadata, $columns]
        ) {
            try {
                CompanyBackupDerivedHashSet::fromArray(
                    $metadata,
                    'table:synthetic_snapshots',
                    $columns,
                );
                self::fail('Odvozený hash musí mít jednoznačný kanonický kontrakt.');
            } catch (CompanyBackupDataSourceException $e) {
                self::assertSame('data_derived_hash_metadata_invalid', $e->errorCode);
            }
        }
    }

    public function testRejectsInvalidCrossColumnHashDependencyMetadata(): void
    {
        $input = [
            'algorithm' => 'sha256_canonical_json',
            'hash_column' => 'input_hash',
            'nullable' => false,
            'source_column' => 'input_json',
        ];
        $result = [
            'algorithm' => 'sha256_canonical_json',
            'dependencies' => [[
                'path' => ['source_hash'],
                'source_hash_column' => 'input_hash',
            ]],
            'hash_column' => 'result_hash',
            'nullable' => false,
            'source_column' => 'result_json',
        ];
        $columns = ['input_json', 'input_hash', 'result_json', 'result_hash'];

        foreach (
            [
                [
                    $input,
                    [...$result, 'dependencies' => []],
                ],
                [
                    $input,
                    [...$result, 'dependencies' => [[
                        'path' => ['source_hash'],
                        'source_hash_column' => 'missing_hash',
                    ]]],
                ],
                [
                    $input,
                    [...$result, 'dependencies' => [[
                        'path' => ['source_hash'],
                        'source_hash_column' => 'result_hash',
                    ]]],
                ],
                [
                    [...$input, 'dependencies' => [[
                        'path' => ['source_hash'],
                        'source_hash_column' => 'result_hash',
                    ]]],
                    $result,
                ],
            ] as $metadata
        ) {
            try {
                CompanyBackupDerivedHashSet::fromArray(
                    $metadata,
                    'table:synthetic_snapshots',
                    $columns,
                );
                self::fail('Závislosti odvozených hashů musí tvořit platný DAG.');
            } catch (CompanyBackupDataSourceException $e) {
                self::assertSame('data_derived_hash_metadata_invalid', $e->errorCode);
            }
        }
    }

    public function testRejectsInvalidCanonicalRowProjectionMetadata(): void
    {
        $valid = [
            'algorithm' => 'sha256_canonical_projection',
            'hash_column' => 'record_hash',
            'nullable' => false,
            'projection' => [
                ['column' => 'employee_id', 'key' => 'employee_id'],
                ['key' => 'schema_version', 'literal' => 'synthetic.v1'],
            ],
        ];
        $columns = ['employee_id', 'record_hash'];

        foreach (
            [
                [...$valid, 'nullable' => true],
                [...$valid, 'projection' => []],
                [...$valid, 'projection' => [
                    ['column' => 'employee_id', 'key' => 'employee_id'],
                    ['column' => 'employee_id', 'key' => 'employee_id'],
                ]],
                [...$valid, 'projection' => [
                    ['column' => 'missing_id', 'key' => 'employee_id'],
                ]],
                [...$valid, 'source_column' => 'employee_id'],
                [
                    'algorithm' => 'sha256_canonical_json',
                    'hash_column' => 'record_hash',
                    'nullable' => false,
                    'projection' => [
                        ['column' => 'employee_id', 'key' => 'employee_id'],
                    ],
                ],
            ] as $metadata
        ) {
            try {
                CompanyBackupDerivedHashSet::fromArray(
                    [$metadata],
                    'table:synthetic_records',
                    $columns,
                );
                self::fail('Řádková projekce musí mít jednoznačný kontrakt.');
            } catch (CompanyBackupDataSourceException $e) {
                self::assertSame('data_derived_hash_metadata_invalid', $e->errorCode);
            }
        }
    }
}
