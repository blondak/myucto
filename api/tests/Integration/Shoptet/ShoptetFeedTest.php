<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Shoptet;

use MyInvoice\Action\Eshop\PublicShoptetFeedAction;
use MyInvoice\Service\Shoptet\ShoptetFeedService;
use MyInvoice\Service\Shoptet\ShoptetSettingsService;
use MyInvoice\Service\Stock\SalesOrderService;
use MyInvoice\Tests\Integration\Stock\StockTestCase;
use PHPUnit\Framework\Attributes\Group;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * Feed zásob a cen pro automatický import produktů Shoptetu: token jen jako hash,
 * jen produkty vybrané firmy, zásoba bez rezervací z jiných kanálů, stabilní obsah
 * s ETagem a platnost proti Relax NG schématu Shoptetu (products-supplier-v10.rng).
 */
#[Group('integration')]
final class ShoptetFeedTest extends StockTestCase
{
    private const RNG = __DIR__ . '/../../Fixtures/Shoptet/rng/products-supplier-v10.rng';

    private ShoptetFeedService $feed;
    private ShoptetSettingsService $settings;
    private int $sid = 0;
    private int $warehouseId = 0;
    private int $itemA = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->feed = $this->container->get(ShoptetFeedService::class);
        $this->settings = $this->container->get(ShoptetSettingsService::class);

        $this->sid = $this->createSupplier();
        $this->warehouseId = $this->warehouse($this->sid);
        $second = $this->warehouse($this->sid, 'DRUHY', false);
        $pdo = $this->db->pdo();

        $this->itemA = $this->item($this->sid, 'FEED-A');
        $hidden = $this->item($this->sid, 'FEED-SKRYTY');
        $sizeS = $this->item($this->sid, 'FEED-V-S');
        $sizeM = $this->item($this->sid, 'FEED-V-M');
        $update = $pdo->prepare('UPDATE stock_items SET export_eshop = ?, vat_rate_id = ?, sale_price_without_vat = ?, ean = ? WHERE id = ?');
        $update->execute([1, $this->vatRateId, '100.00', '8590000000024', $this->itemA]);
        $update->execute([0, $this->vatRateId, '50.00', null, $hidden]);
        $update->execute([1, $this->vatRateId, '200.00', null, $sizeS]);
        $update->execute([1, $this->vatRateId, '200.00', null, $sizeM]);

        $pdo->prepare('INSERT INTO product_masters (supplier_id, name) VALUES (?, ?)')->execute([$this->sid, 'Tričko s variantami']);
        $masterId = (int) $pdo->lastInsertId();
        $pdo->prepare('INSERT INTO stock_attributes (supplier_id, code, name) VALUES (?, ?, ?)')->execute([$this->sid, 'velikost', 'Velikost']);
        $attributeId = (int) $pdo->lastInsertId();
        foreach ([$sizeS => 'S', $sizeM => 'M'] as $itemId => $label) {
            $pdo->prepare('INSERT INTO stock_attribute_options (supplier_id, attribute_id, code, label) VALUES (?, ?, ?, ?)')
                ->execute([$this->sid, $attributeId, strtolower($label), $label]);
            $optionId = (int) $pdo->lastInsertId();
            $pdo->prepare('INSERT INTO product_variants (supplier_id, master_id, stock_item_id) VALUES (?, ?, ?)')
                ->execute([$this->sid, $masterId, $itemId]);
            $pdo->prepare('INSERT INTO product_variant_options (supplier_id, master_id, stock_item_id, attribute_id, option_id) VALUES (?, ?, ?, ?, ?)')
                ->execute([$this->sid, $masterId, $itemId, $attributeId, $optionId]);
        }

        $yesterday = date('Y-m-d', strtotime('-1 day'));
        $this->receiveStock($this->sid, $this->warehouseId, $this->itemA, '10', 40.0, $yesterday);
        $this->receiveStock($this->sid, $second, $this->itemA, '5', 40.0, $yesterday);
        $this->receiveStock($this->sid, $this->warehouseId, $sizeS, '4', 80.0, $yesterday);

        // Rezervace z jiného kanálu se od zásoby odečte, rezervace objednávky ze Shoptetu ne.
        $orders = $this->container->get(SalesOrderService::class);
        $clientId = $this->client($this->sid);
        foreach ([[null, '2', 'CHANNEL-1'], ['shoptet', '3', 'SHOPTET-1']] as [$source, $qty, $ref]) {
            $order = $orders->create($this->sid, [
                'client_id' => $clientId,
                'currency_id' => $this->currencyIdFor($this->sid),
                'external_source' => $source,
                'external_id' => $source !== null ? $ref : null,
                'order_number' => $ref,
                'lines' => [['stock_item_id' => $this->itemA, 'warehouse_id' => $this->warehouseId, 'quantity' => $qty, 'unit_price' => '100', 'vat_rate_id' => $this->vatRateId]],
            ], $this->userId);
            $orders->confirm($this->sid, (int) $order['id'], 'feed-' . $ref);
        }
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            if ($this->db->pdo()->inTransaction()) $this->db->pdo()->rollBack();
            foreach ($this->supplierIds as $supplierId) {
                $this->db->pdo()->prepare('DELETE FROM fulfillment_tasks WHERE supplier_id = ?')->execute([$supplierId]);
                $this->db->pdo()->prepare('DELETE FROM sales_orders WHERE supplier_id = ?')->execute([$supplierId]);
                $this->db->pdo()->prepare('DELETE FROM product_masters WHERE supplier_id = ?')->execute([$supplierId]);
            }
        }
        parent::tearDown();
    }

    public function testFeedContainsOnlySelectedProductsWithSellableStockAndIsValid(): void
    {
        $other = $this->createSupplier();
        $foreign = $this->item($other, 'CIZI-FIRMA');
        $this->db->pdo()->prepare('UPDATE stock_items SET export_eshop = 1 WHERE id = ?')->execute([$foreign]);

        $out = $this->feed->xml($this->sid, $this->settings->get($this->sid));
        $xml = new \SimpleXMLElement($out['xml']);

        $codes = array_map('strval', $xml->xpath('//CODE') ?: []);
        sort($codes);
        self::assertSame(['FEED-A', 'FEED-V-M', 'FEED-V-S'], $codes, 'Jen produkty označené pro e-shop a jen této firmy.');
        self::assertSame(3, $out['items']);

        $a = $xml->xpath('//SHOPITEM[CODE="FEED-A"]')[0];
        self::assertSame('13.000', (string) $a->STOCK->AMOUNT, '15 ks na prodejných skladech minus 2 rezervované jiným kanálem.');
        self::assertSame('121.00', (string) $a->PRICE_VAT);
        self::assertSame('8590000000024', (string) $a->EAN);
        self::assertSame('', (string) $a->NAME, 'Název jednoduchého produktu se neposílá.');

        $variant = $xml->xpath('//VARIANT[CODE="FEED-V-S"]')[0];
        self::assertSame('Tričko s variantami', (string) $variant->xpath('../../NAME')[0]);
        self::assertSame('Velikost', (string) $variant->PARAMETERS->PARAMETER->NAME);
        self::assertSame('S', (string) $variant->PARAMETERS->PARAMETER->VALUE);
        self::assertSame('4.000', (string) $variant->STOCK->AMOUNT);

        $dom = new \DOMDocument();
        $dom->loadXML($out['xml']);
        $prev = libxml_use_internal_errors(true);
        $valid = $dom->relaxNGValidate(self::RNG);
        $errors = array_map(static fn (\LibXMLError $e): string => trim($e->message), libxml_get_errors());
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        self::assertTrue($valid, 'Feed musí projít Relax NG schématem Shoptetu: ' . implode('; ', $errors));
    }

    public function testWarehouseChoiceAndPriceToggle(): void
    {
        $this->settings->save($this->sid, ['feed_warehouse_id' => $this->warehouseId, 'feed_include_price' => false], $this->userId);
        $xml = new \SimpleXMLElement($this->feed->xml($this->sid, $this->settings->get($this->sid))['xml']);
        $a = $xml->xpath('//SHOPITEM[CODE="FEED-A"]')[0];

        self::assertSame('8.000', (string) $a->STOCK->AMOUNT, 'Jen zvolený sklad: 10 minus 2.');
        self::assertCount(0, $xml->xpath('//PRICE_VAT'), 'Cena se na přání neposílá.');
    }

    public function testTokenIsStoredOnlyAsHashAndRotationInvalidatesOldToken(): void
    {
        $token = $this->settings->rotateFeedToken($this->sid, $this->userId);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $token);
        $stored = (string) $this->db->pdo()->query('SELECT feed_token_hash FROM shoptet_settings WHERE supplier_id = ' . $this->sid)->fetchColumn();
        self::assertSame(hash('sha256', $token), $stored);
        self::assertNotSame($token, $stored);
        self::assertSame($this->sid, (int) $this->settings->findByFeedToken($token)['supplier_id']);

        $newToken = $this->settings->rotateFeedToken($this->sid, $this->userId);
        self::assertNull($this->settings->findByFeedToken($token), 'Starý token po rotaci neplatí.');
        self::assertNotNull($this->settings->findByFeedToken($newToken));

        $this->settings->disableFeed($this->sid, $this->userId);
        self::assertNull($this->settings->findByFeedToken($newToken));
    }

    public function testPublicFeedServesEtagAndNotModified(): void
    {
        $token = $this->settings->rotateFeedToken($this->sid, $this->userId);
        $action = $this->container->get(PublicShoptetFeedAction::class);
        $requests = new ServerRequestFactory();
        $responses = new ResponseFactory();

        $first = $action($requests->createServerRequest('GET', '/api/public/shoptet/feed/' . $token), $responses->createResponse(), ['token' => $token]);
        self::assertSame(200, $first->getStatusCode());
        self::assertStringStartsWith('application/xml', $first->getHeaderLine('Content-Type'));
        $etag = $first->getHeaderLine('ETag');
        self::assertNotSame('', $etag);
        self::assertNotSame('', $first->getHeaderLine('Last-Modified'));
        self::assertStringContainsString('<CODE>FEED-A</CODE>', (string) $first->getBody());

        $again = $action(
            $requests->createServerRequest('GET', '/api/public/shoptet/feed/' . $token)->withHeader('If-None-Match', $etag),
            $responses->createResponse(),
            ['token' => $token],
        );
        self::assertSame(304, $again->getStatusCode(), 'Nezměněný feed Shoptet nemusí stahovat znovu.');
        self::assertSame($etag, $again->getHeaderLine('ETag'), 'Stejná data dávají stejný ETag.');

        $this->receiveStock($this->sid, $this->warehouseId, $this->itemA, '1', 40.0, date('Y-m-d', strtotime('-1 day')));
        $changed = $action(
            $requests->createServerRequest('GET', '/api/public/shoptet/feed/' . $token)->withHeader('If-None-Match', $etag),
            $responses->createResponse(),
            ['token' => $token],
        );
        self::assertSame(200, $changed->getStatusCode());
        self::assertNotSame($etag, $changed->getHeaderLine('ETag'));

        $unknown = $action($requests->createServerRequest('GET', '/x'), $responses->createResponse(), ['token' => str_repeat('0', 64)]);
        self::assertSame(404, $unknown->getStatusCode());
        self::assertStringNotContainsString('FEED-A', (string) $unknown->getBody());
    }

    public function testCsvSkipsVariantsAndProtectsCells(): void
    {
        $out = $this->feed->csv($this->sid, $this->settings->get($this->sid));
        $lines = array_values(array_filter(explode("\r\n", $out['csv'])));

        self::assertSame('code;pairCode;stock;price;includingVat', $lines[0]);
        self::assertSame('FEED-A;;13.000;121.00;1', $lines[1]);
        self::assertCount(2, $lines, 'Varianty se v CSV nepřenáší (pairCode Shoptetu MyÚčto nezná).');
        self::assertNotEmpty($out['skipped']);
    }

    /**
     * FAIL-BEFORE: kód začínající znakem vzorce dostal apostrof, který se stal součástí
     * kódu, a Shoptet produkt nespároval.
     */
    public function testCsvLeavesOutCodesThatLookLikeFormulas(): void
    {
        $id = $this->item($this->sid, '-FEED-MINUS');
        $this->db->pdo()->prepare('UPDATE stock_items SET export_eshop = 1, vat_rate_id = ?, sale_price_without_vat = ? WHERE id = ?')
            ->execute([$this->vatRateId, '10.00', $id]);

        $out = $this->feed->csv($this->sid, $this->settings->get($this->sid));

        self::assertStringNotContainsString('FEED-MINUS', $out['csv']);
        self::assertStringContainsString('-FEED-MINUS', implode(' | ', $out['skipped']));
    }
}
