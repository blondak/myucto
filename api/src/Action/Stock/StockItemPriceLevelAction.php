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
use MyInvoice\Service\Stock\StockPriceLevelService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Cenové hladiny na skladové kartě (migrace 1833).
 *
 *   GET /api/stock/items/{id}/price-levels — cena karty v každé aktivní hladině × měně
 *   PUT /api/stock/items/{id}/price-levels — výjimky produktu (jen pravidla typu product)
 */
final class StockItemPriceLevelAction
{
    use AccountingActionSupport;
    use GuardsStockEnabled;

    public function __construct(
        private readonly Connection $db,
        private readonly StockPriceLevelService $levels,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function get(Request $request, Response $response, array $args): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->guardStockEnabled($this->db, $supplierId, $response, $err)) return $err;
        try {
            return Json::ok($response, $this->levels->itemOverview($supplierId, (int) $args['id']));
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
        $body = $request->getParsedBody();
        try {
            $saved = $this->levels->saveItem($supplierId, $itemId, $body);
        } catch (StockException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus, $e->details);
        }
        $this->logger->log(
            'stock.item_price_levels_updated',
            $this->userId($request),
            'stock_item',
            $itemId,
            ['count' => is_array($body) ? count($body) : 0],
            $this->ipMatcher->clientIpFromRequest($request->getServerParams()),
            $request->getHeaderLine('User-Agent'),
            $supplierId,
        );
        return Json::ok($response, $saved);
    }
}
