<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Accounting;

use MyInvoice\Service\Accounting\Expense\PurchaseDiscountAllocation as A;
use PHPUnit\Framework\TestCase;

final class PurchaseDiscountAllocationTest extends TestCase
{
    private static function item(int $id, string $description, float $net, ?string $kind = null, float $rate = 21.0, ?string $account = null): array
    {
        return ['id' => $id, 'description' => $description, 'total_without_vat' => $net,
            'vat_rate_snapshot' => $rate, 'expense_kind' => $kind, 'expense_account_code' => $account];
    }

    public function testGoodsDiscountSkipsShipping(): void
    {
        $items = [
            self::item(1, 'Thunderbolt adaptér', 197.39, 'small_asset'),
            self::item(2, 'Versandkosten', 5.59, 'service'),
            self::item(3, 'Aktionsrabatt', -7.24, 'service'),
        ];
        self::assertSame([3 => [1 => 1.0]], A::allocate($items));
        self::assertEquals([1 => 190.15, 2 => 5.59], A::netsAfterDiscounts($items));
    }

    public function testShippingDiscountGoesToShipping(): void
    {
        $items = [
            self::item(1, 'Monitor', 34919.26, 'small_asset'),
            self::item(2, 'Doručení na prodejnu', 37.19, 'service'),
            self::item(3, 'Sleva na dopravné', -37.19, 'service'),
        ];
        self::assertSame([3 => [2 => 1.0]], A::allocate($items));
    }

    public function testProportionalWithinSameVatRate(): void
    {
        $items = [
            self::item(1, 'Kniha', 100.00, 'material', 10.0),
            self::item(2, 'Tablet', 300.00, 'small_asset'),
            self::item(3, 'Kabel', 100.00, 'material'),
            self::item(4, 'Sleva 10 %', -40.00, null),
        ];
        self::assertEquals([2 => 270.00, 3 => 90.00, 1 => 100.00], A::netsAfterDiscounts($items));
    }

    public function testReturnedGoodsAreSkippedAndPartialReturnWeighsTheRest(): void
    {
        $switch = self::item(1, 'Switch', 3000.00, 'small_asset') + ['returned_without_vat' => 3000.00];
        $router = self::item(2, 'Router', 1000.00, 'small_asset');
        $voucher = self::item(3, 'Dárkový šek voucher', -100.00, 'service');
        self::assertSame([3 => [2 => 1.0]], A::allocate([$switch, $router, $voucher]));

        $switch['returned_without_vat'] = 2000.00;
        self::assertEquals([3 => [1 => 0.5, 2 => 0.5]], A::allocate([$switch, $router, $voucher]));
    }

    public function testNonDiscountNegativeLineAndExplicitAccountAreLeftAlone(): void
    {
        $items = [
            self::item(1, 'Zboží', 100.00, 'material'),
            self::item(2, 'Vratka obalu', -10.00, 'service'),
            self::item(3, 'Sleva', -5.00, 'service', 21.0, '648'),
        ];
        self::assertSame([], A::allocate($items));
    }
}
