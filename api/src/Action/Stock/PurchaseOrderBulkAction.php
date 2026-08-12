<?php

declare(strict_types=1);

namespace MyInvoice\Action\Stock;

use MyInvoice\Action\Accounting\AccountingActionSupport;
use MyInvoice\Http\Json;
use MyInvoice\Http\TenantReferenceGuard;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\InTransitRepository;
use MyInvoice\Repository\WarehouseRepository;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Stock\PurchaseOrderService;
use MyInvoice\Service\Stock\StockException;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Hromadné založení objednávek z obrazovky doplnění zásob
 * (Epic SKLAD „na cestě", §5.6) — `POST /api/stock/purchase-orders/bulk`.
 *
 * Vstup je plochý seznam „chci tohle zboží v tomhle množství"; výstupem je
 * JEDNA objednávka NA DODAVATELE. To seskupení je celý důvod, proč endpoint
 * existuje: uživatel na obrazovce zaškrtne dvacet karet od pěti dodavatelů
 * a nechce z toho udělat dvacet objednávek ručně.
 *
 * Dodavatel se bere z těla requestu, jinak z preferované nabídky karty
 * (`stock_item_vendors.is_preferred`). Karta bez dodavatele se do žádné
 * objednávky nedostane a vrátí se ve `skipped` — tiše ji zahodit by znamenalo,
 * že uživatel objedná míň, než zaškrtl, a nedozví se o tom.
 *
 * Objednávky vznikají jako DRAFT: odeslání (a tím i vstup do „na cestě") je
 * vždycky vědomý krok uživatele, ne vedlejší efekt hromadné akce.
 */
final class PurchaseOrderBulkAction
{
    use AccountingActionSupport;
    use GuardsStockEnabled;

    private const MAX_LINES = 500;

    public function __construct(
        private readonly Connection $db,
        private readonly PurchaseOrderService $orders,
        private readonly InTransitRepository $repo,
        private readonly WarehouseRepository $warehouses,
        private readonly TenantReferenceGuard $tenantRefs,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function create(Request $request, Response $response): Response
    {
        if (!$this->requirePermission($request, $response, 'stock.orders.write', AccessLevel::WRITE, $err)) {
            return $err;
        }
        $supplierId = $this->currentSupplierId($request);
        if (!$this->guardStockEnabled($this->db, $supplierId, $response, $err)) {
            return $err;
        }

        $body     = (array) ($request->getParsedBody() ?? []);
        $rawItems = is_array($body['items'] ?? null) ? array_slice($body['items'], 0, self::MAX_LINES) : [];
        if ($rawItems === []) {
            return Json::error($response, 'invalid_order', 'Zadej aspoň jednu položku k objednání.', 422);
        }

        // Cizí klíče z těla vázané na tenanta (CWE-639 / BOLA). `vendor_id` chodí
        // per položku, takže se guard volá i nad každou z nich — dodavatel z cizí
        // firmy by jinak založil objednávku, kterou by tenant viděl ve svém seznamu.
        $bad = $this->tenantRefs->violations($supplierId, $body, ['currency_id']);
        foreach ($rawItems as $raw) {
            if (is_array($raw)) {
                $bad = array_merge($bad, $this->tenantRefs->violations($supplierId, $raw, ['vendor_id']));
            }
        }
        if ($bad !== []) {
            return Json::error($response, 'invalid_reference', TenantReferenceGuard::message(array_unique($bad)), 422);
        }

        $orderDate = trim((string) ($body['order_date'] ?? date('Y-m-d')));
        $expected  = trim((string) ($body['expected_date'] ?? '')) ?: null;

        $warehouseId = (int) ($body['warehouse_id'] ?? 0);
        if ($warehouseId <= 0) {
            $default     = $this->warehouses->getDefault($supplierId);
            $warehouseId = $default !== null ? (int) $default['id'] : 0;
        }
        if ($warehouseId <= 0) {
            return Json::error($response, 'invalid_order', 'Firma nemá výchozí sklad — vyber sklad ručně.', 422);
        }

        $itemIds = array_values(array_filter(array_map(
            static fn (mixed $i): int => is_array($i) ? (int) ($i['stock_item_id'] ?? 0) : 0,
            $rawItems,
        ), static fn (int $v): bool => $v > 0));

        $items  = $this->itemsById($supplierId, $itemIds);
        $offers = $this->repo->vendorOffersForItems($supplierId, $itemIds);

        /** @var array<int,list<array<string,mixed>>> $byVendor */
        $byVendor = [];
        $skipped  = [];
        foreach ($rawItems as $raw) {
            if (!is_array($raw)) {
                continue;
            }
            $itemId = (int) ($raw['stock_item_id'] ?? 0);
            $item   = $items[$itemId] ?? null;
            if ($item === null) {
                $skipped[] = ['stock_item_id' => $itemId, 'reason' => 'item_not_found'];
                continue;
            }
            $qty = (float) ($raw['qty'] ?? $raw['suggested_qty'] ?? 0);
            if ($qty <= 0) {
                $skipped[] = ['stock_item_id' => $itemId, 'reason' => 'invalid_qty'];
                continue;
            }

            $offer    = $this->offerFor($offers, $itemId, (int) ($raw['vendor_id'] ?? 0));
            $vendorId = (int) ($raw['vendor_id'] ?? 0) > 0 ? (int) $raw['vendor_id'] : (int) ($offer['client_id'] ?? 0);
            if ($vendorId <= 0) {
                $skipped[] = ['stock_item_id' => $itemId, 'sku' => $item['sku'], 'reason' => 'no_vendor'];
                continue;
            }

            $byVendor[$vendorId][] = [
                'stock_item_id' => $itemId,
                'warehouse_id'  => (int) ($raw['warehouse_id'] ?? 0) > 0 ? (int) $raw['warehouse_id'] : null,
                'vendor_sku'    => $offer['vendor_sku'] ?? null,
                'description'   => (string) $item['name'],
                'unit'          => (string) $item['unit'],
                'qty_ordered'   => number_format($qty, 3, '.', ''),
                'unit_price'    => isset($raw['unit_price']) && $raw['unit_price'] !== ''
                    ? number_format((float) $raw['unit_price'], 6, '.', '')
                    : number_format((float) ($offer['purchase_price'] ?? 0), 6, '.', ''),
                'vat_rate_id'   => $item['vat_rate_id'],
                'expected_date' => $expected,
            ];
        }

        if ($byVendor === []) {
            return Json::error($response, 'invalid_order', 'Ani jedna položka nešla objednat — chybí dodavatel nebo množství.', 422, [
                'items' => $skipped,
            ]);
        }

        $currencyId = (int) ($body['currency_id'] ?? 0);
        if ($currencyId <= 0) {
            $currencyId = $this->defaultCurrencyId($supplierId);
        }

        $created = [];
        try {
            foreach ($byVendor as $vendorId => $lines) {
                $created[] = $this->orders->create($supplierId, [
                    'vendor_id'     => $vendorId,
                    'order_date'    => $orderDate,
                    'expected_date' => $expected,
                    'warehouse_id'  => $warehouseId,
                    'currency_id'   => $currencyId,
                    'note'          => self::nullableString($body['note'] ?? null),
                    'lines'         => $lines,
                ], $this->userId($request));
            }
        } catch (\Throwable $e) {
            return $this->mapStockError($response, $e);
        }

        $this->logger->log(
            'stock.orders_bulk_created',
            $this->userId($request),
            'purchase_order',
            (int) ($created[0]['id'] ?? 0),
            ['count' => count($created), 'skipped' => count($skipped)],
            $this->ipMatcher->clientIpFromRequest($request->getServerParams()),
            $request->getHeaderLine('User-Agent'),
            $supplierId,
        );

        return Json::ok($response, ['orders' => $created, 'created' => count($created), 'skipped' => $skipped], 201);
    }

    /**
     * @param list<array<string,mixed>> $offers
     * @return array<string,mixed>|null
     */
    private function offerFor(array $offers, int $itemId, int $preferVendorId): ?array
    {
        $fallback = null;
        foreach ($offers as $offer) {
            if ($offer['stock_item_id'] !== $itemId) {
                continue;
            }
            if ($preferVendorId > 0 && $offer['client_id'] === $preferVendorId) {
                return $offer;
            }
            $fallback ??= $offer;
        }

        return $fallback;
    }

    /**
     * @param list<int> $itemIds
     * @return array<int,array<string,mixed>>
     */
    private function itemsById(int $supplierId, array $itemIds): array
    {
        $ids = array_values(array_unique($itemIds));
        if ($ids === []) {
            return [];
        }
        $place = implode(',', array_fill(0, count($ids), '?'));
        $stmt  = $this->db->pdo()->prepare(
            "SELECT id, sku, name, unit, vat_rate_id FROM stock_items
              WHERE supplier_id = ? AND id IN ($place)"
        );
        $stmt->execute([$supplierId, ...$ids]);

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $out[(int) $r['id']] = [
                'sku'         => (string) $r['sku'],
                'name'        => (string) $r['name'],
                'unit'        => (string) $r['unit'],
                'vat_rate_id' => $r['vat_rate_id'] !== null ? (int) $r['vat_rate_id'] : null,
            ];
        }

        return $out;
    }

    private function defaultCurrencyId(int $supplierId): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id FROM currencies WHERE supplier_id = ?
              ORDER BY is_default DESC, (code = ?) DESC, id ASC LIMIT 1'
        );
        $stmt->execute([$supplierId, 'CZK']);

        return (int) ($stmt->fetchColumn() ?: 0);
    }

    private static function nullableString(mixed $v): ?string
    {
        $s = trim((string) ($v ?? ''));

        return $s === '' ? null : $s;
    }

    private function mapStockError(Response $response, \Throwable $e): Response
    {
        if ($e instanceof StockException) {
            return Json::error($response, 'stock.error.' . $e->errorCode, $e->getMessage(), $e->httpStatus, ['items' => $e->details]);
        }
        throw $e;
    }
}
