<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup\Company;

use MyInvoice\Service\Backup\Company\CompanyBackupDataSourceException;
use MyInvoice\Service\Backup\Company\CompanyBackupPolymorphicReferenceCase;
use MyInvoice\Service\Backup\Company\CompanyBackupPolymorphicReferenceSet;
use MyInvoice\Service\Backup\Registry\TenantDataDefinition;
use MyInvoice\Service\Backup\Registry\TenantDataObjectKind;
use MyInvoice\Service\Backup\Registry\TenantDataPolicy;
use MyInvoice\Service\Backup\Registry\TenantDataRegistry;
use PHPUnit\Framework\TestCase;

final class CompanyBackupPolymorphicReferenceSetTest extends TestCase
{
    public function testRemapsIdsEncodedPeriodsAndPreservesSyntheticKeys(): void
    {
        $references = CompanyBackupPolymorphicReferenceSet::fromArray(
            $this->metadata(),
            'table:journal_entries',
            ['id', 'source_type', 'source_id'],
        );
        $references->assertRegistryTargets(new TenantDataRegistry(1, [
            $this->definition('accounting_periods'),
            $this->definition('invoices'),
        ]));
        $mapper = static fn (
            CompanyBackupPolymorphicReferenceCase $case,
            int $value,
        ): int => $value + 100;

        self::assertSame(
            117,
            $references->remap(
                ['source_type' => 'invoice', 'source_id' => 17],
                $mapper,
            )['source_id'],
        );
        self::assertSame(
            1071,
            $references->remap(
                ['source_type' => 'fx_revaluation', 'source_id' => 71],
                $mapper,
            )['source_id'],
        );
        self::assertSame(
            107,
            $references->remap(
                ['source_type' => 'closing', 'source_id' => 7],
                $mapper,
            )['source_id'],
        );
        self::assertSame(
            1_000_000_001_074,
            $references->remap(
                ['source_type' => 'closing', 'source_id' => 1_000_000_000_074],
                $mapper,
            )['source_id'],
        );
        self::assertSame(
            2_000_000_000_107,
            $references->remap(
                ['source_type' => 'small_asset_accrual', 'source_id' => 2_000_000_000_007],
                $mapper,
            )['source_id'],
        );

        $mapperCalls = 0;
        $preserved = $references->remap(
            ['source_type' => 'manual', 'source_id' => 202601],
            static function () use (&$mapperCalls): never {
                $mapperCalls++;
                throw new \LogicException('Preserve varianta nesmí volat mapper.');
            },
        );
        self::assertSame(202601, $preserved['source_id']);
        self::assertSame(0, $mapperCalls);
        self::assertNull($references->remap(
            ['source_type' => 'invoice', 'source_id' => null],
            $mapper,
        )['source_id']);
    }

    public function testRejectsUnknownDiscriminatorAndUnknownEncodedSlot(): void
    {
        $references = CompanyBackupPolymorphicReferenceSet::fromArray(
            $this->metadata(),
            'table:journal_entries',
            ['id', 'source_type', 'source_id'],
        );

        foreach (
            [
                ['source_type' => 'future_type', 'source_id' => 1],
                ['source_type' => 'fx_revaluation', 'source_id' => 74],
                ['source_type' => 'closing', 'source_id' => 1_000_000_000_071],
            ] as $row
        ) {
            try {
                $references->remap($row, static fn ($case, int $value): int => $value);
                self::fail('Neznámý typ ani slot nesmí projít polymorfním remapem.');
            } catch (CompanyBackupDataSourceException $e) {
                self::assertSame('data_polymorphic_reference_value_invalid', $e->errorCode);
                self::assertSame('source_id', $e->column);
            }
        }
    }

    public function testRejectsMissingColumnsAndInvalidMappedId(): void
    {
        $references = CompanyBackupPolymorphicReferenceSet::fromArray(
            $this->metadata(),
            'table:journal_entries',
            ['id', 'source_type', 'source_id'],
        );

        foreach ([[], ['source_type' => 'invoice'], ['source_id' => 1]] as $row) {
            try {
                $references->remap($row, static fn ($case, int $value): int => $value);
                self::fail('Chybějící hodnota polymorfní reference musí zastavit obnovu.');
            } catch (CompanyBackupDataSourceException $e) {
                self::assertSame('data_polymorphic_reference_value_invalid', $e->errorCode);
            }
        }

        try {
            $references->remap(
                ['source_type' => 'invoice', 'source_id' => 1],
                static fn (): null => null,
            );
            self::fail('Povinně mapované ID nesmí zmizet bez explicitního fallbacku.');
        } catch (CompanyBackupDataSourceException $e) {
            self::assertSame('data_polymorphic_reference_value_invalid', $e->errorCode);
        }
    }

    public function testRejectsNonCanonicalCasesAndInvalidTransformMetadata(): void
    {
        $metadata = $this->metadata();
        $metadata[0]['cases'] = array_reverse($metadata[0]['cases']);
        try {
            CompanyBackupPolymorphicReferenceSet::fromArray(
                $metadata,
                'table:journal_entries',
                ['id', 'source_type', 'source_id'],
            );
            self::fail('Varianty musí být v kanonickém pořadí pro stabilní fingerprint.');
        } catch (CompanyBackupDataSourceException $e) {
            self::assertSame('data_polymorphic_reference_metadata_invalid', $e->errorCode);
        }

        $metadata = $this->metadata();
        $metadata[0]['cases'][1]['slots'] = [1, 10];
        try {
            CompanyBackupPolymorphicReferenceSet::fromArray(
                $metadata,
                'table:journal_entries',
                ['id', 'source_type', 'source_id'],
            );
            self::fail('Slot mimo rozsah násobitele musí být odmítnut.');
        } catch (CompanyBackupDataSourceException $e) {
            self::assertSame('data_polymorphic_reference_metadata_invalid', $e->errorCode);
        }
    }

    /** @return list<array<string,mixed>> */
    private function metadata(): array
    {
        return [[
            'column' => 'source_id',
            'discriminator_column' => 'source_type',
            'nullable' => true,
            'cases' => [
                $this->case(
                    'closing',
                    'table:accounting_periods',
                    'identity_or_decimal_slot',
                    1_000_000_000_000,
                    10,
                    [4, 5, 6, 7],
                ),
                $this->case(
                    'fx_revaluation',
                    'table:accounting_periods',
                    'decimal_slot',
                    0,
                    10,
                    [1, 2, 3],
                ),
                $this->case('invoice', 'table:invoices'),
                $this->preserveCase('manual'),
                $this->case(
                    'small_asset_accrual',
                    'table:accounting_periods',
                    'identity_or_offset',
                    2_000_000_000_000,
                ),
            ],
        ]];
    }

    /**
     * @param list<int> $slots
     * @return array{
     *   base:int,
     *   equals:string,
     *   mapping:string,
     *   multiplier:int,
     *   slots:list<int>,
     *   target:string,
     *   target_columns:list<string>,
     *   transform:string
     * }
     */
    private function case(
        string $equals,
        string $target,
        string $transform = 'identity',
        int $base = 0,
        int $multiplier = 1,
        array $slots = [],
    ): array {
        return [
            'base' => $base,
            'equals' => $equals,
            'mapping' => 'tenant_id',
            'multiplier' => $multiplier,
            'slots' => $slots,
            'target' => $target,
            'target_columns' => ['id'],
            'transform' => $transform,
        ];
    }

    /**
     * @return array{
     *   base:int,
     *   equals:string,
     *   mapping:string,
     *   multiplier:int,
     *   slots:list<int>,
     *   target:null,
     *   target_columns:list<string>,
     *   transform:string
     * }
     */
    private function preserveCase(string $equals): array
    {
        return [
            'base' => 0,
            'equals' => $equals,
            'mapping' => 'preserve',
            'multiplier' => 1,
            'slots' => [],
            'target' => null,
            'target_columns' => [],
            'transform' => 'identity',
        ];
    }

    private function definition(string $table): TenantDataDefinition
    {
        return new TenantDataDefinition(
            'table:' . $table,
            TenantDataObjectKind::Table,
            TenantDataPolicy::TenantOwned,
            [TenantDataRegistry::COMPANY_BACKUP_PROFILE],
            ['primary_key' => ['id']],
        );
    }
}
