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
use MyInvoice\Service\Stock\StockItemPackagingService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Balení skladové karty (issue #17).
 *
 *   GET /api/stock/items/{id}/packaging — základní jednotka, výchozí prodejní jednotka, balení
 *   PUT /api/stock/items/{id}/packaging — uloží balení (upsert, EAN balení, pojistka vystavených dokladů)
 */
final class StockItemPackagingAction
{
    use AccountingActionSupport;
    use GuardsStockEnabled;

    public function __construct(
        private readonly Connection $db,
        private readonly StockItemPackagingService $packaging,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function get(Request $request, Response $response, array $args): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->guardStockEnabled($this->db, $supplierId, $response, $err)) return $err;
        try {
            return Json::ok($response, $this->packaging->get($supplierId, (int) $args['id']));
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
            $saved = $this->packaging->save($supplierId, $itemId, (array) ($request->getParsedBody() ?? []));
        } catch (StockException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus, $e->details);
        }
        $this->logger->log(
            'stock.item_packaging_updated',
            $this->userId($request),
            'stock_item',
            $itemId,
            ['units' => array_column($saved['units'], 'unit_code'), 'default_sale_unit' => $saved['default_sale_unit']],
            $this->ipMatcher->clientIpFromRequest($request->getServerParams()),
            $request->getHeaderLine('User-Agent'),
            $supplierId,
        );
        return Json::ok($response, $saved);
    }
}
