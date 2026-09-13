<?php

declare(strict_types=1);

namespace MyInvoice\Action\Stock;

use MyInvoice\Action\Accounting\AccountingActionSupport;
use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Stock\StockException;
use MyInvoice\Service\Stock\StockItemCustomerPriceService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Individuální ceny zákazníků na skladové kartě (issue #17).
 *
 *   GET /api/stock/items/{id}/customer-prices — sada karty + dnešní výsledná cena za základní jednotku
 *   PUT /api/stock/items/{id}/customer-prices — nahradí celou sadu karty
 */
final class StockItemCustomerPriceAction
{
    use AccountingActionSupport;
    use GuardsStockEnabled;

    public function __construct(
        private readonly Connection $db,
        private readonly StockItemCustomerPriceService $prices,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function get(Request $request, Response $response, array $args): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->guardStockEnabled($this->db, $supplierId, $response, $err)) return $err;
        try {
            return Json::ok($response, $this->prices->list($supplierId, (int) $args['id']));
        } catch (StockException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus, $e->details);
        }
    }

    public function put(Request $request, Response $response, array $args): Response
    {
        if (!$this->requirePermission($request, $response, 'stock.items.write', AccessLevel::WRITE, $err)) return $err;
        $supplierId = $this->currentSupplierId($request);
        if (!$this->guardStockEnabled($this->db, $supplierId, $response, $err)) return $err;
        $itemId = (int) $args['id'];
        try {
            $saved = $this->prices->save($supplierId, $itemId, $request->getParsedBody());
        } catch (StockException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus, $e->details);
        }
        $this->logger->log(
            'stock.item_customer_prices_updated',
            $this->userId($request),
            'stock_item',
            $itemId,
            ['count' => count($saved)],
            $this->ipMatcher->clientIpFromRequest($request->getServerParams()),
            $request->getHeaderLine('User-Agent'),
            $supplierId,
        );
        return Json::ok($response, $saved);
    }
}
