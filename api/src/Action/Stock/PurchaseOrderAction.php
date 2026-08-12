<?php

declare(strict_types=1);

namespace MyInvoice\Action\Stock;

use MyInvoice\Action\Accounting\AccountingActionSupport;
use MyInvoice\Http\Json;
use MyInvoice\Http\TenantReferenceGuard;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\PurchaseOrderRepository;
use MyInvoice\Repository\WarehouseRepository;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Pdf\PurchaseOrderPdfRenderer;
use MyInvoice\Service\Stock\PurchaseOrderService;
use MyInvoice\Service\Stock\StockException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Objednávky vydané dodavateli — REST API (Epic SKLAD „na cestě", fáze 1).
 *
 *   GET    /api/stock/purchase-orders               — stránkovaný seznam (filtry)
 *   POST   /api/stock/purchase-orders               — nový draft
 *   GET    /api/stock/purchase-orders/{id}          — detail vč. řádků a plnění
 *   PUT    /api/stock/purchase-orders/{id}          — úprava draftu
 *   DELETE /api/stock/purchase-orders/{id}          — smazání draftu
 *   POST   /api/stock/purchase-orders/{id}/send     — odeslání (přidělí číslo OBJ)
 *   POST   /api/stock/purchase-orders/{id}/confirm  — potvrzení dodavatelem
 *   POST   /api/stock/purchase-orders/{id}/cancel   — storno (jen bez příjmu)
 *   POST   /api/stock/purchase-orders/{id}/close    — uzavření nedodaného zbytku
 *   POST   /api/stock/purchase-orders/{id}/reopen   — znovuotevření
 *   GET    /api/stock/purchase-orders/{id}/pdf      — tisk objednávky
 *
 * Zápis vyžaduje `stock.orders.write` (RoutePermissionMap + defense-in-depth
 * kontrola tady, aby Action nezávisela jen na routovacích pravidlech).
 */
final class PurchaseOrderAction
{
    use AccountingActionSupport;
    use GuardsStockEnabled;

    private const MAX_PER_PAGE = 500;

    public function __construct(
        private readonly Connection $db,
        private readonly PurchaseOrderService $service,
        private readonly PurchaseOrderRepository $orders,
        private readonly WarehouseRepository $warehouses,
        private readonly TenantReferenceGuard $tenantRefs,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
        private readonly PurchaseOrderPdfRenderer $pdfRenderer,
    ) {}

    public function list(Request $request, Response $response): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->guardStockEnabled($this->db, $supplierId, $response, $err)) {
            return $err;
        }
        $q       = $request->getQueryParams();
        $filters = [];
        foreach (['q', 'from', 'to', 'expected_to'] as $key) {
            if (isset($q[$key]) && $q[$key] !== '') {
                $filters[$key] = (string) $q[$key];
            }
        }
        if (isset($q['state']) && $q['state'] !== '') {
            $filters['state'] = array_values(array_filter(
                array_map('trim', explode(',', (string) $q['state'])),
                static fn (string $v): bool => $v !== '',
            ));
        }
        if (!empty($q['open'])) {
            // „Otevřené" = to, co ještě může dorazit; přesně stavy, které se
            // počítají do „na cestě" plus draft (uživatel je chce vidět taky).
            $filters['state'] = ['draft', 'sent', 'confirmed', 'partially_received'];
        }
        foreach (['vendor_id', 'warehouse_id', 'stock_item_id'] as $key) {
            if (!empty($q[$key])) {
                $filters[$key] = (int) $q[$key];
            }
        }

        $limit  = max(1, min(self::MAX_PER_PAGE, (int) ($q['limit'] ?? 100)));
        $offset = max(0, (int) ($q['offset'] ?? 0));

        [$rows, $total] = $this->orders->listPaged($supplierId, $filters, $limit, $offset);

        // Plnění per objednávka dvěma agregačními dotazy (ne N+1) — seznam musí
        // umět sloupce objednáno / přijato / zbývá.
        $orderIds = array_map(static fn (array $r): int => (int) $r['id'], $rows);
        $summary  = $this->orders->quantitySummary($supplierId, $orderIds);
        $byOrder  = $this->orders->receivedTotalsByOrder($supplierId, $orderIds);
        foreach ($rows as &$row) {
            $oid      = (int) $row['id'];
            $ordered  = (float) ($summary[$oid]['effective'] ?? 0);
            $receivedQty = max(0.0, (float) ($byOrder[$oid] ?? 0));
            $row['qty_ordered_total']   = number_format(max(0.0, $ordered), 3, '.', '');
            $row['qty_received_total']  = number_format($receivedQty, 3, '.', '');
            $row['qty_remaining_total'] = number_format(max(0.0, $ordered - $receivedQty), 3, '.', '');
        }
        unset($row);

        return Json::ok($response, ['items' => $rows, 'total' => $total, 'limit' => $limit, 'offset' => $offset]);
    }

    public function get(Request $request, Response $response, array $args): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->guardStockEnabled($this->db, $supplierId, $response, $err)) {
            return $err;
        }
        $order = $this->service->detail($supplierId, (int) $args['id']);
        if ($order === null) {
            return Json::error($response, 'not_found', 'Objednávka nenalezena.', 404);
        }

        return Json::ok($response, $order);
    }

    public function create(Request $request, Response $response): Response
    {
        if (!$this->requireOrdersWrite($request, $response, $err)) {
            return $err;
        }
        $supplierId = $this->currentSupplierId($request);
        if (!$this->guardStockEnabled($this->db, $supplierId, $response, $err)) {
            return $err;
        }
        $body = (array) ($request->getParsedBody() ?? []);
        if (($bad = $this->guardReferences($supplierId, $body)) !== null) {
            return Json::error($response, 'invalid_reference', TenantReferenceGuard::message($bad), 422);
        }

        try {
            $result = $this->service->create($supplierId, $body, $this->userId($request));
            $this->log($request, 'stock.order_created', (int) $result['id'], ['vendor_id' => $result['vendor_id'] ?? null]);

            return Json::ok($response, $result, 201);
        } catch (\Throwable $e) {
            return $this->mapStockError($response, $e);
        }
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        if (!$this->requireOrdersWrite($request, $response, $err)) {
            return $err;
        }
        $supplierId = $this->currentSupplierId($request);
        if (!$this->guardStockEnabled($this->db, $supplierId, $response, $err)) {
            return $err;
        }
        $body = (array) ($request->getParsedBody() ?? []);
        if (($bad = $this->guardReferences($supplierId, $body)) !== null) {
            return Json::error($response, 'invalid_reference', TenantReferenceGuard::message($bad), 422);
        }

        $id = (int) $args['id'];
        try {
            $result = $this->service->update($supplierId, $id, $body, $this->userId($request));
            $this->log($request, 'stock.order_updated', $id, []);

            return Json::ok($response, $result);
        } catch (\Throwable $e) {
            return $this->mapStockError($response, $e);
        }
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        if (!$this->requireOrdersWrite($request, $response, $err)) {
            return $err;
        }
        $supplierId = $this->currentSupplierId($request);
        if (!$this->guardStockEnabled($this->db, $supplierId, $response, $err)) {
            return $err;
        }
        $id = (int) $args['id'];
        try {
            $this->service->delete($supplierId, $id);
            $this->log($request, 'stock.order_deleted', $id, []);

            return Json::ok($response, ['deleted' => true]);
        } catch (\Throwable $e) {
            return $this->mapStockError($response, $e);
        }
    }

    public function send(Request $request, Response $response, array $args): Response
    {
        return $this->action($request, $response, $args, 'stock.order_sent', function (int $supplierId, int $id, array $body, ?int $userId): array {
            return $this->service->send($supplierId, $id, $userId);
        });
    }

    public function confirm(Request $request, Response $response, array $args): Response
    {
        return $this->action($request, $response, $args, 'stock.order_confirmed', function (int $supplierId, int $id, array $body, ?int $userId): array {
            return $this->service->confirm($supplierId, $id, $body, $userId);
        });
    }

    public function cancel(Request $request, Response $response, array $args): Response
    {
        return $this->action($request, $response, $args, 'stock.order_cancelled', function (int $supplierId, int $id, array $body, ?int $userId): array {
            return $this->service->cancel($supplierId, $id, (string) ($body['reason'] ?? ''), $userId);
        });
    }

    public function close(Request $request, Response $response, array $args): Response
    {
        return $this->action($request, $response, $args, 'stock.order_closed', function (int $supplierId, int $id, array $body, ?int $userId): array {
            return $this->service->close($supplierId, $id, (string) ($body['reason'] ?? ''), $userId);
        });
    }

    public function reopen(Request $request, Response $response, array $args): Response
    {
        return $this->action($request, $response, $args, 'stock.order_reopened', function (int $supplierId, int $id, array $body, ?int $userId): array {
            return $this->service->reopen($supplierId, $id, $userId);
        });
    }

    /** PDF objednávky — po odeslání je to doklad, který jde poslat dodavateli. */
    public function pdf(Request $request, Response $response, array $args): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->guardStockEnabled($this->db, $supplierId, $response, $err)) {
            return $err;
        }
        $id    = (int) $args['id'];
        $order = $this->service->detail($supplierId, $id);
        if ($order === null) {
            return Json::error($response, 'not_found', 'Objednávka nenalezena.', 404);
        }

        $stmt = $this->db->pdo()->prepare(
            'SELECT company_name, street, city, zip, ic, dic FROM supplier WHERE id = ?'
        );
        $stmt->execute([$supplierId]);
        $supplier = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];

        $vendorStmt = $this->db->pdo()->prepare(
            'SELECT company_name, street, city, zip, ic, dic FROM clients WHERE id = ? AND supplier_id = ?'
        );
        $vendorStmt->execute([(int) $order['vendor_id'], $supplierId]);
        $vendor = $vendorStmt->fetch(\PDO::FETCH_ASSOC) ?: [];

        $currencyStmt = $this->db->pdo()->prepare(
            'SELECT code FROM currencies WHERE id = ? AND supplier_id = ?'
        );
        $currencyStmt->execute([(int) $order['currency_id'], $supplierId]);
        $currencyCode = (string) ($currencyStmt->fetchColumn() ?: 'CZK');

        try {
            $bytes = $this->pdfRenderer->render([
                'order'         => $order,
                'supplier'      => $supplier,
                'vendor'        => $vendor,
                'warehouse'     => $this->warehouses->find($supplierId, (int) $order['warehouse_id']),
                'currency_code' => $currencyCode,
            ]);
        } catch (\Throwable $e) {
            return $this->mapStockError($response, $e);
        }

        $filename = 'OBJ-' . (string) ($order['order_number'] ?? $id) . '.pdf';
        $filename = str_replace(['/', '\\', ' '], '-', $filename);

        $response->getBody()->write($bytes);

        return $response
            ->withHeader('Content-Type', 'application/pdf')
            ->withHeader('Content-Disposition', 'inline; filename="' . $filename . '"')
            ->withHeader('Content-Length', (string) strlen($bytes))
            ->withHeader('Cache-Control', 'private, no-store');
    }

    // ── interní ──────────────────────────────────────────────────────────────

    /**
     * Sdílený obal stavových přechodů — RBAC, stock gate, log, mapování chyb.
     *
     * @param callable(int,int,array<string,mixed>,?int):array<string,mixed> $fn
     */
    private function action(Request $request, Response $response, array $args, string $logAction, callable $fn): Response
    {
        if (!$this->requireOrdersWrite($request, $response, $err)) {
            return $err;
        }
        $supplierId = $this->currentSupplierId($request);
        if (!$this->guardStockEnabled($this->db, $supplierId, $response, $err)) {
            return $err;
        }
        $id   = (int) $args['id'];
        $body = (array) ($request->getParsedBody() ?? []);
        try {
            $result = $fn($supplierId, $id, $body, $this->userId($request));
            $this->log($request, $logAction, $id, [
                'state'        => $result['state'] ?? null,
                'order_number' => $result['order_number'] ?? null,
            ]);

            return Json::ok($response, $result);
        } catch (\Throwable $e) {
            return $this->mapStockError($response, $e);
        }
    }

    private function requireOrdersWrite(Request $request, Response $response, ?Response &$err): bool
    {
        return $this->requirePermission($request, $response, 'stock.orders.write', AccessLevel::WRITE, $err);
    }

    /**
     * Cizí klíče z těla requestu vázané na tenanta (CWE-639 / BOLA).
     * `warehouse_id`, `stock_item_id` a `vat_rate_id` guard v SCOPES nemá —
     * ověřuje je {@see PurchaseOrderService::validateBody()} vlastním dotazem
     * se supplier predikátem (sklad navíc na aktivitu).
     *
     * @param array<string,mixed> $body
     * @return list<string>|null null = v pořádku
     */
    private function guardReferences(int $supplierId, array $body): ?array
    {
        $bad = $this->tenantRefs->violations($supplierId, $body, ['vendor_id', 'currency_id']);

        return $bad === [] ? null : $bad;
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
