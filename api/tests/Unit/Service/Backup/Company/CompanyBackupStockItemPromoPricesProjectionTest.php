<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup\Company;

use MyInvoice\Service\Backup\Company\CompanyBackupReference;
use MyInvoice\Service\Backup\Company\CompanyBackupTableProjection;
use MyInvoice\Service\Backup\Registry\TenantDataPolicy;
use MyInvoice\Service\Backup\Registry\TenantDataRegistry;
use MyInvoice\Service\Backup\Registry\TenantDataRegistryFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CompanyBackupStockItemPromoPricesProjectionTest extends TestCase
{
    /** @return array<string,array{string,?string}> */
    public static function quantityModes(): array
    {
        return [
            'stock' => ['stock', null],
            'limited' => ['limited', '100.000'],
            'unlimited' => ['unlimited', null],
        ];
    }

    #[DataProvider('quantityModes')]
    public function testPromotionPreservesLimitsCurrencyAndConsumptionWindow(string $mode, ?string $limit): void
    {
        $registry = TenantDataRegistryFactory::draftV1();
        $definition = $registry->definition('table:stock_item_promo_prices');
        self::assertNotNull($definition);
        self::assertSame(TenantDataPolicy::TenantOwned, $definition->policy);
        self::assertSame([TenantDataRegistry::COMPANY_BACKUP_PROFILE], $definition->profiles);
        // Překrývající se kampaně se stejnou kartou a měnou jsou povolené.
        self::assertArrayNotHasKey('natural_key', $definition->details);
        $projection = CompanyBackupTableProjection::fromDefinition($definition);
        $projection->assertRegistryTargets($registry);
        $row = [
            'id' => 3, 'supplier_id' => 7, 'stock_item_id' => 11,
            'currency_code' => 'EUR', 'promo_price' => '12.34', 'label' => 'Test campaign',
            'valid_from' => null, 'valid_to' => '2026-12-31',
            'qty_mode' => $mode, 'qty_limit' => $limit, 'is_active' => 0,
            'note' => null, 'created_at' => '2026-01-01 12:00:00',
            'updated_at' => '2026-01-02 12:00:00',
        ];
        self::assertSame(array_keys($row), $projection->dataColumns);
        $visited = [];
        $mapped = $projection->references->remap($row,
            static function (CompanyBackupReference $reference, array $key) use (&$visited): array {
                $visited[] = $reference->target;
                return [$key[0] + 100];
            },
        );
        self::assertSame(['table:stock_items', 'table:supplier'], $visited);
        self::assertSame(array_replace($row, ['supplier_id' => 107, 'stock_item_id' => 111]), $mapped);
    }
}
