<?php

declare(strict_types=1);

namespace MyInvoice\Action\Stock;

use MyInvoice\Action\Accounting\AccountingActionSupport;
use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Stock\PurchaseOrderReceiptService;
use MyInvoice\Service\Stock\StockException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Příjem na sklad z objednávky (Epic SKLAD „na cestě", §5.3).
 *
 *   GET  /api/stock/purchase-orders/{id}/receipt   — návrh řádků (zbývá přijmout)
 *   POST /api/stock/purchase-orders/{id}/receipt   — DRAFT příjemka
 *   GET  /api/stock/purchase-orders/{id}/receipts  — příjemky k objednávce
 *
 * Zaúčtování se tady NEDĚLÁ — uživatel doklad zaúčtuje existujícím
 * `POST /api/stock/documents/{id}/post`, který zároveň (v téže transakci)
 * přepočte stav objednávky. Paralelní post endpoint by tuhle vazbu rozdvojil.
 */
final class PurchaseOrderReceiptAction
{
    use AccountingActionSupport;
    use GuardsStockEnabled;

    public function __construct(
        private readonly Connection $db,
        private readonly PurchaseOrderReceiptService $service,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function propose(Request $request, Response $response, array $args): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->guardStockEnabled($this->db, $supplierId, $response, $err)) {
            return $err;
        }
        try {
            return Json::ok($response, $this->service->propose($supplierId, (int) $args['id']));
        } catch (\Throwable $e) {
            return $this->mapStockError($response, $e);
        }
    }

    public function create(Request $request, Response $response, array $args): Response
    {
        if (!$this->requirePermission($request, $response, 'stock.orders.write', AccessLevel::WRITE, $err)) {
            return $err;
        }
        $supplierId = $this->currentSupplierId($request);
        if (!$this->guardStockEnabled($this->db, $supplierId, $response, $err)) {
            return $err;
        }
        $id   = (int) $args['id'];
        $body = (array) ($request->getParsedBody() ?? []);
        try {
            $doc = $this->service->createReceipt($supplierId, $id, $body, $this->userId($request));
            $this->log($request, 'stock.order_receipt_created', $id, [
                'stock_document_id' => $doc['id'] ?? null,
                'cost_is_estimate'  => $doc['cost_is_estimate'] ?? false,
            ]);

            return Json::ok($response, $doc, 201);
        } catch (\Throwable $e) {
            return $this->mapStockError($response, $e);
        }
    }

    public function list(Request $request, Response $response, array $args): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->guardStockEnabled($this->db, $supplierId, $response, $err)) {
            return $err;
        }

        return Json::ok($response, $this->service->receiptsForOrder($supplierId, (int) $args['id']));
    }

    private function log(Request $request, string $action, int $id, array $payload): void
    {
        $this->logger->log(
            $action,
            $this->userId($request),
            'purchase_order',
            $id,
            $payload,
            $this->ipMatcher->clientIpFromRequest($request->getServerParams()),
            $request->getHeaderLine('User-Agent'),
            $this->currentSupplierId($request),
        );
    }

    private function mapStockError(Response $response, \Throwable $e): Response
    {
        if ($e instanceof StockException) {
            return Json::error($response, 'stock.error.' . $e->errorCode, $e->getMessage(), $e->httpStatus, ['items' => $e->details]);
        }
        throw $e;
    }
}
