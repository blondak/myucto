<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Eshop;

use MyInvoice\Repository\StockItemPriceRepository;
use MyInvoice\Repository\StockItemPromoPriceRepository;
use MyInvoice\Service\Eshop\Pricing\EffectivePriceResolver;
use MyInvoice\Service\Eshop\Pricing\PriceCalculationService;
use MyInvoice\Tests\Integration\Stock\StockTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Akční (promoční) ceny — {@see EffectivePriceResolver} (migrace 1328).
 *
 * Pokrývá to, co rozhoduje o ceně na dokladu: hranice časového okna, tři režimy
 * množstevního stropu, vyčerpaný strop, překryv akcí a pravidlo „vše nebo nic
 * per řádek". Staví na StockTestCase (throwaway supplier, receiveStock seeduje
 * skladový stav, invoiceDraft/invoiceItem staví doklady pro dopočet čerpání).
 */
#[Group('integration')]
final class EshopPromoPriceTest extends StockTestCase
{
    private EffectivePriceResolver $resolver;
    private StockItemPromoPriceRepository $promos;
    private StockItemPriceRepository $prices;
    private PriceCalculationService $calc;

    /** Referenční „dnešek" testu — daleko v budoucnu, ať nekoliduje s reálnými daty. */
    private const TODAY = '2099-06-15';

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = $this->container->get(EffectivePriceResolver::class);
        $this->promos   = $this->container->get(StockItemPromoPriceRepository::class);
        $this->prices   = $this->container->get(StockItemPriceRepository::class);
        $this->calc     = $this->container->get(PriceCalculationService::class);
    }

    /** Karta se standardní cenou 1000 CZK (fixed režim → deterministické). */
    private function pricedItem(int $supplierId, string $sku, string $price = '1000.00'): int
    {
        $item = $this->item($supplierId, $sku);
        $this->prices->upsert($supplierId, $item, 'CZK', [
            'price_mode' => 'fixed', 'markup_pct' => null, 'fixed_price' => $price,
            'rounding' => 'none', 'is_manual_override' => false,
        ]);
        $this->calc->recompute($supplierId, $item);
        return $item;
    }

    /** @param array<string,mixed> $over */
    private function promo(int $supplierId, int $itemId, array $over = []): int
    {
        return $this->promos->insert($supplierId, $itemId, array_merge([
            'currency_code' => 'CZK',
            'promo_price'   => '790.00',
            'label'         => 'Akce',
            'valid_from'    => null,
            'valid_to'      => null,
            'qty_mode'      => 'unlimited',
            'qty_limit'     => null,
            'is_active'     => true,
            'note'          => null,
        ], $over));
    }

    // ── standardní cena bez akce ─────────────────────────────────────────────

    public function testWithoutPromoResolverReturnsStandardPrice(): void
    {
        $sid = $this->createSupplier();
        $item = $this->pricedItem($sid, 'PROMO-NONE');

        $r = $this->resolver->resolve($sid, $item, 'CZK', '1', self::TODAY);

        self::assertSame('1000.00', $r['base_price']);
        self::assertSame('1000.00', $r['unit_price']);
        self::assertFalse($r['promo_applied']);
        self::assertSame('none', $r['promo_reason']);
    }

    public function testCardWithoutPriceRowFallsBackToStockCardSalePrice(): void
    {
        $sid = $this->createSupplier();
        $item = $this->item($sid, 'PROMO-FALLBACK');
        // Jen zrcadlo ve skladové kartě, žádný řádek stock_item_prices.
        $this->itemsRepo->setSalePrice($sid, $item, '555.00');
        $this->promo($sid, $item, ['promo_price' => '499.00']);

        $r = $this->resolver->resolve($sid, $item, 'CZK', '1', self::TODAY);

        self::assertSame('555.00', $r['base_price'], 'Bez cenového řádku se u CZK bere sale_price_without_vat.');
        self::assertSame('499.00', $r['unit_price']);
    }

    // ── časové okno (hranice) ────────────────────────────────────────────────

    public function testValidFromIsInclusiveOnItsFirstDay(): void
    {
        $sid = $this->createSupplier();
        $item = $this->pricedItem($sid, 'PROMO-FROM');
        $this->promo($sid, $item, ['valid_from' => self::TODAY]);

        self::assertSame('790.00', $this->resolver->resolve($sid, $item, 'CZK', '1', self::TODAY)['unit_price']);
        self::assertSame('1000.00', $this->resolver->resolve($sid, $item, 'CZK', '1', '2099-06-14')['unit_price'],
            'Den před začátkem akce se ještě nesmí uplatnit.');
    }

    public function testValidToIsInclusiveOnItsLastDay(): void
    {
        $sid = $this->createSupplier();
        $item = $this->pricedItem($sid, 'PROMO-TO');
        $this->promo($sid, $item, ['valid_to' => self::TODAY]);

        self::assertSame('790.00', $this->resolver->resolve($sid, $item, 'CZK', '1', self::TODAY)['unit_price']);
        self::assertSame('1000.00', $this->resolver->resolve($sid, $item, 'CZK', '1', '2099-06-16')['unit_price'],
            'Den po konci akce už platí standardní cena.');
    }

    public function testOpenEndedWindowAppliesAlways(): void
    {
        $sid = $this->createSupplier();
        $item = $this->pricedItem($sid, 'PROMO-OPEN');
        $this->promo($sid, $item);

        self::assertSame('790.00', $this->resolver->resolve($sid, $item, 'CZK', '1', '2000-01-01')['unit_price']);
        self::assertSame('790.00', $this->resolver->resolve($sid, $item, 'CZK', '1', '2199-12-31')['unit_price']);
    }

    public function testInactivePromoIsIgnored(): void
    {
        $sid = $this->createSupplier();
        $item = $this->pricedItem($sid, 'PROMO-OFF');
        $this->promo($sid, $item, ['is_active' => false]);

        self::assertSame('1000.00', $this->resolver->resolve($sid, $item, 'CZK', '1', self::TODAY)['unit_price']);
    }

    public function testPromoInAnotherCurrencyDoesNotAffectCzk(): void
    {
        $sid = $this->createSupplier();
        $item = $this->pricedItem($sid, 'PROMO-EUR');
        $this->promo($sid, $item, ['currency_code' => 'EUR', 'promo_price' => '19.00']);

        self::assertSame('1000.00', $this->resolver->resolve($sid, $item, 'CZK', '1', self::TODAY)['unit_price']);
    }

    // ── režim stropu: do vyprodání zásob ─────────────────────────────────────

    public function testStockModeAppliesWhileStockCoversTheLine(): void
    {
        $sid = $this->createSupplier();
        $wh = $this->warehouse($sid);
        $item = $this->pricedItem($sid, 'PROMO-STOCK');
        $this->receiveStock($sid, $wh, $item, '10.000', 500.0);
        $this->promo($sid, $item, ['qty_mode' => 'stock']);

        $r = $this->resolver->resolve($sid, $item, 'CZK', '10', self::TODAY);
        self::assertSame('790.00', $r['unit_price']);
        self::assertSame('10.000', $r['promo_qty_available']);
    }

    public function testStockModeIsExhaustedWithoutStock(): void
    {
        $sid = $this->createSupplier();
        $this->warehouse($sid);
        $item = $this->pricedItem($sid, 'PROMO-NOSTOCK');
        $this->promo($sid, $item, ['qty_mode' => 'stock']);

        $r = $this->resolver->resolve($sid, $item, 'CZK', '1', self::TODAY);
        self::assertSame('1000.00', $r['unit_price']);
        self::assertSame('exhausted', $r['promo_reason']);
    }

    public function testStockModeReArmsAfterRestock(): void
    {
        $sid = $this->createSupplier();
        $wh = $this->warehouse($sid);
        $item = $this->pricedItem($sid, 'PROMO-REARM');
        $this->promo($sid, $item, ['qty_mode' => 'stock']);

        self::assertSame('exhausted', $this->resolver->resolve($sid, $item, 'CZK', '1', self::TODAY)['promo_reason']);

        $this->receiveStock($sid, $wh, $item, '4.000', 500.0);

        // Jádro výkladu „do vyprodání zásob": strop se NEODEČÍTÁ, čte se živě
        // ze skladu, takže doskladnění akci znovu nabije.
        $r = $this->resolver->resolve($sid, $item, 'CZK', '4', self::TODAY);
        self::assertSame('790.00', $r['unit_price']);
        self::assertSame('4.000', $r['promo_qty_available']);
    }

    // ── režim stropu: pevný rozpočet kusů ────────────────────────────────────

    public function testLimitedModeCountsDownWithIssuedInvoices(): void
    {
        $sid = $this->createSupplier();
        $item = $this->pricedItem($sid, 'PROMO-LIMIT');
        $this->promo($sid, $item, [
            'qty_mode' => 'limited', 'qty_limit' => '10.000', 'valid_from' => '2099-01-01',
        ]);

        self::assertSame('10.000', $this->resolver->resolve($sid, $item, 'CZK', '1', self::TODAY)['promo_qty_available']);

        // 4 ks prodané za akční cenu na vystavené faktuře → rozpočet klesne.
        $this->issuedSale($sid, $item, '4.000', 790.0);

        $r = $this->resolver->resolve($sid, $item, 'CZK', '1', self::TODAY);
        self::assertSame('6.000', $r['promo_qty_available']);
        self::assertSame('790.00', $r['unit_price']);
    }

    public function testLimitedModeIgnoresLinesSoldAboveThePromoPrice(): void
    {
        $sid = $this->createSupplier();
        $item = $this->pricedItem($sid, 'PROMO-ABOVE');
        $this->promo($sid, $item, [
            'qty_mode' => 'limited', 'qty_limit' => '10.000', 'valid_from' => '2099-01-01',
        ]);

        // Prodáno za plnou cenu — zákazník slevu nedostal, rozpočet se nečerpá.
        $this->issuedSale($sid, $item, '4.000', 1000.0);

        self::assertSame('10.000', $this->resolver->resolve($sid, $item, 'CZK', '1', self::TODAY)['promo_qty_available']);
    }

    public function testLimitedModeIgnoresDraftInvoices(): void
    {
        $sid = $this->createSupplier();
        $item = $this->pricedItem($sid, 'PROMO-DRAFT');
        $this->promo($sid, $item, [
            'qty_mode' => 'limited', 'qty_limit' => '10.000', 'valid_from' => '2099-01-01',
        ]);
        $client = $this->client($sid);
        $inv = $this->invoiceDraft($sid, $client); // zůstává draft
        $this->invoiceItem($inv, $item, null, '3.000', 790.0);

        self::assertSame('10.000', $this->resolver->resolve($sid, $item, 'CZK', '1', self::TODAY)['promo_qty_available']);
    }

    public function testCreditNoteReleasesTheConsumedBudget(): void
    {
        $sid = $this->createSupplier();
        $item = $this->pricedItem($sid, 'PROMO-CN');
        $this->promo($sid, $item, [
            'qty_mode' => 'limited', 'qty_limit' => '10.000', 'valid_from' => '2099-01-01',
        ]);
        $this->issuedSale($sid, $item, '6.000', 790.0);
        self::assertSame('4.000', $this->resolver->resolve($sid, $item, 'CZK', '1', self::TODAY)['promo_qty_available']);

        $this->issuedSale($sid, $item, '2.000', 790.0, 'credit_note');

        self::assertSame('6.000', $this->resolver->resolve($sid, $item, 'CZK', '1', self::TODAY)['promo_qty_available'],
            'Dobropis vrací kusy zpět do rozpočtu akce.');
    }

    public function testExhaustedLimitedPromoFallsBackToStandardPrice(): void
    {
        $sid = $this->createSupplier();
        $item = $this->pricedItem($sid, 'PROMO-USED');
        $this->promo($sid, $item, [
            'qty_mode' => 'limited', 'qty_limit' => '5.000', 'valid_from' => '2099-01-01',
        ]);
        $this->issuedSale($sid, $item, '5.000', 790.0);

        $r = $this->resolver->resolve($sid, $item, 'CZK', '1', self::TODAY);
        self::assertSame('1000.00', $r['unit_price']);
        self::assertFalse($r['promo_applied']);
        self::assertSame('exhausted', $r['promo_reason']);
    }

    public function testOpenEndedPromoDoesNotCountSalesFromBeforeItWasCreated(): void
    {
        $sid = $this->createSupplier();
        $item = $this->pricedItem($sid, 'PROMO-PAST');
        // Prodej dávno v minulosti — tedy PŘED datem, kdy akce vznikla (created_at = teď).
        $this->issuedSale($sid, $item, '9.000', 100.0, 'invoice', '2020-03-03');

        // Akce bez valid_from → okno čerpání začíná dnem založení (created_at).
        $this->promo($sid, $item, ['qty_mode' => 'limited', 'qty_limit' => '10.000']);

        self::assertSame('10.000', $this->resolver->resolve($sid, $item, 'CZK', '1', self::TODAY)['promo_qty_available'],
            'Historické prodeje nesmí sežrat rozpočet akce, která tehdy neexistovala.');
    }

    // ── režim stropu: bez omezení ────────────────────────────────────────────

    public function testUnlimitedModeHasNoCap(): void
    {
        $sid = $this->createSupplier();
        $item = $this->pricedItem($sid, 'PROMO-UNL');
        $this->promo($sid, $item, ['qty_mode' => 'unlimited']);

        $r = $this->resolver->resolve($sid, $item, 'CZK', '99999', self::TODAY);
        self::assertSame('790.00', $r['unit_price']);
        self::assertNull($r['promo_qty_available']);
    }

    // ── vše nebo nic per řádek ───────────────────────────────────────────────

    public function testPromoIsNotAppliedWhenCapDoesNotCoverTheWholeLine(): void
    {
        $sid = $this->createSupplier();
        $wh = $this->warehouse($sid);
        $item = $this->pricedItem($sid, 'PROMO-PARTIAL');
        $this->receiveStock($sid, $wh, $item, '3.000', 500.0);
        $this->promo($sid, $item, ['qty_mode' => 'stock']);

        $r = $this->resolver->resolve($sid, $item, 'CZK', '5', self::TODAY);
        self::assertSame('1000.00', $r['unit_price'], 'Míchaná jednotková cena se nedělá — řádek jde za standardní cenu.');
        self::assertFalse($r['promo_applied']);
        self::assertSame('qty_exceeds_remaining', $r['promo_reason']);
        self::assertSame('3.000', $r['promo_qty_available'], 'UI musí vědět, na kolik kusů by akce platila.');
    }

    public function testCheapestPromoWithTooSmallCapFallsThroughToTheNextOne(): void
    {
        $sid = $this->createSupplier();
        $wh = $this->warehouse($sid);
        $item = $this->pricedItem($sid, 'PROMO-FALLTHRU');
        $this->receiveStock($sid, $wh, $item, '2.000', 500.0);
        $this->promo($sid, $item, ['promo_price' => '600.00', 'qty_mode' => 'stock']);      // levnější, málo kusů
        $this->promo($sid, $item, ['promo_price' => '850.00', 'qty_mode' => 'unlimited']);  // dražší, bez stropu

        $r = $this->resolver->resolve($sid, $item, 'CZK', '5', self::TODAY);
        self::assertSame('850.00', $r['unit_price'], 'Dražší akce bez stropu je pořád lepší než plná cena.');
        self::assertTrue($r['promo_applied']);
    }

    // ── překryv akcí ─────────────────────────────────────────────────────────

    public function testOverlappingPromosCheapestWins(): void
    {
        $sid = $this->createSupplier();
        $item = $this->pricedItem($sid, 'PROMO-OVERLAP');
        $this->promo($sid, $item, ['promo_price' => '900.00', 'label' => 'Sezona']);
        $this->promo($sid, $item, ['promo_price' => '750.00', 'label' => 'Vyprodej']);
        $this->promo($sid, $item, ['promo_price' => '880.00', 'label' => 'Newsletter']);

        $r = $this->resolver->resolve($sid, $item, 'CZK', '1', self::TODAY);
        self::assertSame('750.00', $r['unit_price']);
        self::assertSame('Vyprodej', $r['promo']['label']);
    }

    public function testTieOnPriceIsBrokenByTheNewerPromo(): void
    {
        $sid = $this->createSupplier();
        $item = $this->pricedItem($sid, 'PROMO-TIE');
        $this->promo($sid, $item, ['promo_price' => '800.00', 'label' => 'Stara']);
        $this->promo($sid, $item, ['promo_price' => '800.00', 'label' => 'Nova']);

        self::assertSame('Nova', $this->resolver->resolve($sid, $item, 'CZK', '1', self::TODAY)['promo']['label']);
    }

    public function testPromoAboveStandardPriceIsIgnored(): void
    {
        $sid = $this->createSupplier();
        $item = $this->pricedItem($sid, 'PROMO-EXPENSIVE', '500.00');
        $this->promo($sid, $item, ['promo_price' => '790.00']);

        $r = $this->resolver->resolve($sid, $item, 'CZK', '1', self::TODAY);
        self::assertSame('500.00', $r['unit_price'], 'Po snížení běžné ceny nesmí stará akce zboží zdražit.');
        self::assertSame('not_cheaper', $r['promo_reason']);
    }

    // ── anotace pro editor ───────────────────────────────────────────────────

    public function testAnnotateReportsLifecycleStates(): void
    {
        $sid = $this->createSupplier();
        $item = $this->pricedItem($sid, 'PROMO-STATES');
        $this->promo($sid, $item, ['label' => 'bezi']);
        $this->promo($sid, $item, ['label' => 'planovana', 'valid_from' => '2199-01-01']);
        $this->promo($sid, $item, ['label' => 'skoncila', 'valid_to' => '2000-01-01']);
        $this->promo($sid, $item, ['label' => 'vypnuta', 'is_active' => false]);
        $this->promo($sid, $item, ['label' => 'vycerpana', 'qty_mode' => 'stock']); // bez skladu

        $byLabel = [];
        foreach ($this->resolver->annotate($sid, $this->promos->listForItem($sid, $item), self::TODAY) as $row) {
            $byLabel[(string) $row['label']] = (string) $row['state'];
        }

        self::assertSame('active',    $byLabel['bezi']);
        self::assertSame('scheduled', $byLabel['planovana']);
        self::assertSame('expired',   $byLabel['skoncila']);
        self::assertSame('disabled',  $byLabel['vypnuta']);
        self::assertSame('exhausted', $byLabel['vycerpana']);
    }

    // ── dávkové čtení ────────────────────────────────────────────────────────

    public function testResolveManyReturnsOneEntryPerCard(): void
    {
        $sid = $this->createSupplier();
        $a = $this->pricedItem($sid, 'PROMO-BULK-A');
        $b = $this->pricedItem($sid, 'PROMO-BULK-B', '2000.00');
        $this->promo($sid, $a, ['promo_price' => '640.00']);

        $res = $this->resolver->resolveMany($sid, [$a, $b], 'CZK', '1', self::TODAY);

        self::assertSame('640.00', $res[$a]['unit_price']);
        self::assertSame('2000.00', $res[$b]['unit_price']);
        self::assertFalse($res[$b]['promo_applied']);
    }

    // ── tenant izolace ───────────────────────────────────────────────────────

    public function testPromoOfAnotherTenantIsInvisible(): void
    {
        $sidA = $this->createSupplier();
        $sidB = $this->createSupplier();
        $item = $this->pricedItem($sidA, 'PROMO-TENANT');
        $this->promo($sidA, $item, ['promo_price' => '100.00']);

        $r = $this->resolver->resolve($sidB, $item, 'CZK', '1', self::TODAY);
        self::assertNull($r['base_price'], 'Cizí karta nesmí být pro jiného tenanta vidět.');
        self::assertFalse($r['promo_applied']);
    }

    // ── helper: vystavená faktura s jedním skladovým řádkem ──────────────────

    private function issuedSale(
        int $supplierId,
        int $itemId,
        string $qty,
        float $unitPrice,
        string $type = 'invoice',
        string $date = '2099-06-10',
    ): int {
        $client = $this->client($supplierId);
        $invoiceId = $this->invoiceDraft($supplierId, $client, $type, [
            'issue_date' => $date,
            'due_date'   => $date,
            'tax_date'   => $date,
        ]);
        $this->invoiceItem($invoiceId, $itemId, null, $qty, $unitPrice);
        $this->db->pdo()->prepare('UPDATE invoices SET status = "issued" WHERE id = ?')->execute([$invoiceId]);
        return $invoiceId;
    }
}
