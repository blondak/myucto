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

    private function targetRegistry(): TenantDataRegistry
    {
        return new TenantDataRegistry(1, [
            $this->definition(
                'payroll_business_trips',
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
