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
