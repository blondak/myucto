<?php

declare(strict_types=1);

namespace MyInvoice\Action\Accounting\Cash;

use MyInvoice\Action\Accounting\AccountingActionSupport;
use MyInvoice\Http\Json;
use MyInvoice\Service\Accounting\Cash\CashException;
use MyInvoice\Service\Accounting\Cash\CashRegisterService;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Číselník pokladen — REST API (mini-epic POKLADNA #14).
 *
 *   GET    /api/accounting/cash-registers            — seznam (?include_inactive=1)
 *   POST   /api/accounting/cash-registers            — nová pokladna (účetní|admin)
 *   GET    /api/accounting/cash-registers/{id}       — detail (?date=YYYY-MM-DD)
 *   PUT    /api/accounting/cash-registers/{id}       — úprava (účetní|admin)
 *   DELETE /api/accounting/cash-registers/{id}       — smazání (jen bez dokladů)
 */
final class CashRegisterAction
{
    use AccountingActionSupport;

    public function __construct(
        private readonly CashRegisterService $service,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function list(Request $request, Response $response): Response
    {
        $supplierId = $this->currentSupplierId($request);
        $q = $request->getQueryParams();
        $includeInactive = filter_var($q['include_inactive'] ?? false, FILTER_VALIDATE_BOOLEAN);
        return Json::ok($response, $this->service->list($supplierId, $includeInactive));
    }

    public function get(Request $request, Response $response, array $args): Response
    {
        $supplierId = $this->currentSupplierId($request);
        $q = $request->getQueryParams();
        $date = isset($q['date']) && $q['date'] !== '' ? (string) $q['date'] : null;
        try {
            return Json::ok($response, $this->service->get($supplierId, (int) $args['id'], $date));
        } catch (\Throwable $e) {
            return $this->mapCashError($response, $e);
        }
    }

    public function create(Request $request, Response $response): Response
    {
        if (!$this->requireCashWrite($request, $response, $err)) {
            return $err;
        }
        $supplierId = $this->currentSupplierId($request);
        $body = (array) ($request->getParsedBody() ?? []);
        try {
            $id = $this->service->create($supplierId, $body);
            $detail = $this->service->get($supplierId, $id);
            $this->log($request, 'cash.register_created', $id, ['name' => $detail['name'] ?? null, 'account_code' => $detail['account_code'] ?? null]);
            return Json::ok($response, $detail, 201);
        } catch (\Throwable $e) {
            return $this->mapCashError($response, $e);
        }
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        if (!$this->requireCashWrite($request, $response, $err)) {
            return $err;
        }
        $supplierId = $this->currentSupplierId($request);
        $id = (int) $args['id'];
        $body = (array) ($request->getParsedBody() ?? []);
        try {
            $this->service->update($supplierId, $id, $body);
            $detail = $this->service->get($supplierId, $id);
            $this->log($request, 'cash.register_updated', $id, ['name' => $detail['name'] ?? null]);
            return Json::ok($response, $detail);
        } catch (\Throwable $e) {
            return $this->mapCashError($response, $e);
        }
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        if (!$this->requireCashWrite($request, $response, $err)) {
            return $err;
        }
        $supplierId = $this->currentSupplierId($request);
        $id = (int) $args['id'];
        try {
            $this->service->delete($supplierId, $id);
            $this->log($request, 'cash.register_deleted', $id, []);
            return Json::ok($response, ['deleted' => true]);
        } catch (\Throwable $e) {
            return $this->mapCashError($response, $e);
        }
    }

    private function log(Request $request, string $action, int $id, array $payload): void
    {
        $this->logger->log(
            $action,
            $this->userId($request),
            'cash_register',
            $id,
            $payload,
            $this->ipMatcher->clientIpFromRequest($request->getServerParams()),
            $request->getHeaderLine('User-Agent'),
            $this->currentSupplierId($request),
        );
    }

}
