<?php

declare(strict_types=1);

namespace MyInvoice\Action\Eshop;

use MyInvoice\Action\Accounting\AccountingActionSupport;
use MyInvoice\Action\Stock\GuardsStockEnabled;
use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\StockItemRepository;
use MyInvoice\Repository\StockItemVendorRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Eshop\Pricing\PriceRecomputeDispatcher;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Dodavatelé zboží — M:N na clients (is_vendor) (Epic ESHOP).
 *
 *   GET /api/eshop/products/{id}/vendors
 *   PUT /api/eshop/products/{id}/vendors    — replace (guard is_vendor + tenant)
 *
 * Skladovost/lhůta u dodavatele slouží neskladovému zboží (is_stocked=0, E5).
 * Po změně přepočítá cenu (pricing_base=manual bere vendor purchase_price).
 */
final class ProductVendorAction
{
    use AccountingActionSupport;
    use GuardsStockEnabled;

    public function __construct(
        private readonly Connection $db,
        private readonly StockItemRepository $items,
        private readonly StockItemVendorRepository $vendors,
        private readonly PriceRecomputeDispatcher $dispatcher,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function get(Request $request, Response $response, array $args): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->guardStockEnabled($this->db, $supplierId, $response, $err)) {
            return $err;
        }
        $itemId = (int) $args['id'];
        if ($this->items->find($supplierId, $itemId) === null) {
            return Json::error($response, 'not_found', 'Karta zboží nenalezena.', 404);
        }
        return Json::ok($response, $this->vendors->listForItem($supplierId, $itemId));
    }

    public function put(Request $request, Response $response, array $args): Response
    {
        if (!$this->requireWrite($request, $response, $err)) {
            return $err;
        }
        $supplierId = $this->currentSupplierId($request);
        if (!$this->guardStockEnabled($this->db, $supplierId, $response, $err)) {
            return $err;
        }
        $itemId = (int) $args['id'];
        if ($this->items->find($supplierId, $itemId) === null) {
            return Json::error($response, 'not_found', 'Karta zboží nenalezena.', 404);
        }
        $body = (array) ($request->getParsedBody() ?? []);
        $rows = is_array($body['vendors'] ?? null) ? $body['vendors'] : [];

        // Validace + guard is_vendor/tenant.
        $prepared = [];
        $clientIds = [];
        $preferredCount = 0;
        foreach ($rows as $r) {
            if (!is_array($r)) {
                continue;
            }
            $clientId = (int) ($r['client_id'] ?? 0);
            if ($clientId <= 0) {
                continue;
            }
            $price = $this->numOrNull($r['purchase_price'] ?? null);
            $currency = strtoupper(trim((string) ($r['currency_code'] ?? 'CZK')));
            if (!preg_match('/^[A-Z]{3}$/', $currency)) {
                $currency = 'CZK';
            }
            $preferred = (bool) ($r['is_preferred'] ?? false);
            if ($preferred) {
                $preferredCount++;
            }
            $clientIds[] = $clientId;
            $prepared[] = [
                'client_id'      => $clientId,
                'vendor_sku'     => $this->strOrNull($r['vendor_sku'] ?? null),
                'purchase_price' => $price,
                'currency_code'  => $currency,
                'delivery_days'  => isset($r['delivery_days']) && $r['delivery_days'] !== null && $r['delivery_days'] !== '' ? (int) $r['delivery_days'] : null,
                'stock_qty'      => $this->numOrNull($r['stock_qty'] ?? null),
                'is_preferred'   => $preferred,
                'note'           => $this->strOrNull($r['note'] ?? null),
            ];
        }
        if ($preferredCount > 1) {
            return Json::error($response, 'multiple_preferred_vendors', 'Zboží může mít nejvýše jednoho preferovaného dodavatele.', 422);
        }

        // Guard: každý client_id patří tenantovi a je is_vendor=1.
        $owned = array_flip($this->vendors->filterOwnedVendors($supplierId, $clientIds));
        foreach ($clientIds as $cid) {
            if (!isset($owned[$cid])) {
                return Json::error($response, 'vendor_invalid', 'Zvolený dodavatel neexistuje nebo není označen jako dodavatel.', 422, ['client_id' => $cid]);
            }
        }

        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $this->vendors->deleteForItem($supplierId, $itemId);
            foreach ($prepared as $v) {
                $this->vendors->add($supplierId, $itemId, $v);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        // Přepočet ceny (pricing_base=manual závisí na preferovaném dodavateli).
        $this->dispatcher->recomputeItem($supplierId, $itemId);
        $this->log($request, 'eshop.vendors_updated', $itemId, ['count' => count($prepared)]);
        return Json::ok($response, $this->vendors->listForItem($supplierId, $itemId));
    }

    private function numOrNull(mixed $v): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }
        $s = str_replace(',', '.', (string) $v);
        return is_numeric($s) ? $s : null;
    }

    private function strOrNull(mixed $v): ?string
    {
        if ($v === null) {
            return null;
        }
        $s = trim((string) $v);
        return $s === '' ? null : $s;
    }

    private function log(Request $request, string $action, int $id, array $payload): void
    {
        $this->logger->log(
            $action,
            $this->userId($request),
            'stock_item',
            $id,
            $payload,
            $this->ipMatcher->clientIpFromRequest($request->getServerParams()),
            $request->getHeaderLine('User-Agent'),
            $this->currentSupplierId($request),
        );
    }
}
