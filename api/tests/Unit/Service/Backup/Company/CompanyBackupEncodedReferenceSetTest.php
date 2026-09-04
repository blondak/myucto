<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup\Company;

use MyInvoice\Service\Backup\Company\CompanyBackupDataSourceException;
use MyInvoice\Service\Backup\Company\CompanyBackupEncodedReference;
use MyInvoice\Service\Backup\Company\CompanyBackupEncodedReferenceSet;
use MyInvoice\Service\Backup\Company\CompanyBackupReferenceMapping;
use MyInvoice\Service\Backup\Registry\TenantDataDefinition;
use MyInvoice\Service\Backup\Registry\TenantDataObjectKind;
use MyInvoice\Service\Backup\Registry\TenantDataPolicy;
use MyInvoice\Service\Backup\Registry\TenantDataRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CompanyBackupEncodedReferenceSetTest extends TestCase
{
    public function testRemapsConditionalPrefixedIdAndPreservesSuffix(): void
    {
        $references = CompanyBackupEncodedReferenceSet::fromArray(
            [$this->travelReference()],
            'table:payroll_inputs',
            ['source_kind', 'external_id'],
        );
        $references->assertRegistryTargets($this->targetRegistry());

        self::assertSame(['external_id'], $references->classifiedColumns());
        self::assertSame(
            'external_id->payroll_business_trips:id'
                . '?source_kind=travel@travel:~:',
            $references->references[0]->signature(),
        );
        self::assertSame(
            ['source_kind' => 'manual', 'external_id' => 'user-reference'],
            $references->remap(
                ['source_kind' => 'manual', 'external_id' => 'user-reference'],
                static fn (): never => throw new \LogicException(
                    'Nematchující podmínka nesmí volat mapper.',
                ),
            ),
        );

        $restored = $references->remap(
            ['source_kind' => 'travel', 'external_id' => 'travel:17:exempt'],
            static function (
                CompanyBackupEncodedReference $reference,
                int $value,
            ): int {
                self::assertSame('table:payroll_business_trips', $reference->target);
                self::assertSame(17, $value);
                return 117;
            },
        );
        self::assertSame('travel:117:exempt', $restored['external_id']);
    }

    public function testSynchronizesCorrelatedIdAndPreservesLegacyValue(): void
    {
        $references = CompanyBackupEncodedReferenceSet::fromArray(
            [$this->dependantReference()],
            'table:payroll_person_tax_child_claims',
            ['dependant_id', 'child_reference'],
        );
        $references->assertRegistryTargets($this->targetRegistry());

        self::assertSame(
            'child_reference=dependant_id->payroll_dependants:id@dependant-',
            $references->references[0]->signature(),
        );
        self::assertSame(
            [
                'dependant_id' => null,
                'child_reference' => 'legacy:synthetic-child',
            ],
            $references->remap(
                [
                    'dependant_id' => null,
                    'child_reference' => 'legacy:synthetic-child',
                ],
                static fn (): never => throw new \LogicException(
                    'Legacy reference bez dependant_id nesmí volat mapper.',
                ),
            ),
        );

        $restored = $references->remap(
            ['dependant_id' => 17, 'child_reference' => 'dependant-17'],
            static function (
                CompanyBackupEncodedReference $reference,
                int $value,
            ): int {
                self::assertSame('table:payroll_dependants', $reference->target);
                self::assertSame(17, $value);
                return 117;
            },
        );
        self::assertSame(117, $restored['dependant_id']);
        self::assertSame('dependant-117', $restored['child_reference']);
    }

    public function testPreservesExplicitZeroSentinelWithoutCallingMapper(): void
    {
        $references = CompanyBackupEncodedReferenceSet::fromArray(
            [[
                'column' => 'register_reference',
                'condition' => null,
                'mapping' => CompanyBackupReferenceMapping::TenantIdOrZero->value,
                'nullable' => false,
                'target' => 'table:cash_registers',
                'target_columns' => ['id'],
                'value_prefix' => 'register:',
                'value_suffix_separator' => null,
            ]],
            'table:synthetic_records',
            ['register_reference'],
        );

        self::assertSame(
            ['register_reference' => 'register:0'],
            $references->remap(
                ['register_reference' => 'register:0'],
                static fn (): never => throw new \LogicException(
                    'Nulový sentinel nesmí vstoupit do ID mapy.',
                ),
            ),
        );
        self::assertSame(
            'register:107',
            $references->remap(
                ['register_reference' => 'register:7'],
                static fn ($reference, int $value): int => $value + 100,
            )['register_reference'],
        );
    }

    /** @return iterable<string,array{array<string,mixed>}> */
    public static function invalidCorrelatedRows(): iterable
    {
        yield 'different identifier' => [[
            'dependant_id' => 18,
            'child_reference' => 'dependant-17',
        ]];
        yield 'textual scalar identifier' => [[
            'dependant_id' => '17',
            'child_reference' => 'dependant-17',
        ]];
        yield 'zero scalar identifier' => [[
            'dependant_id' => 0,
            'child_reference' => 'dependant-0',
        ]];
        yield 'missing scalar identifier' => [[
            'child_reference' => 'dependant-17',
        ]];
    }

    /** @param array<string,mixed> $row */
    #[DataProvider('invalidCorrelatedRows')]
    public function testRejectsInvalidCorrelatedIdentity(array $row): void
    {
        $references = CompanyBackupEncodedReferenceSet::fromArray(
            [$this->dependantReference()],
            'table:payroll_person_tax_child_claims',
            ['dependant_id', 'child_reference'],
        );

        try {
            $references->assertSourceRow($row);
            self::fail('Nesouhlasící skalární a řetězcové ID nesmí projít.');
        } catch (CompanyBackupDataSourceException $e) {
            self::assertSame('data_encoded_reference_value_invalid', $e->errorCode);
            self::assertSame('child_reference', $e->column);
        }
    }

    /** @return iterable<string,array{mixed}> */
    public static function invalidTravelValues(): iterable
    {
        yield 'null' => [null];
        yield 'zero' => ['travel:0:exempt'];
        yield 'leading zero' => ['travel:017:exempt'];
        yield 'missing suffix' => ['travel:17'];
        yield 'uppercase suffix' => ['travel:17:Exempt'];
        yield 'wrong prefix' => ['trip:17:exempt'];
    }

    #[DataProvider('invalidTravelValues')]
    public function testRejectsInvalidValueWhenConditionMatches(mixed $value): void
    {
        $references = CompanyBackupEncodedReferenceSet::fromArray(
            [$this->travelReference()],
            'table:payroll_inputs',
            ['source_kind', 'external_id'],
        );

        try {
            $references->assertSourceRow([
                'source_kind' => 'travel',
                'external_id' => $value,
            ]);
            self::fail('Neplatná řetězcová identita nesmí projít exportem.');
        } catch (CompanyBackupDataSourceException $e) {
            self::assertSame('data_encoded_reference_value_invalid', $e->errorCode);
            self::assertSame('external_id', $e->column);
        }
    }

    public function testRejectsInvalidMetadataAndTarget(): void
    {
        try {
            CompanyBackupEncodedReferenceSet::fromArray(
                [[...$this->travelReference(), 'value_suffix_separator' => '/']],
                'table:payroll_inputs',
                ['source_kind', 'external_id'],
            );
            self::fail('Neplatný oddělovač nesmí vstoupit do registru.');
        } catch (CompanyBackupDataSourceException $e) {
            self::assertSame('data_encoded_reference_metadata_invalid', $e->errorCode);
        }

        $references = CompanyBackupEncodedReferenceSet::fromArray(
            [$this->travelReference()],
            'table:payroll_inputs',
            ['source_kind', 'external_id'],
        );
        try {
            $references->assertRegistryTargets(new TenantDataRegistry(1, [
                $this->definition(
                    'payroll_business_trips',
                    TenantDataPolicy::GlobalReference,
                ),
            ]));
            self::fail('Tenantové ID nesmí mířit do globálního číselníku.');
        } catch (CompanyBackupDataSourceException $e) {
            self::assertSame('data_encoded_reference_target_invalid', $e->errorCode);
        }

        foreach ([
            [...$this->dependantReference(), 'correlated_id_column' => null],
            [...$this->dependantReference(), 'correlated_id_column' => 'child_reference'],
            [...$this->dependantReference(), 'nullable' => true],
        ] as $invalid) {
            try {
                CompanyBackupEncodedReferenceSet::fromArray(
                    [$invalid],
                    'table:payroll_person_tax_child_claims',
                    ['dependant_id', 'child_reference'],
                );
                self::fail('Nejednoznačná korelace nesmí vstoupit do registru.');
            } catch (CompanyBackupDataSourceException $e) {
                self::assertSame(
                    'data_encoded_reference_metadata_invalid',
                    $e->errorCode,
                );
            }
        }

        try {
            CompanyBackupEncodedReferenceSet::fromArray(
                [$this->dependantReference()],
                'table:payroll_person_tax_child_claims',
                ['child_reference'],
            );
            self::fail('Korelované ID musí být součástí exportu.');
        } catch (CompanyBackupDataSourceException $e) {
            self::assertSame(
                'data_encoded_reference_correlation_source_not_exported',
                $e->errorCode,
            );
            self::assertSame('dependant_id', $e->column);
        }

        try {
            CompanyBackupEncodedReferenceSet::fromArray(
                [
                    $this->dependantReference(),
                    [
                        ...$this->dependantReference(),
                        'column' => 'alternate_reference',
                    ],
                ],
                'table:payroll_person_tax_child_claims',
                ['dependant_id', 'child_reference', 'alternate_reference'],
            );
            self::fail('Jedno ID nesmějí současně přepisovat dva kontrakty.');
        } catch (CompanyBackupDataSourceException $e) {
            self::assertSame('data_encoded_reference_duplicate', $e->errorCode);
            self::assertSame('dependant_id', $e->column);
        }
    }

    /** @return array<string,mixed> */
    private function travelReference(): array
    {
        return [
            'column' => 'external_id',
            'condition' => [
                'column' => 'source_kind',
                'equals' => 'travel',
            ],
            'mapping' => CompanyBackupReferenceMapping::TenantId->value,
            'nullable' => false,
            'target' => 'table:payroll_business_trips',
            'target_columns' => ['id'],
            'value_prefix' => 'travel:',
            'value_suffix_separator' => ':',
        ];
    }

    /** @return array<string,mixed> */
    private function dependantReference(): array
    {
        return [
            'column' => 'child_reference',
            'condition' => null,
            'correlated_id_column' => 'dependant_id',
            'mapping' => CompanyBackupReferenceMapping::TenantId->value,
            'nullable' => false,
            'target' => 'table:payroll_dependants',
            'target_columns' => ['id'],
            'value_prefix' => 'dependant-',
            'value_suffix_separator' => null,
        ];
    }

    private function targetRegistry(): TenantDataRegistry
    {
        return new TenantDataRegistry(1, [
            $this->definition(
                'payroll_business_trips',
                TenantDataPolicy::TenantOwned,
            ),
            $this->definition(
                'payroll_dependants',
                TenantDataPolicy::TenantOwned,
            ),
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
