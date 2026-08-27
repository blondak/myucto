<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup\Company;

use MyInvoice\Service\Backup\Company\CompanyBackupDataSourceException;
use MyInvoice\Service\Backup\Company\CompanyBackupReferenceConstraint;
use MyInvoice\Service\Backup\Company\CompanyBackupReferenceMapping;
use MyInvoice\Service\Backup\Company\CompanyBackupReferenceSet;
use MyInvoice\Service\Backup\Company\CompanyBackupRestoreOverrideSet;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CompanyBackupRestoreOverrideSetTest extends TestCase
{
    public function testAppliesDeclaredSafeStateWithoutChangingSourceRow(): void
    {
        $overrides = CompanyBackupRestoreOverrideSet::fromArray(
            [
                'automation_digest_enabled' => [
                    'value' => 0,
                    'reason' => 'disable_automation_after_restore',
                ],
                'automation_level' => [
                    'value' => 'off',
                    'reason' => 'disable_automation_after_restore',
                ],
            ],
            'table:accounting_supplier_settings',
            ['supplier_id', 'automation_level', 'automation_digest_enabled'],
            ['supplier_id'],
            CompanyBackupReferenceSet::fromArray(
                [$this->supplierReference()],
                'table:accounting_supplier_settings',
            ),
        );
        $source = [
            'supplier_id' => 7,
            'automation_level' => 'full',
            'automation_digest_enabled' => 1,
        ];

        $restored = $overrides->apply($source);

        self::assertSame('off', $restored['automation_level']);
        self::assertSame(0, $restored['automation_digest_enabled']);
        self::assertSame('full', $source['automation_level']);
        self::assertSame(
            'disable_automation_after_restore',
            $overrides->overrides['automation_level']->reason,
        );
    }

    public function testRejectsOverrideOfReferenceColumn(): void
    {
        try {
            CompanyBackupRestoreOverrideSet::fromArray(
                ['supplier_id' => ['value' => 1, 'reason' => 'unsafe_reference_rewrite']],
                'table:synthetic_records',
                ['supplier_id', 'name'],
                ['supplier_id'],
                CompanyBackupReferenceSet::fromArray(
                    [$this->supplierReference()],
                    'table:synthetic_records',
                ),
            );
            self::fail('Restore override nesmí přepsat klíč ani referenční sloupec.');
        } catch (CompanyBackupDataSourceException $e) {
            self::assertSame('data_restore_override_column_invalid', $e->errorCode);
            self::assertSame('supplier_id', $e->column);
        }
    }

    /** @return iterable<string,array{array<mixed>}> */
    public static function invalidMetadata(): iterable
    {
        yield 'unknown column' => [[
            'missing' => ['value' => 0, 'reason' => 'disable_after_restore'],
        ]];
        yield 'unstable reason' => [[
            'automation_level' => ['value' => 'off', 'reason' => 'vypnout po obnově'],
        ]];
        yield 'structured value' => [[
            'automation_level' => [
                'value' => ['off'],
                'reason' => 'disable_after_restore',
            ],
        ]];
        yield 'unknown metadata field' => [[
            'automation_level' => [
                'value' => 'off',
                'reason' => 'disable_after_restore',
                'note' => 'unbound',
            ],
        ]];
    }

    /** @param array<mixed> $metadata */
    #[DataProvider('invalidMetadata')]
    public function testRejectsInvalidOrUnboundMetadata(array $metadata): void
    {
        $this->expectException(CompanyBackupDataSourceException::class);
        CompanyBackupRestoreOverrideSet::fromArray(
            $metadata,
            'table:synthetic_records',
            ['id', 'automation_level'],
            ['id'],
            CompanyBackupReferenceSet::fromArray([], 'table:synthetic_records'),
        );
    }

    /** @return array<string,mixed> */
    private function supplierReference(): array
    {
        return [
            'columns' => ['supplier_id'],
            'target' => 'table:supplier',
            'target_columns' => ['id'],
            'mapping' => CompanyBackupReferenceMapping::TenantId->value,
            'constraint' => CompanyBackupReferenceConstraint::Required->value,
            'nullable_columns' => [],
            'fallbacks' => [],
        ];
    }
}
