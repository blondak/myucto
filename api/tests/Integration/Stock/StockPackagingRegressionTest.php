<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Stock;

use MyInvoice\Action\Stock\StockItemAction;
use MyInvoice\Action\Stock\StockItemQuoteAction;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\InTransitRepository;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Repository\StockItemPriceRepository;
use MyInvoice\Repository\StockItemPromoPriceRepository;
use MyInvoice\Service\Eshop\Pricing\EffectivePriceResolver;
use MyInvoice\Service\Eshop\Pricing\PriceCalculationService;
use MyInvoice\Service\Pdf\InvoicePdfRenderer;
use MyInvoice\Service\Stock\StockItemPackagingService;
use MyInvoice\Service\Stock\StockUnitConverter;
use PHPUnit\Framework\Attributes\Group;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

/**
 * Balení a zákaznické ceny (issue #17) jsou čistě opt-in: karta bez balení
 * a bez zákaznických cen, firma bez skladu a převodní jednotky šarží se musí
 * chovat PŘESNĚ jako před touto změnou. Každé tvrzení tady by prošlo i na
 * původním kódu — hlídá, že nová logika neprosákla do výchozího chování.
 */
#[Group('integration')]
final class StockPackagingRegressionTest extends StockTestCase
{
    private const TODAY = '2099-06-15';

    public function testStockDisabledSupplierIssuesWithoutStockDocumentsAndPdfNote(): void
    {
        $sid = $this->createSupplier(stockEnabled: false);
        $wh = $this->warehouse($sid);
        $item = $this->item($sid, 'OFF');
        // I kdyby na kartě bylo balení (firma sklad vypnula později), nic se nepřepočítá.
        $this->db->pdo()->prepare("INSERT INTO stock_item_units (supplier_id, stock_item_id, unit_code, numerator, denominator, is_sales_unit) VALUES (?, ?, 'KT', 8, 1, 1)")
            ->execute([$sid, $item]);
        $inv = $this->invoiceDraft($sid, $this->client($sid));
        $this->setUnit($this->invoiceItem($inv, $item, $wh, '10.000', 800.0), 'KT');

        $this->issue->assertAvailableForInvoice($sid, $this->invoiceRow($inv, $sid));
        $this->inTx(fn () => $this->issue->issueForInvoice($sid, $this->invoiceRow($inv, $sid), $this->userId));
        self::assertSame([], $this->docsRepo->listByInvoice($sid, $inv));

        self::assertSame('10.000', $this->container->get(StockUnitConverter::class)->toBase($sid, $item, 'KT', '10'));
        $html = $this->container->get(InvoicePdfRenderer::class)
            ->renderHtml($this->container->get(InvoiceRepository::class)->find($inv), includeCss: false, includeWorkReport: false);
        self::assertStringNotContainsString('(celkem', $html);

        $this->db->pdo()->prepare("UPDATE invoices SET status = 'issued' WHERE id = ?")->execute([$inv]);
        $reserved = $this->container->get(InTransitRepository::class)->reservedForItems($sid, [$item]);
        self::assertSame('10.000', $reserved[0]['qty_reserved']);
    }

    public function testLegacyTrackingUnitIssuesOneToOne(): void
    {
        $sid = $this->createSupplier();
        $wh = $this->warehouse($sid);
        $item = $this->item($sid, 'LEGACY');
        $this->receiveStock($sid, $wh, $item, '10.000', 10.0);
        // Převodní jednotka šarží z 1793 (is_sales_unit = 0) se na doklady nepromítá.
        $this->db->pdo()->prepare("INSERT INTO stock_item_units (supplier_id, stock_item_id, unit_code, numerator, denominator) VALUES (?, ?, 'bal', 5, 1)")
            ->execute([$sid, $item]);
        $inv = $this->invoiceDraft($sid, $this->client($sid));
        $this->setUnit($this->invoiceItem($inv, $item, $wh, '3.000', 100.0), 'bal');

        $this->inTx(fn () => $this->issue->issueForInvoice($sid, $this->invoiceRow($inv, $sid), $this->userId));
        $docs = $this->docsRepo->listByInvoice($sid, $inv);
        self::assertSame('3.000', (string) $this->docsRepo->lines($sid, (int) $docs[0]['id'])[0]['qty']);
        self::assertSame(7000, $this->level($sid, $wh, $item)['qtyT']);

        // Ani našeptávač ani detail karty převodní jednotku šarží nevydají.
        $action = $this->container->get(StockItemAction::class);
        self::assertSame([], $this->body($action->get($this->request('GET', $sid), new Psr7Response(), ['id' => (string) $item]))['units']);
        self::assertSame([], $this->body($action->search($this->request('GET', $sid, null, ['q' => 'LEGACY']), new Psr7Response()))[0]['units']);
        self::assertSame([], $this->container->get(StockItemPackagingService::class)->get($sid, $item)['units']);
    }

    public function testOrdinaryIssueCreditNoteAndCancelStayOneToOne(): void
    {
        $sid = $this->createSupplier();
        $wh = $this->warehouse($sid);
        $item = $this->item($sid, 'PLAIN');
        $this->receiveStock($sid, $wh, $item, '20.000', 10.0);
        $client = $this->client($sid);

        $parent = $this->invoiceDraft($sid, $client);
        $this->invoiceItem($parent, $item, $wh, '5.000', 100.0);
        $this->inTx(fn () => $this->issue->issueForInvoice($sid, $this->invoiceRow($parent, $sid), $this->userId));
        self::assertSame(15000, $this->level($sid, $wh, $item)['qtyT']);

        $credit = $this->invoiceDraft($sid, $client, 'credit_note', ['parent_invoice_id' => $parent]);
        $this->invoiceItem($credit, $item, $wh, '2.000', 100.0);
        $this->inTx(fn () => $this->issue->returnForCreditNote($sid, [
            'id' => $credit, 'parent_invoice_id' => $parent, 'issue_date' => '2099-06-20', 'varsymbol' => '2099902',
        ], $this->userId));
        $docs = $this->docsRepo->listByInvoice($sid, $credit);
        self::assertSame('2.000', (string) $this->docsRepo->lines($sid, (int) $docs[0]['id'])[0]['qty']);
        self::assertSame(17000, $this->level($sid, $wh, $item)['qtyT']);

        $this->inTx(fn () => $this->issue->reverseForInvoice($sid, $parent, $this->userId));
        self::assertSame(22000, $this->level($sid, $wh, $item)['qtyT']);
    }

    public function testResolverWithoutClientReturnsTheOriginalResultShape(): void
    {
        $sid = $this->createSupplier();
        $resolver = $this->container->get(EffectivePriceResolver::class);
        $plain = $this->pricedItem($sid, 'RES-PLAIN');
        $promoted = $this->pricedItem($sid, 'RES-PROMO');
        $promoId = $this->container->get(StockItemPromoPriceRepository::class)->insert($sid, $promoted, [
            'currency_code' => 'CZK', 'promo_price' => '790.00', 'label' => 'Akce', 'valid_from' => null,
            'valid_to' => null, 'qty_mode' => 'unlimited', 'qty_limit' => null, 'is_active' => true, 'note' => null,
        ]);
        // Klient bez zákaznických cen je totéž co žádný klient.
        $clientWithoutPrices = $this->client($sid);

        $expectedPlain = [
            'stock_item_id' => $plain, 'currency_code' => 'CZK', 'base_price' => '1000.00', 'unit_price' => '1000.00',
            'promo_applied' => false, 'promo_reason' => 'none', 'promo_qty_available' => null, 'promo' => null,
        ];
        $expectedPromo = [
            'stock_item_id' => $promoted, 'currency_code' => 'CZK', 'base_price' => '1000.00', 'unit_price' => '790.00',
            'promo_applied' => true, 'promo_reason' => 'applied', 'promo_qty_available' => null,
            'promo' => [
                'id' => $promoId, 'label' => 'Akce', 'promo_price' => '790.00', 'valid_from' => null, 'valid_to' => null,
                'qty_mode' => 'unlimited', 'qty_limit' => null, 'qty_remaining' => null,
            ],
        ];
        foreach ([null, $clientWithoutPrices] as $client) {
            self::assertSame($expectedPlain, $resolver->resolve($sid, $plain, 'CZK', '1', self::TODAY, $client));
            self::assertSame($expectedPromo, $resolver->resolve($sid, $promoted, 'CZK', '1', self::TODAY, $client));
            self::assertSame([$plain => $expectedPlain, $promoted => $expectedPromo], $resolver->resolveMany($sid, [$plain, $promoted], 'CZK', '1', self::TODAY, $client));
        }
    }

    public function testQuoteEqualsEffectivePriceWithoutPackagingAndCustomerPrices(): void
    {
        $sid = $this->createSupplier();
        $priced = $this->pricedItem($sid, 'Q-PRICED');
        $mirror = $this->item($sid, 'Q-MIRROR');
        $this->itemsRepo->setSalePrice($sid, $mirror, '555.50');
        $noPrice = $this->item($sid, 'Q-NOPRICE');
        $items = $this->container->get(StockItemAction::class);
        $quote = $this->container->get(StockItemQuoteAction::class);

        $lines = [];
        foreach ([$priced, $mirror, $noPrice] as $i => $id) {
            $lines[] = ['key' => (string) $i, 'stock_item_id' => $id, 'unit' => $i === 0 ? 'ks' : null, 'quantity' => '1'];
        }
        $res = $this->body($quote->quote($this->request('POST', $sid, ['client_id' => null, 'currency' => 'CZK', 'date' => null, 'lines' => $lines]), new Psr7Response()))['lines'];

        foreach ([$priced, $mirror, $noPrice] as $i => $id) {
            $card = $this->body($items->get($this->request('GET', $sid), new Psr7Response(), ['id' => (string) $id]));
            self::assertSame($card['effective_price'], $res[$i]['unit_price'], "Karta $id");
            self::assertSame($card['effective_price'], $res[$i]['base_unit_price'], "Karta $id");
            self::assertSame('standard', $res[$i]['price_source']);
            self::assertFalse($card['has_customer_prices']);
            self::assertSame([], $card['units']);
        }
        self::assertNull($res[2]['unit_price'], 'Karta bez ceny v měně → null, nikdy 0.');

        // Karta bez ceny v požadované měně — null i v cizí měně.
        $eur = $this->body($quote->quote($this->request('POST', $sid, ['currency' => 'EUR', 'lines' => [$lines[0]]]), new Psr7Response()))['lines'][0];
        self::assertNull($eur['unit_price']);
        self::assertNull($eur['base_unit_price']);
    }

    public function testEffectivePriceInSearchAndDetailIgnoresCustomerPrices(): void
    {
        $sid = $this->createSupplier();
        $item = $this->pricedItem($sid, 'EFF');
        $client = $this->client($sid);
        $this->db->pdo()->prepare(
            "INSERT INTO stock_item_customer_prices (supplier_id, stock_item_id, client_id, currency_code, price_type, fixed_price)
             VALUES (?, ?, ?, 'CZK', 'fixed', 500.00)"
        )->execute([$sid, $item, $client]);
        $action = $this->container->get(StockItemAction::class);

        $card = $this->body($action->get($this->request('GET', $sid), new Psr7Response(), ['id' => (string) $item]));
        $found = $this->body($action->search($this->request('GET', $sid, null, ['q' => 'EFF']), new Psr7Response()))[0];
        self::assertSame('1000.00', $card['effective_price'], 'effective_price zůstává CZK, dnes, bez klienta.');
        self::assertSame('1000.00', $found['effective_price']);
        self::assertTrue($card['has_customer_prices']);
        self::assertTrue($found['has_customer_prices']);
    }

    // ── pomocníci ───────────────────────────────────────────────────────────

    private function pricedItem(int $supplierId, string $sku): int
    {
        $item = $this->item($supplierId, $sku);
        $this->container->get(StockItemPriceRepository::class)->upsert($supplierId, $item, 'CZK', [
            'price_mode' => 'fixed', 'markup_pct' => null, 'fixed_price' => '1000.00',
            'rounding' => 'none', 'is_manual_override' => false,
        ]);
        $this->container->get(PriceCalculationService::class)->recompute($supplierId, $item);
        return $item;
    }

    private function setUnit(int $lineId, string $unit): void
    {
        $this->db->pdo()->prepare('UPDATE invoice_items SET unit = ? WHERE id = ?')->execute([$unit, $lineId]);
    }

    /** @return array<string,mixed> */
    private function invoiceRow(int $invoiceId, int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT id, supplier_id, invoice_type, parent_invoice_id, issue_date, tax_date, varsymbol FROM invoices WHERE id = ? AND supplier_id = ?');
        $stmt->execute([$invoiceId, $supplierId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        $row['id'] = (int) $row['id'];
        return $row;
    }

    private function inTx(callable $fn): void
    {
        $pdo = $this->db->pdo();
        $pdo->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
        $pdo->beginTransaction();
        try {
            $fn();
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /** @param array<string,mixed>|null $body @param array<string,string> $query */
    private function request(string $method, int $supplierId, ?array $body = null, array $query = []): ServerRequestInterface
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest($method, '/api/test')
            ->withQueryParams($query)
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin']);
        return $body !== null ? $request->withParsedBody($body) : $request;
    }

    /** @return array<mixed> */
    private function body(ResponseInterface $response): array
    {
        return json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }
}
