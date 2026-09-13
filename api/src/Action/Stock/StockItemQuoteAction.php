<?php

declare(strict_types=1);

namespace MyInvoice\Action\Stock;

use MyInvoice\Action\Accounting\AccountingActionSupport;
use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Stock\StockException;
use MyInvoice\Service\Stock\StockItemQuoteService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * POST /api/stock/items/quote — nacenění skladových řádků dokladu v jednotce
 * řádku (balení) pro odběratele, měnu a datum (issue #17). Jen čte; oprávnění
 * `stock` READ jako našeptávač karet.
 */
final class StockItemQuoteAction
{
    use AccountingActionSupport;
    use GuardsStockEnabled;

    public function __construct(
        private readonly Connection $db,
        private readonly StockItemQuoteService $quotes,
    ) {}

    public function quote(Request $request, Response $response): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->guardStockEnabled($this->db, $supplierId, $response, $err)) return $err;
        try {
            return Json::ok($response, $this->quotes->quote($supplierId, (array) ($request->getParsedBody() ?? [])));
        } catch (StockException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus, $e->details);
        }
    }
}
