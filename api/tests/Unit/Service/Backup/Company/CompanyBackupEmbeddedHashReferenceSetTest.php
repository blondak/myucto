<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup\Company;

use MyInvoice\Service\Backup\CanonicalJson;
use MyInvoice\Service\Backup\Company\CompanyBackupDataSourceException;
use MyInvoice\Service\Backup\Company\CompanyBackupEmbeddedHashReference;
use MyInvoice\Service\Backup\Company\CompanyBackupEmbeddedHashReferenceSet;
use MyInvoice\Service\Backup\Registry\TenantDataDefinition;
use MyInvoice\Service\Backup\Registry\TenantDataObjectKind;
use MyInvoice\Service\Backup\Registry\TenantDataPolicy;
use MyInvoice\Service\Backup\Registry\TenantDataRegistry;
use PHPUnit\Framework\TestCase;

final class CompanyBackupEmbeddedHashReferenceSetTest extends TestCase
{
    public function testRemapsCrossRowHashReferenceInsideCanonicalJson(): void
    {
        $references = CompanyBackupEmbeddedHashReferenceSet::fromArray(
            [$this->reference()],
            'table:payroll_run_revisions',
            ['input_snapshot_json'],
        );
        $sourceHash = str_repeat('a', 64);
        $targetHash = str_repeat('b', 64);
        $row = [
            'input_snapshot_json' => CanonicalJson::encode([
                'people' => [[
                    'statutory_accumulators' => [
                        'income_tax' => [
                            'state' => [
                                'approved_results' => [[
                                    'source_result_hash' => $sourceHash,
                                ]],
                            ],
                        ],
                        'social_insurance' => ['state' => null],
                    ],
                ]],
            ]),
        ];

        $references->assertSourceRow($row);
        $restored = $references->remap(
            $row,
            static function (
                CompanyBackupEmbeddedHashReference $reference,
                string $hash,
            ) use ($sourceHash, $targetHash): string {
                self::assertSame(
                    'table:payroll_statutory_person_results',
                    $reference->target,
                );
                self::assertSame('result_snapshot_hash', $reference->targetHashColumn);
                self::assertSame($sourceHash, $hash);
                return $targetHash;
            },
        );

        $payload = json_decode(
            (string) $restored['input_snapshot_json'],
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        self::assertSame(
            $targetHash,
            $payload['people'][0]['statutory_accumulators']['income_tax']
                ['state']['approved_results'][0]['source_result_hash'],
        );
        self::assertNull(
            $payload['people'][0]['statutory_accumulators']['social_insurance']
                ['state'],
        );
        self::assertSame(
            'input_snapshot_json:people.*.statutory_accumulators.*.state'
                . '.approved_results.*.source_result_hash'
                . '->payroll_statutory_person_results:result_snapshot_hash?',
            $references->references[0]->signature(),
        );
    }

    public function testFailsClosedForMissingOrInvalidHashMapping(): void
    {
        $references = CompanyBackupEmbeddedHashReferenceSet::fromArray(
            [$this->reference()],
            'table:payroll_run_revisions',
            ['input_snapshot_json'],
        );
        $row = $this->rowWithHash(str_repeat('a', 64));

        foreach ([null, str_repeat('A', 64), 'not-a-hash'] as $mapped) {
            try {
                $references->remap(
                    $row,
                    static fn (): mixed => $mapped,
                );
                self::fail('Neúplná hashová mapa musí obnovu zastavit.');
            } catch (CompanyBackupDataSourceException $e) {
                self::assertSame(
                    'data_embedded_hash_reference_value_invalid',
                    $e->errorCode,
                );
                self::assertSame('input_snapshot_json', $e->column);
            }
        }
    }

    public function testRejectsTamperedSourceHashAndNonCanonicalJson(): void
    {
        $references = CompanyBackupEmbeddedHashReferenceSet::fromArray(
            [$this->reference()],
            'table:payroll_run_revisions',
            ['input_snapshot_json'],
        );

        foreach (
            [
                $this->rowWithHash(str_repeat('A', 64)),
                $this->rowWithHash('not-a-hash'),
                ['input_snapshot_json' => '{"z":1,"a":2}'],
            ] as $row
        ) {
            try {
                $references->assertSourceRow($row);
                self::fail('Poškozená hashová reference nesmí projít preflightem.');
            } catch (CompanyBackupDataSourceException $e) {
                self::assertSame(
                    'data_embedded_hash_reference_value_invalid',
                    $e->errorCode,
                );
            }
        }
    }

    public function testValidatesTargetAsDeclaredDerivedHash(): void
    {
        $references = CompanyBackupEmbeddedHashReferenceSet::fromArray(
            [$this->reference()],
            'table:payroll_run_revisions',
            ['input_snapshot_json'],
        );
        $references->assertRegistryTargets(new TenantDataRegistry(1, [
            $this->targetDefinition(withDerivedHash: true),
        ]));

        try {
            $references->assertRegistryTargets(new TenantDataRegistry(1, [
                $this->targetDefinition(withDerivedHash: false),
            ]));
            self::fail('Hashová reference smí mířit jen na odvoditelný cílový hash.');
        } catch (CompanyBackupDataSourceException $e) {
            self::assertSame(
                'data_embedded_hash_reference_target_invalid',
                $e->errorCode,
            );
            self::assertSame('input_snapshot_json', $e->column);
        }
    }

    public function testRejectsNonCanonicalDuplicateOrUnboundMetadata(): void
    {
        $first = $this->reference();
        $second = [
            ...$first,
            'path' => ['z_hash'],
        ];

        foreach (
            [
                [[$second, $first], ['input_snapshot_json']],
                [[$first, $first], ['input_snapshot_json']],
                [[$first], ['different_json']],
                [[[
                    ...$first,
                    'target_hash_column' => 'not-valid!',
                ]], ['input_snapshot_json']],
            ] as [$metadata, $columns]
        ) {
            try {
                CompanyBackupEmbeddedHashReferenceSet::fromArray(
                    $metadata,
                    'table:payroll_run_revisions',
                    $columns,
                );
                self::fail('Hashová reference musí mít kanonická úplná metadata.');
            } catch (CompanyBackupDataSourceException $e) {
                self::assertContains($e->errorCode, [
                    'data_embedded_hash_reference_duplicate',
                    'data_embedded_hash_reference_metadata_invalid',
                    'data_embedded_hash_reference_source_not_exported',
                ]);
            }
        }
    }

    /** @return array<string,mixed> */
    private function reference(): array
    {
        return [
            'column' => 'input_snapshot_json',
            'nullable' => true,
            'path' => [
                'people', '*', 'statutory_accumulators', '*', 'state',
                'approved_results', '*', 'source_result_hash',
            ],
            'target' => 'table:payroll_statutory_person_results',
            'target_hash_column' => 'result_snapshot_hash',
        ];
    }

    /** @return array{input_snapshot_json:string} */
    private function rowWithHash(string $hash): array
    {
        return [
            'input_snapshot_json' => CanonicalJson::encode([
                'people' => [[
                    'statutory_accumulators' => [[
                        'state' => [
                            'approved_results' => [[
                                'source_result_hash' => $hash,
                            ]],
                        ],
                    ]],
                ]],
            ]),
        ];
    }

    private function targetDefinition(bool $withDerivedHash): TenantDataDefinition
    {
        return new TenantDataDefinition(
            'table:payroll_statutory_person_results',
            TenantDataObjectKind::Table,
            TenantDataPolicy::TenantOwned,
            [TenantDataRegistry::COMPANY_BACKUP_PROFILE],
            [
                'primary_key' => ['id'],
                'ownership' => [
                    'strategy' => 'supplier_id',
                    'column' => 'supplier_id',
                ],
                'secrets' => [],
                'company_backup' => [
                    'data_columns' => [
                        'id',
                        'supplier_id',
                        'result_snapshot_json',
                        'result_snapshot_hash',
                    ],
                    ...($withDerivedHash ? [
                        'derived_hashes' => [[
                            'algorithm' => 'sha256_canonical_json',
                            'hash_column' => 'result_snapshot_hash',
                            'nullable' => false,
                            'source_column' => 'result_snapshot_json',
                        ]],
                    ] : []),
                    'embedded_references' => [],
                    'generated_columns' => [],
                    'omit_columns' => [],
                    'references' => [[
                        'columns' => ['supplier_id'],
                        'constraint' => 'required',
                        'fallbacks' => [],
                        'mapping' => 'tenant_id',
                        'nullable_columns' => [],
                        'target' => 'table:supplier',
                        'target_columns' => ['id'],
                    ]],
                    'restore_overrides' => [],
                ],
            ],
        );
    }
}
