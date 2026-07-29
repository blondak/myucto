<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Eshop;

use MyInvoice\Repository\StockItemPriceRepository;
use MyInvoice\Repository\StockItemVendorRepository;
use MyInvoice\Service\Eshop\Pricing\PriceCalculationService;
use MyInvoice\Tests\Integration\Stock\StockTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Epic ESHOP F2 — cenotvorba end-to-end (markup CZK/FX, fixed, override,
 * is_stocked=0 vendor fallback, zrcadlo do sale_price_without_vat). Staví na
 * StockTestCase (throwaway supplier, receiveStock seeduje weighted_avg).
 */
#[Group('integration')]
final class EshopPricingTest extends StockTestCase
{
    private PriceCalculationService $calc;
    private StockItemPriceRepository $prices;
    private StockItemVendorRepository $vendors;
    private string $fxDate = '2099-06-10';

    protected function setUp(): void
    {
        parent::setUp();
        $this->calc    = $this->container->get(PriceCalculationService::class);
        $this->prices  = $this->container->get(StockItemPriceRepository::class);
        $this->vendors = $this->container->get(StockItemVendorRepository::class);
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            // Ukliď testovací kurz (exchange_rates je globální, bez supplier_id).
            $this->db->pdo()->prepare('DELETE FROM exchange_rates WHERE rate_date = ? AND currency_code = ?')
                ->execute([$this->fxDate, 'EUR']);
        }
        parent::tearDown();
    }

    private function setPricingBase(int $itemId, string $base): void
    {
        $this->db->pdo()->prepare('UPDATE stock_items SET pricing_base = ? WHERE id = ?')->execute([$base, $itemId]);
    }

    private function priceRow(int $supplierId, int $itemId, string $currency): ?array
    {
        return $this->prices->findByCurrency($supplierId, $itemId, $currency);
    }

    public function testMarkupCzkFromWeightedAvgWithMirror(): void
    {
        $sid = $this->createSupplier();
        $wh = $this->warehouse($sid);
        $item = $this->item($sid, 'PRICE-1');
        $this->receiveStock($sid, $wh, $item, '10.000', 100.0); // weighted_avg = 100 CZK

        $this->prices->upsert($sid, $item, 'CZK', [
            'price_mode' => 'markup', 'markup_pct' => '50', 'fixed_price' => null,
            'rounding' => 'none', 'is_manual_override' => false,
        ]);

        $this->calc->recompute($sid, $item);

        $row = $this->priceRow($sid, $item, 'CZK');
        self::assertSame('150.00', $row['computed_price']);
        // Zrcadlo do skladové karty.
        $card = $this->itemsRepo->find($sid, $item);
        self::assertSame('150.00', $card['sale_price_without_vat']);
    }

    public function testIsStockedZeroUsesPreferredVendorFallback(): void
    {
        $sid = $this->createSupplier();
        $item = $this->item($sid, 'PRICE-NOSTOCK'); // žádná příjemka → weighted_avg null
        $this->setPricingBase($item, 'manual');

        $vendorClient = $this->client($sid, 'Dodavatel A'); // is_vendor=1
        $this->vendors->add($sid, $item, [
            'client_id' => $vendorClient, 'purchase_price' => '200.00',
            'currency_code' => 'CZK', 'is_preferred' => true,
        ]);

        $this->prices->upsert($sid, $item, 'CZK', [
            'price_mode' => 'markup', 'markup_pct' => '25', 'fixed_price' => null,
            'rounding' => 'none', 'is_manual_override' => false,
        ]);

        $this->calc->recompute($sid, $item);

        $row = $this->priceRow($sid, $item, 'CZK');
        self::assertSame('250.00', $row['computed_price'], 'is_stocked=0: nacenění z vendor purchase_price (E5)');
    }

    public function testFixedModeWithNineEndingRounding(): void
    {
        $sid = $this->createSupplier();
        $item = $this->item($sid, 'PRICE-FIX');
        $this->prices->upsert($sid, $item, 'CZK', [
            'price_mode' => 'fixed', 'markup_pct' => null, 'fixed_price' => '199.90',
            'rounding' => '9_ending', 'is_manual_override' => false,
        ]);

        $this->calc->recompute($sid, $item);

        self::assertSame('199.00', $this->priceRow($sid, $item, 'CZK')['computed_price']);
    }

    public function testManualOverrideIsNotRecomputed(): void
    {
        $sid = $this->createSupplier();
        $wh = $this->warehouse($sid);
        $item = $this->item($sid, 'PRICE-OVR');
        $this->receiveStock($sid, $wh, $item, '5.000', 100.0);

        $this->prices->upsert($sid, $item, 'CZK', [
            'price_mode' => 'markup', 'markup_pct' => '10', 'fixed_price' => null,
            'rounding' => 'none', 'is_manual_override' => true,
        ]);
        // Ruční cena nastavená přímo (override).
        $this->db->pdo()->prepare(
            'UPDATE stock_item_prices SET computed_price = ? WHERE supplier_id = ? AND stock_item_id = ? AND currency_code = ?'
        )->execute(['999.00', $sid, $item, 'CZK']);

        $this->calc->recompute($sid, $item);

        self::assertSame('999.00', $this->priceRow($sid, $item, 'CZK')['computed_price'], 'override se nepřepočítává');
    }

    public function testMarkupForeignCurrencyViaFx(): void
    {
        $sid = $this->createSupplier();
        $wh = $this->warehouse($sid);
        $item = $this->item($sid, 'PRICE-EUR');
        $this->receiveStock($sid, $wh, $item, '4.000', 250.0); // weighted_avg = 250 CZK

        // Kurz 1 EUR = 25 CZK k testovacímu datu.
        $this->db->pdo()->prepare(
            'INSERT INTO exchange_rates (rate_date, currency_code, rate) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE rate = VALUES(rate)'
        )->execute([$this->fxDate, 'EUR', '25.000000']);

        $this->prices->upsert($sid, $item, 'EUR', [
            'price_mode' => 'markup', 'markup_pct' => '0', 'fixed_price' => null,
            'rounding' => 'none', 'is_manual_override' => false,
        ]);

        $this->calc->recompute($sid, $item, $this->fxDate);

        $row = $this->priceRow($sid, $item, 'EUR');
        self::assertSame('10.00', $row['computed_price'], '250 CZK / 25 = 10 EUR');
        self::assertSame('25.000000', $row['computed_rate']);
    }

    public function testMissingPurchaseCostYieldsNullComputed(): void
    {
        $sid = $this->createSupplier();
        $item = $this->item($sid, 'PRICE-NONE'); // bez stavu, bez vendora
        $this->prices->upsert($sid, $item, 'CZK', [
            'price_mode' => 'markup', 'markup_pct' => '30', 'fixed_price' => null,
            'rounding' => 'none', 'is_manual_override' => false,
        ]);

        $this->calc->recompute($sid, $item);

        self::assertNull($this->priceRow($sid, $item, 'CZK')['computed_price'], 'chybí NC → computed_price NULL');
    }
}
