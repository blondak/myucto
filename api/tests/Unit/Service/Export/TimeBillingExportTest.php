<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Export;

use MyInvoice\Service\Export\TimeBillingExport;
use PHPUnit\Framework\TestCase;

final class TimeBillingExportTest extends TestCase
{
    public function testWholeHourHasNoDanglingDecimalSeparator(): void
    {
        self::assertSame('1', TimeBillingExport::formatQuantity(['duration_minutes' => 60], 'legacy'));
    }

    public function testLegacyQuantityIsReturnedByteForByte(): void
    {
        self::assertSame('1.20', TimeBillingExport::formatQuantity(['duration_minutes' => null], '1.20'));
    }

    public function testOnlyNonStockHourlySubCentRateOptsIntoPrecision(): void
    {
        self::assertTrue(TimeBillingExport::usesPreciseHourlyRate([
            'unit' => 'h',
            'unit_price_without_vat' => 333.33333,
        ]));
        self::assertFalse(TimeBillingExport::usesPreciseHourlyRate([
            'unit' => 'h',
            'unit_price_without_vat' => 333.33,
        ]));
        self::assertFalse(TimeBillingExport::usesPreciseHourlyRate([
            'stock_item_id' => 5,
            'unit' => 'h',
            'unit_price_without_vat' => 333.33333,
        ]));
    }
}
