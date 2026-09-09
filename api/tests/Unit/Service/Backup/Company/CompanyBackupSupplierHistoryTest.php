<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup\Company;

use MyInvoice\Service\Backup\Company\CompanyBackupReference;
use MyInvoice\Service\Backup\Company\CompanyBackupSourceIdentityProjection;
use MyInvoice\Service\Backup\Company\CompanyBackupTableProjection;
use MyInvoice\Service\Backup\Company\CompanyBackupTenantSqlSelector;
use MyInvoice\Service\Backup\Registry\CompanyBackupSupplierHistoryDefinitions;
use MyInvoice\Service\Backup\Registry\TenantDataRegistry;
use MyInvoice\Service\Backup\Registry\TenantDataRegistryFactory;
use PHPUnit\Framework\TestCase;

final class CompanyBackupSupplierHistoryTest extends TestCase
{
    public function testHistoriesPreserveValuesAndOnlyRemapSupplierAndHistoricalActor(): void
    {
        $registry = TenantDataRegistryFactory::draftV1();
        foreach (CompanyBackupSupplierHistoryDefinitions::definitions() as $expected) {
            $definition = $registry->definition($expected->key);
            self::assertNotNull($definition);
            self::assertSame($expected->toArray(), $definition->toArray());
            self::assertFalse($definition->hasProfile(TenantDataRegistry::ACCOUNTING_ARCHIVE_PROFILE));
            $projection = CompanyBackupTableProjection::fromDefinition($definition);
            $projection->assertRegistryTargets($registry);
            $row = array_fill_keys($projection->dataColumns, null);
            $row['id'] = 11;
            $row['supplier_id'] = 7;
            foreach (['effective_from' => '2030-01-01', 'year' => 2021, 'month' => 2,
                'taxpayer_type' => 'fo', 'advance_kind' => 'tax', 'period_year' => 2021,
                'created_by' => 17] as $column => $value) {
                if (array_key_exists($column, $row)) {
                    $row[$column] = $value;
                }
            }
            $projection->assertCompleteSourceRow($row);
            $selection = (new CompanyBackupTenantSqlSelector())->select($projection, 7);
            self::assertSame([7], $selection->params);
            self::assertSame('`_company_source`.`supplier_id` = ?', $selection->where);
            $identity = CompanyBackupSourceIdentityProjection::fromDefinition($definition)->identityForRow($row);
            self::assertCount(3, $identity->keys());
            $mapped = $projection->references->remap($row,
                static function (CompanyBackupReference $reference, array $values): array {
                    self::assertIsInt($values[0]);
                    return [$values[0] + 100];
                });
            $expectedRow = array_replace($row, ['supplier_id' => 107]);
            if (array_key_exists('created_by', $row)) {
                $expectedRow['created_by'] = 117;
                self::assertSame(['null', 'restore_actor'], $projection->references->references[0]->fallbacks);
            }
            self::assertSame($expectedRow, $mapped);
            self::assertSame([], $projection->restoreOverrides->overrides);
            self::assertSame([], $projection->omitColumns);
        }
    }
}
