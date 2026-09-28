<?php
declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Migration\Abra;

use MyInvoice\Service\Migration\Abra\AbraStockOpeningImporter;
use PHPUnit\Framework\TestCase;

final class AbraStockOpeningImporterTest extends TestCase
{
    public function testPositiveCardKeepsQuantityAndCentValue(): void
    {
        $plan = AbraStockOpeningImporter::mapCard(self::row('3', '10.01'), self::item());

        self::assertNull($plan['reason']);
        self::assertSame('3.000', $plan['qty']);
        self::assertSame('10.01', $plan['value']);
        self::assertSame(1001, (int) round(
            (float) $plan['qty'] * (float) $plan['unit_cost'] * 100
            + (float) $plan['extra_cost'] * 100,
        ));
    }

    public function testNegativeQuantityOrValuationIsNotImported(): void
    {
        self::assertSame('stock_negative_quantity_skipped',
            AbraStockOpeningImporter::mapCard(self::row('-1', '10'), self::item())['reason']);
        self::assertSame('stock_negative_valuation_skipped',
            AbraStockOpeningImporter::mapCard(self::row('1', '-10'), self::item())['reason']);
    }

    public function testSourceWarehousePreflightRejectsMultipleWarehouses(): void
    {
        $this->expectException(\MyInvoice\Service\Migration\Abra\AbraException::class);
        AbraStockOpeningImporter::singleSourceWarehouse([
            ['id' => '3', 'kod' => 'MAIN'], ['id' => '4', 'kod' => 'SECONDARY'],
        ]);
    }

    /** @return array<string,mixed> */
    private static function row(string $quantity, string $value): array
    {
        return ['id' => '1', 'cenik@ref' => '/c/demo/cenik/2.json',
            'sklad@ref' => '/c/demo/sklad/3.json', 'stavMJ' => $quantity, 'stavTuz' => $value];
    }

    /** @return array<string,mixed> */
    private static function item(): array
    {
        return ['id' => 4, 'is_active' => 1, 'is_stocked' => 1, 'tracking_mode' => 'none'];
    }
}
