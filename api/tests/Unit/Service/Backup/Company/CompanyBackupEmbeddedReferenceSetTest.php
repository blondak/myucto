<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup\Company;

use MyInvoice\Service\Backup\Company\CompanyBackupDataSourceException;
use MyInvoice\Service\Backup\Company\CompanyBackupEmbeddedReference;
use MyInvoice\Service\Backup\Company\CompanyBackupEmbeddedReferenceSet;
use MyInvoice\Service\Backup\Company\CompanyBackupReferenceMapping;
use MyInvoice\Service\Backup\Registry\TenantDataDefinition;
use MyInvoice\Service\Backup\Registry\TenantDataObjectKind;
use MyInvoice\Service\Backup\Registry\TenantDataPolicy;
use MyInvoice\Service\Backup\Registry\TenantDataRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CompanyBackupEmbeddedReferenceSetTest extends TestCase
{
    public function testRemapsWildcardIdsInsideJsonColumnWithoutChangingSourceRow(): void
    {
        $references = CompanyBackupEmbeddedReferenceSet::fromArray(
            [$this->journalEntryReference()],
            'table:accounting_closing_steps',
            ['id', 'payload'],
        );
        $references->assertRegistryTargets($this->targetRegistry());
        $source = [
            'id' => 3,
            'payload' => '{"entries":[{"entry_id":17},{"entry_id":19}],"total":2}',
        ];

        $restored = $references->remap(
            $source,
            static function (
                CompanyBackupEmbeddedReference $reference,
                int|string $sourceValue,
            ): int {
                self::assertSame('table:journal_entries', $reference->target);
                return (int) $sourceValue + 100;
            },
        );

        self::assertSame(
            [
                'entries' => [['entry_id' => 117], ['entry_id' => 119]],
                'total' => 2,
            ],
            json_decode((string) $restored['payload'], true, flags: JSON_THROW_ON_ERROR),
        );
        self::assertSame(17, json_decode(
            (string) $source['payload'],
            true,
            flags: JSON_THROW_ON_ERROR,
        )['entries'][0]['entry_id']);
        self::assertSame(
            'payload:entries.*.entry_id->journal_entries:id',
            $references->references[0]->signature(),
        );
    }

    public function testKeepsNullableValueAndMissingVariantUntouched(): void
    {
        $metadata = $this->journalEntryReference();
        $metadata['nullable'] = true;
        $references = CompanyBackupEmbeddedReferenceSet::fromArray(
            [$metadata],
            'table:accounting_closing_steps',
            ['payload'],
        );
        $calls = 0;

        $withNull = $references->remap(
            ['payload' => ['entries' => [['entry_id' => null]]]],
            static function () use (&$calls): int {
                $calls++;
                return 1;
            },
        );
        $withoutVariant = $references->remap(
            ['payload' => ['reason' => 'not_applicable']],
            static function () use (&$calls): int {
                $calls++;
                return 1;
            },
        );

        self::assertNull($withNull['payload']['entries'][0]['entry_id']);
        self::assertSame(['reason' => 'not_applicable'], $withoutVariant['payload']);
        self::assertSame(0, $calls);
    }

    public function testRemapsPrefixedDecimalIdentityThroughNumericTargetId(): void
    {
        $metadata = [
            ...$this->journalEntryReference(),
            'path' => ['people', '*', 'person_reference'],
            'target' => 'table:payroll_employees',
            'value_prefix' => 'employee:',
        ];
        $references = CompanyBackupEmbeddedReferenceSet::fromArray(
            [$metadata],
            'table:payroll_run_revisions',
            ['payload'],
        );
        $references->assertRegistryTargets(new TenantDataRegistry(1, [
            $this->definition('payroll_employees', TenantDataPolicy::TenantOwned),
        ]));

        $restored = $references->remap(
            ['payload' => '{"people":[{"person_reference":"employee:17"}]}'],
            static function (
                CompanyBackupEmbeddedReference $reference,
                int|string $sourceValue,
            ): int {
                self::assertSame(17, $sourceValue);
                return 117;
            },
        );

        self::assertSame(
            'employee:117',
            json_decode(
                (string) $restored['payload'],
                true,
                flags: JSON_THROW_ON_ERROR,
            )['people'][0]['person_reference'],
        );
        self::assertSame(
            'payload:people.*.person_reference->payroll_employees:id@employee:',
            $references->references[0]->signature(),
        );
    }

    public function testRemapsPrefixedIdentityAndPreservesValidatedSuffix(): void
    {
        $metadata = [
            ...$this->journalEntryReference(),
            'path' => ['included_components', '*'],
            'target' => 'table:payroll_inputs',
            'value_prefix' => 'input.',
            'value_suffix_separator' => '.',
        ];
        $references = CompanyBackupEmbeddedReferenceSet::fromArray(
            [$metadata],
            'table:payroll_run_revisions',
            ['payload'],
        );
        $references->assertRegistryTargets(new TenantDataRegistry(1, [
            $this->definition('payroll_inputs', TenantDataPolicy::TenantOwned),
        ]));

        $restored = $references->remap(
            ['payload' => ['included_components' => [
                'input.17.base_wage',
                'input.19.bonus_2026',
            ]]],
            static fn (
                CompanyBackupEmbeddedReference $reference,
                int|string $sourceValue,
            ): int => (int) $sourceValue + 100,
        );

        self::assertSame(
            ['input.117.base_wage', 'input.119.bonus_2026'],
            $restored['payload']['included_components'],
        );
        self::assertSame(
            'payload:included_components.*->payroll_inputs:id@input.~.',
            $references->references[0]->signature(),
        );
    }

    public function testRemapsHyphenatedPayrollTraceIdentity(): void
    {
        $metadata = [
            ...$this->journalEntryReference(),
            'path' => ['trace', '*', 'id'],
            'target' => 'table:payroll_employees',
            'value_prefix' => 'revision-person-',
            'value_suffix_separator' => '-',
        ];
        $references = CompanyBackupEmbeddedReferenceSet::fromArray(
            [$metadata],
            'table:payroll_run_revisions',
            ['payload'],
        );

        $restored = $references->remap(
            ['payload' => ['trace' => [[
                'id' => 'revision-person-17-garnishable',
            ]]]],
            static fn (
                CompanyBackupEmbeddedReference $reference,
                int|string $sourceValue,
            ): int => (int) $sourceValue + 100,
        );

        self::assertSame(
            'revision-person-117-garnishable',
            $restored['payload']['trace'][0]['id'],
        );
    }

    public function testRemapsReferencesInsideCanonicalNestedJsonDocument(): void
    {
        $document = [
            'document_nullable' => true,
            'document_path' => [
                'people',
                '*',
                'work_summary',
                'source_snapshot_json',
            ],
        ];
        $references = CompanyBackupEmbeddedReferenceSet::fromArray(
            [
                [
                    ...$this->journalEntryReference(),
                    ...$document,
                    'path' => ['employment', 'id'],
                    'target' => 'table:payroll_employments',
                ],
                [
                    ...$this->journalEntryReference(),
                    ...$document,
                    'path' => ['supplier_id'],
                    'target' => 'table:supplier',
                ],
            ],
            'table:payroll_run_revisions',
            ['payload'],
        );
        $references->assertRegistryTargets(new TenantDataRegistry(1, [
            $this->definition('payroll_employments', TenantDataPolicy::TenantOwned),
            $this->definition('supplier', TenantDataPolicy::TenantRoot),
        ]));
        $nested = \MyInvoice\Service\Backup\CanonicalJson::encode([
            'employment' => ['id' => 17],
            'supplier_id' => 7,
        ]);
        $payload = \MyInvoice\Service\Backup\CanonicalJson::encode([
            'people' => [
                ['work_summary' => ['source_snapshot_json' => $nested]],
                ['work_summary' => ['source_snapshot_json' => null]],
            ],
        ]);

        $restored = $references->remap(
            ['payload' => $payload],
            static fn (
                CompanyBackupEmbeddedReference $reference,
                int|string $sourceValue,
            ): int => (int) $sourceValue + match ($reference->target) {
                'table:payroll_employments' => 100,
                'table:supplier' => 200,
                default => throw new \LogicException('Neočekávaný cíl reference.'),
            },
        );

        $outer = json_decode(
            (string) $restored['payload'],
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $inner = json_decode(
            $outer['people'][0]['work_summary']['source_snapshot_json'],
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        self::assertSame(['employment' => ['id' => 117], 'supplier_id' => 207], $inner);
        self::assertNull($outer['people'][1]['work_summary']['source_snapshot_json']);
        self::assertSame(
            'payload:people.*.work_summary.source_snapshot_json'
                . '::employment.id->payroll_employments:id',
            $references->references[0]->signature(),
        );
    }

    public function testRejectsMalformedOrNonCanonicalNestedJsonDocument(): void
    {
        $metadata = [
            ...$this->journalEntryReference(),
            'document_nullable' => false,
            'document_path' => ['source_snapshot_json'],
            'path' => ['employment', 'id'],
            'target' => 'table:payroll_employments',
        ];
        $references = CompanyBackupEmbeddedReferenceSet::fromArray(
            [$metadata],
            'table:payroll_run_revisions',
            ['payload'],
        );

        foreach (
            [
                null,
                '{',
                '17',
                '{"supplier_id":7,"employment":{"id":17}}',
            ] as $document
        ) {
            try {
                $references->remap(
                    ['payload' => ['source_snapshot_json' => $document]],
                    static function (): never {
                        self::fail('Neplatný vnořený dokument nesmí dojít k mapperu.');
                    },
                );
                self::fail('Poškozený vnořený dokument musí obnovu zastavit.');
            } catch (CompanyBackupDataSourceException $e) {
                self::assertSame('data_embedded_reference_value_invalid', $e->errorCode);
                self::assertSame('payload', $e->column);
            }
        }
    }

    public function testRejectsConflictingNestedDocumentNullability(): void
    {
        $first = [
            ...$this->journalEntryReference(),
            'document_nullable' => true,
            'document_path' => ['source_snapshot_json'],
            'path' => ['employment_id'],
            'target' => 'table:payroll_employments',
        ];
        $second = [
            ...$first,
            'document_nullable' => false,
            'path' => ['supplier_id'],
            'target' => 'table:supplier',
        ];

        $this->expectExceptionObject(new CompanyBackupDataSourceException(
            'data_embedded_reference_metadata_invalid',
            'table:payroll_run_revisions',
            'payload',
        ));
        CompanyBackupEmbeddedReferenceSet::fromArray(
            [$first, $second],
            'table:payroll_run_revisions',
            ['payload'],
        );
    }

    public function testRejectsMalformedPrefixedIdentityBeforeMapping(): void
    {
        $metadata = [
            ...$this->journalEntryReference(),
            'path' => ['person_reference'],
            'target' => 'table:payroll_employees',
            'value_prefix' => 'employee:',
        ];
        $references = CompanyBackupEmbeddedReferenceSet::fromArray(
            [$metadata],
            'table:payroll_run_revisions',
            ['payload'],
        );

        foreach (
            ['employee:0', 'employee:01', 'employment:17', 'employee:999999999999999999999']
            as $identity
        ) {
            try {
                $references->remap(
                    ['payload' => ['person_reference' => $identity]],
                    static function (): never {
                        self::fail('Neplatná řetězcová identita nesmí dojít k mapperu.');
                    },
                );
                self::fail('Neplatná řetězcová identita nesmí projít obnovou.');
            } catch (CompanyBackupDataSourceException $e) {
                self::assertSame('data_embedded_reference_value_invalid', $e->errorCode);
            }
        }
    }

    public function testRejectsMalformedPreservedSuffixBeforeMapping(): void
    {
        $metadata = [
            ...$this->journalEntryReference(),
            'path' => ['component_reference'],
            'target' => 'table:payroll_inputs',
            'value_prefix' => 'input.',
            'value_suffix_separator' => '.',
        ];
        $references = CompanyBackupEmbeddedReferenceSet::fromArray(
            [$metadata],
            'table:payroll_run_revisions',
            ['payload'],
        );

        foreach (
            [
                'input.0.base_wage',
                'input.01.base_wage',
                'input.17',
                'input.17.BaseWage',
                'input.17.base/wage',
            ] as $identity
        ) {
            try {
                $references->remap(
                    ['payload' => ['component_reference' => $identity]],
                    static function (): never {
                        self::fail('Neplatný suffix nesmí dojít k mapperu.');
                    },
                );
                self::fail('Neplatný suffix řetězcové identity nesmí projít.');
            } catch (CompanyBackupDataSourceException $e) {
                self::assertSame('data_embedded_reference_value_invalid', $e->errorCode);
            }
        }
    }

    public function testRejectsInvalidJsonAndInvalidReferencedValue(): void
    {
        $references = CompanyBackupEmbeddedReferenceSet::fromArray(
            [$this->journalEntryReference()],
            'table:accounting_closing_steps',
            ['payload'],
        );

        foreach (
            [
                ['payload' => '{'],
                ['payload' => '{"entries":[{"entry_id":{"nested":17}}]}'],
                ['payload' => '{"entries":[{"entry_id":null}]}'],
            ] as $row
        ) {
            try {
                $references->remap(
                    $row,
                    static fn (
                        CompanyBackupEmbeddedReference $reference,
                        int|string $value,
                    ): int|string => $value,
                );
                self::fail('Poškozená nebo nevázaná embedded reference musí obnovu zastavit.');
            } catch (CompanyBackupDataSourceException $e) {
                self::assertSame('data_embedded_reference_value_invalid', $e->errorCode);
                self::assertSame('payload', $e->column);
            }
        }

        try {
            $references->remap(
                ['payload' => ['entries' => [['entry_id' => 17]]]],
                static fn (
                    CompanyBackupEmbeddedReference $reference,
                    int|string $value,
                ): null => null,
            );
            self::fail('Povinná reference se nesmí ztratit chybějícím remapem.');
        } catch (CompanyBackupDataSourceException $e) {
            self::assertSame('data_embedded_reference_value_invalid', $e->errorCode);
            self::assertSame('payload', $e->column);
        }
    }

    public function testRejectsTargetOutsideCompanyProfile(): void
    {
        $references = CompanyBackupEmbeddedReferenceSet::fromArray(
            [$this->journalEntryReference()],
            'table:accounting_closing_steps',
            ['payload'],
        );
        $registry = new TenantDataRegistry(1, [
            $this->definition('journal_entries', TenantDataPolicy::InstanceOwned),
        ]);

        try {
            $references->assertRegistryTargets($registry);
            self::fail('Embedded reference nesmí mířit mimo obnovitelný profil.');
        } catch (CompanyBackupDataSourceException $e) {
            self::assertSame('data_embedded_reference_target_invalid', $e->errorCode);
            self::assertSame('payload', $e->column);
        }
    }

    public function testRejectsTwoMappingsClaimingTheSamePayloadPath(): void
    {
        $duplicate = $this->journalEntryReference();
        $duplicate['target'] = 'table:assets';

        try {
            CompanyBackupEmbeddedReferenceSet::fromArray(
                [$this->journalEntryReference(), $duplicate],
                'table:accounting_closing_steps',
                ['payload'],
            );
            self::fail('Jednu hodnotu nesmějí nezávisle remapovat dva cíle.');
        } catch (CompanyBackupDataSourceException $e) {
            self::assertSame('data_embedded_reference_duplicate', $e->errorCode);
            self::assertSame('payload', $e->column);
        }
    }

    public function testRemapsPolymorphicIdsByCorrelatedDiscriminator(): void
    {
        $path = ['checks', '*', 'value', 'findings', '*', 'doc_id'];
        $conditionPath = ['checks', '*', 'value', 'findings', '*', 'doc_type'];
        $references = CompanyBackupEmbeddedReferenceSet::fromArray(
            [
                [
                    ...$this->journalEntryReference(),
                    'path' => $path,
                    'target' => 'table:invoices',
                    'nullable' => true,
                    'condition' => [
                        'path' => $conditionPath,
                        'equals' => 'invoice',
                    ],
                ],
                [
                    ...$this->journalEntryReference(),
                    'path' => $path,
                    'target' => 'table:purchase_invoices',
                    'nullable' => true,
                    'condition' => [
                        'path' => $conditionPath,
                        'equals' => 'purchase_invoice',
                    ],
                ],
            ],
            'table:accounting_closing_steps',
            ['payload'],
        );
        $references->assertRegistryTargets(new TenantDataRegistry(1, [
            $this->definition('invoices', TenantDataPolicy::TenantOwned),
            $this->definition('purchase_invoices', TenantDataPolicy::TenantOwned),
        ]));
        $source = ['payload' => [
            'checks' => [
                ['value' => ['findings' => [
                    ['doc_type' => 'invoice', 'doc_id' => 7],
                    ['doc_type' => 'purchase_invoice', 'doc_id' => 8],
                    ['doc_id' => null],
                ]]],
            ],
        ]];

        $restored = $references->remap(
            $source,
            static fn (
                CompanyBackupEmbeddedReference $reference,
                int|string $value,
            ): int => (int) $value + ($reference->target === 'table:invoices' ? 100 : 200),
        );

        self::assertSame(
            [107, 208, null],
            array_column(
                $restored['payload']['checks'][0]['value']['findings'],
                'doc_id',
            ),
        );
        self::assertSame(
            'payload:checks.*.value.findings.*.doc_id->invoices:id'
                . '?checks.*.value.findings.*.doc_type=invoice',
            $references->references[0]->signature(),
        );
    }

    public function testRejectsUnclassifiedPolymorphicDiscriminator(): void
    {
        $reference = [
            ...$this->journalEntryReference(),
            'path' => ['findings', '*', 'doc_id'],
            'target' => 'table:invoices',
            'condition' => [
                'path' => ['findings', '*', 'doc_type'],
                'equals' => 'invoice',
            ],
        ];
        $references = CompanyBackupEmbeddedReferenceSet::fromArray(
            [$reference],
            'table:accounting_closing_steps',
            ['payload'],
        );

        try {
            $references->remap(
                ['payload' => ['findings' => [[
                    'doc_type' => 'unknown',
                    'doc_id' => 7,
                ]]]],
                static fn (
                    CompanyBackupEmbeddedReference $embedded,
                    int|string $value,
                ): int|string => $value,
            );
            self::fail('Neznámý polymorfní typ nesmí zachovat zdrojové ID.');
        } catch (CompanyBackupDataSourceException $e) {
            self::assertSame('data_embedded_reference_value_invalid', $e->errorCode);
            self::assertSame('payload', $e->column);
        }
    }

    public function testRejectsAmbiguousPolymorphicDiscriminator(): void
    {
        $path = ['findings', '*', 'doc_id'];
        $references = CompanyBackupEmbeddedReferenceSet::fromArray(
            [
                [
                    ...$this->journalEntryReference(),
                    'path' => $path,
                    'target' => 'table:invoices',
                    'condition' => [
                        'path' => ['findings', '*', 'doc_type'],
                        'equals' => 'invoice',
                    ],
                ],
                [
                    ...$this->journalEntryReference(),
                    'path' => $path,
                    'target' => 'table:purchase_invoices',
                    'condition' => [
                        'path' => ['findings', '*', 'source_type'],
                        'equals' => 'imported',
                    ],
                ],
            ],
            'table:accounting_closing_steps',
            ['payload'],
        );

        $this->expectExceptionObject(new CompanyBackupDataSourceException(
            'data_embedded_reference_value_invalid',
            'table:accounting_closing_steps',
            'payload',
        ));
        $references->remap(
            ['payload' => ['findings' => [[
                'doc_type' => 'invoice',
                'source_type' => 'imported',
                'doc_id' => 7,
            ]]]],
            static fn (
                CompanyBackupEmbeddedReference $embedded,
                int|string $value,
            ): int|string => $value,
        );
    }

    /** @return iterable<string,array{array<mixed>,list<string>}> */
    public static function invalidMetadata(): iterable
    {
        $valid = [
            'column' => 'payload',
            'condition' => null,
            'path' => ['entries', '*', 'entry_id'],
            'target' => 'table:journal_entries',
            'target_columns' => ['id'],
            'mapping' => CompanyBackupReferenceMapping::TenantId->value,
            'nullable' => false,
            'fallbacks' => [],
        ];

        yield 'unexported column' => [[...$valid, 'column' => 'missing'], ['payload']];
        yield 'empty path' => [[...$valid, 'path' => []], ['payload']];
        yield 'invalid path segment' => [[...$valid, 'path' => ['entries', '**']], ['payload']];
        yield 'multiple target columns' => [[...$valid, 'target_columns' => ['tenant_id', 'id']], ['payload']];
        yield 'fallback outside actor mapping' => [[...$valid, 'fallbacks' => ['null']], ['payload']];
        yield 'uncorrelated condition wildcard' => [[
            ...$valid,
            'condition' => [
                'path' => ['groups', '*', 'entry_type'],
                'equals' => 'entry',
            ],
        ], ['payload']];
        yield 'unknown field' => [[...$valid, 'comment' => 'unbound'], ['payload']];
        yield 'document path without nullability' => [[
            ...$valid,
            'document_path' => ['source_json'],
        ], ['payload']];
        yield 'document nullability without path' => [[
            ...$valid,
            'document_nullable' => true,
        ], ['payload']];
        yield 'empty document path' => [[
            ...$valid,
            'document_nullable' => true,
            'document_path' => [],
        ], ['payload']];
        yield 'invalid document path segment' => [[
            ...$valid,
            'document_nullable' => false,
            'document_path' => ['source', '**'],
        ], ['payload']];
        yield 'invalid value prefix' => [[...$valid, 'value_prefix' => 'Employee:'], ['payload']];
        yield 'suffix without prefix' => [[
            ...$valid,
            'value_suffix_separator' => '.',
        ], ['payload']];
        yield 'invalid suffix separator' => [[
            ...$valid,
            'value_prefix' => 'input.',
            'value_suffix_separator' => '/',
        ], ['payload']];
        yield 'prefix outside numeric mapping' => [[
            ...$valid,
            'mapping' => CompanyBackupReferenceMapping::TenantNaturalKey->value,
            'value_prefix' => 'employee:',
        ], ['payload']];
    }

    /**
     * @param array<mixed> $metadata
     * @param list<string> $dataColumns
     */
    #[DataProvider('invalidMetadata')]
    public function testRejectsInvalidOrUnboundMetadata(
        array $metadata,
        array $dataColumns,
    ): void {
        $this->expectException(CompanyBackupDataSourceException::class);
        CompanyBackupEmbeddedReferenceSet::fromArray(
            [$metadata],
            'table:accounting_closing_steps',
            $dataColumns,
        );
    }

    /** @return array<string,mixed> */
    private function journalEntryReference(): array
    {
        return [
            'column' => 'payload',
            'condition' => null,
            'path' => ['entries', '*', 'entry_id'],
            'target' => 'table:journal_entries',
            'target_columns' => ['id'],
            'mapping' => CompanyBackupReferenceMapping::TenantId->value,
            'nullable' => false,
            'fallbacks' => [],
        ];
    }

    private function targetRegistry(): TenantDataRegistry
    {
        return new TenantDataRegistry(1, [
            $this->definition('journal_entries', TenantDataPolicy::TenantOwned),
        ]);
    }

    private function definition(
        string $table,
        TenantDataPolicy $policy,
    ): TenantDataDefinition {
        return new TenantDataDefinition(
            'table:' . $table,
            TenantDataObjectKind::Table,
            $policy,
            [TenantDataRegistry::COMPANY_BACKUP_PROFILE],
            ['primary_key' => ['id']],
        );
    }
}
