<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration;

use MyInvoice\Action\PriceList\PriceListItemAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

/**
 * Reprodukuje chybu: při currency-filtrovaném seznamu (contextualResolution) se pro
 * stránky 2+ interních dávek (po 200 položkách) filtr prices_include_vat vyhodnocuje
 * jako `!empty($q['prices_include_vat'])` místo null-aware hodnoty jako na první
 * stránce. Když filtr není zadán, `!empty(null) === false`, takže se druhá a další
 * dávka omezí jen na položky s prices_include_vat=0 a položky s cenami vč. DPH se
 * za prvními 200 tiše ztratí.
 */
#[Group('integration')]
final class PriceListItemListPaginationTest extends TestCase
{
    private Connection $db;
    private PriceListItemAction $action;
    private int $supplierId;
    private int $currencyId;
    private string $currencyCode;
    private int $vatRateId;
    private string $prefix;
    /** @var list<int> */
    private array $createdItemIds = [];
    private ?bool $originalStockEnabled = null;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 3) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php missing');
        }
        try {
            $container = Bootstrap::buildApp()->getContainer();
            if ($container === null) $this->markTestSkipped('Container not available');
            $this->db = $container->get(Connection::class);
            $this->action = $container->get(PriceListItemAction::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI unavailable: ' . $e->getMessage());
        }

        $context = $this->db->pdo()->query(
            "SELECT s.id AS supplier_id, cur.id AS currency_id, cur.code AS currency_code
               FROM supplier s
               JOIN currencies cur ON cur.supplier_id = s.id AND cur.is_active = 1
              ORDER BY s.id, cur.is_default DESC, cur.id
              LIMIT 1"
        )->fetch(PDO::FETCH_ASSOC);
        if (!$context) $this->markTestSkipped('Missing supplier/currency test context');

        $this->supplierId = (int) $context['supplier_id'];
        $this->currencyId = (int) $context['currency_id'];
        $this->currencyCode = (string) $context['currency_code'];
        $this->vatRateId = (int) $this->db->pdo()->query(
            'SELECT id FROM vat_rates WHERE is_reverse_charge = 0 ORDER BY is_default DESC, id LIMIT 1'
        )->fetchColumn();
        if ($this->vatRateId <= 0) $this->markTestSkipped('Missing VAT rate');

        $stmt = $this->db->pdo()->prepare('SELECT stock_enabled FROM supplier WHERE id = ?');
        $stmt->execute([$this->supplierId]);
        $this->originalStockEnabled = (bool) $stmt->fetchColumn();
        $this->db->pdo()->prepare('UPDATE supplier SET stock_enabled = 0 WHERE id = ?')
            ->execute([$this->supplierId]);

        $this->prefix = 'PLPAGTEST-' . bin2hex(random_bytes(4));
        $this->seedItems();
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            if ($this->createdItemIds !== []) {
                $placeholders = implode(',', array_fill(0, count($this->createdItemIds), '?'));
                $this->db->pdo()->prepare(
                    "DELETE FROM price_list_item_prices WHERE price_list_item_id IN ($placeholders)"
                )->execute($this->createdItemIds);
                $this->db->pdo()->prepare(
                    "DELETE FROM price_list_items WHERE id IN ($placeholders)"
                )->execute($this->createdItemIds);
            }
            if ($this->originalStockEnabled !== null && isset($this->supplierId)) {
                $this->db->pdo()->prepare('UPDATE supplier SET stock_enabled = ? WHERE id = ?')
                    ->execute([$this->originalStockEnabled ? 1 : 0, $this->supplierId]);
            }
            $this->db->close();
        }
    }

    public function testCurrencyFilteredListDoesNotDropVatInclusiveItemsPastFirstInternalPage(): void
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/price-list-items')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => 1, 'role' => 'admin'])
            ->withQueryParams([
                'q' => $this->prefix,
                'currency' => $this->currencyCode,
                'page' => '2',
                'per_page' => '200',
                // 'prices_include_vat' se záměrně nezadává — musí vrátit obě varianty.
            ]);

        $response = $this->action->list($request, new Psr7Response());
        $response->getBody()->rewind();
        $payload = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame(201, $payload['meta']['total'], 'Celkový počet musí zahrnovat všechny nasazené položky');

        $ids = array_column($payload['data'], 'id');
        self::assertContains(
            $this->createdItemIds[count($this->createdItemIds) - 1],
            $ids,
            'Položka s prices_include_vat=1 na 201. pozici se ztratila při stránkování bez zadaného filtru prices_include_vat',
        );
    }

    private function seedItems(): void
    {
        $pdo = $this->db->pdo();
        $itemStmt = $pdo->prepare(
            'INSERT INTO price_list_items
                (supplier_id, code, name, description, unit, vat_rate_id,
                 prices_include_vat, base_currency_code, allow_exchange_rate_conversion, archived)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, 0)'
        );
        $priceStmt = $pdo->prepare(
            'INSERT INTO price_list_item_prices
                (supplier_id, price_list_item_id, currency_code, unit_price, archived)
             VALUES (?, ?, ?, ?, 0)'
        );

        // 200 položek bez DPH v ceně, seřaditelných před speciální položkou (name 'A-...').
        for ($i = 0; $i < 200; $i++) {
            $suffix = sprintf('A-%03d', $i);
            $itemStmt->execute([
                $this->supplierId,
                $this->prefix . '-' . $suffix,
                $this->prefix . '-' . $suffix,
                'Synthetic pagination test item',
                'ks',
                $this->vatRateId,
                0,
                $this->currencyCode,
            ]);
            $id = (int) $pdo->lastInsertId();
            $this->createdItemIds[] = $id;
            $priceStmt->execute([$this->supplierId, $id, $this->currencyCode, '100.00']);
        }

        // 201. položka (name 'B-...' > 'A-...', tedy alfabeticky poslední) — s cenami vč. DPH.
        $itemStmt->execute([
            $this->supplierId,
            $this->prefix . '-B-SPECIAL',
            $this->prefix . '-B-SPECIAL',
            'Synthetic pagination test item (VAT-inclusive)',
            'ks',
            $this->vatRateId,
            1,
            $this->currencyCode,
        ]);
        $id = (int) $pdo->lastInsertId();
        $this->createdItemIds[] = $id;
        $priceStmt->execute([$this->supplierId, $id, $this->currencyCode, '121.00']);
    }
}
