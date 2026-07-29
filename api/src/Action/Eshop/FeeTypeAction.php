<?php

declare(strict_types=1);

namespace MyInvoice\Action\Eshop;

use MyInvoice\Action\Accounting\AccountingActionSupport;
use MyInvoice\Action\Stock\GuardsStockEnabled;
use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\StockFeeTypeRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Typy poplatků (autorský/recyklační/WEEE) — číselník (Epic ESHOP).
 *
 *   GET/POST /api/eshop/fee-types ; GET/PUT/DELETE /api/eshop/fee-types/{id}
 */
final class FeeTypeAction
{
    use AccountingActionSupport;
    use GuardsStockEnabled;

    public function __construct(
        private readonly Connection $db,
        private readonly StockFeeTypeRepository $feeTypes,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function list(Request $request, Response $response): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->guardStockEnabled($this->db, $supplierId, $response, $err)) {
            return $err;
        }
        $q = $request->getQueryParams();
        return Json::ok($response, $this->feeTypes->listForSupplier($supplierId, !empty($q['active'])));
    }

    public function get(Request $request, Response $response, array $args): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->guardStockEnabled($this->db, $supplierId, $response, $err)) {
            return $err;
        }
        $row = $this->feeTypes->find($supplierId, (int) $args['id']);
        if ($row === null) {
            return Json::error($response, 'not_found', 'Poplatek nenalezen.', 404);
        }
        return Json::ok($response, $row);
    }

    public function create(Request $request, Response $response): Response
    {
        if (!$this->requireWrite($request, $response, $err)) {
            return $err;
        }
        $supplierId = $this->currentSupplierId($request);
        if (!$this->guardStockEnabled($this->db, $supplierId, $response, $err)) {
            return $err;
        }
        $body = (array) ($request->getParsedBody() ?? []);
        [$data, $verr] = $this->validate($response, $body);
        if ($verr !== null) {
            return $verr;
        }
        if ($this->feeTypes->findByCode($supplierId, $data['code']) !== null) {
            return Json::error($response, 'fee_type_code_taken', 'Poplatek s tímto kódem už existuje.', 409);
        }
        $id = $this->feeTypes->insert($supplierId, $data);
        $this->log($request, 'eshop.fee_type_created', $id, ['code' => $data['code']]);
        return Json::ok($response, $this->feeTypes->find($supplierId, $id) ?? [], 201);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        if (!$this->requireWrite($request, $response, $err)) {
            return $err;
        }
        $supplierId = $this->currentSupplierId($request);
        if (!$this->guardStockEnabled($this->db, $supplierId, $response, $err)) {
            return $err;
        }
        $id = (int) $args['id'];
        $existing = $this->feeTypes->find($supplierId, $id);
        if ($existing === null) {
            return Json::error($response, 'not_found', 'Poplatek nenalezen.', 404);
        }
        $body = (array) ($request->getParsedBody() ?? []);
        [$data, $verr] = $this->validate($response, $body, $existing);
        if ($verr !== null) {
            return $verr;
        }
        $byCode = $this->feeTypes->findByCode($supplierId, $data['code']);
        if ($byCode !== null && (int) $byCode['id'] !== $id) {
            return Json::error($response, 'fee_type_code_taken', 'Poplatek s tímto kódem už existuje.', 409);
        }
        $this->feeTypes->update($supplierId, $id, $data);
        $this->log($request, 'eshop.fee_type_updated', $id, ['code' => $data['code']]);
        return Json::ok($response, $this->feeTypes->find($supplierId, $id) ?? []);
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        if (!$this->requireWrite($request, $response, $err)) {
            return $err;
        }
        $supplierId = $this->currentSupplierId($request);
        if (!$this->guardStockEnabled($this->db, $supplierId, $response, $err)) {
            return $err;
        }
        $id = (int) $args['id'];
        if ($this->feeTypes->find($supplierId, $id) === null) {
            return Json::error($response, 'not_found', 'Poplatek nenalezen.', 404);
        }
        if ($this->feeTypes->isReferenced($supplierId, $id)) {
            return Json::error($response, 'fee_type_in_use', 'Poplatek nelze smazat — je přiřazen ke zboží. Archivujte jej místo mazání.', 409, ['suggestion' => 'archive']);
        }
        $this->feeTypes->delete($supplierId, $id);
        $this->log($request, 'eshop.fee_type_deleted', $id, []);
        return Json::ok($response, ['deleted' => true]);
    }

    /**
     * @param array<string,mixed> $body
     * @param array<string,mixed>|null $existing
     * @return array{0:array<string,mixed>, 1:?Response}
     */
    private function validate(Response $response, array $body, ?array $existing = null): array
    {
        $code = trim((string) ($body['code'] ?? $existing['code'] ?? ''));
        $name = trim((string) ($body['name'] ?? $existing['name'] ?? ''));
        if ($code === '' || mb_strlen($code) > 30) {
            return [[], Json::error($response, 'validation_failed', 'Kód poplatku je povinný (max 30 znaků).', 400)];
        }
        if ($name === '' || mb_strlen($name) > 120) {
            return [[], Json::error($response, 'validation_failed', 'Název poplatku je povinný (max 120 znaků).', 400)];
        }
        $vatRateId = array_key_exists('vat_rate_id', $body)
            ? ($body['vat_rate_id'] !== null && $body['vat_rate_id'] !== '' ? (int) $body['vat_rate_id'] : null)
            : ($existing['vat_rate_id'] ?? null);
        $data = [
            'code'        => $code,
            'name'        => $name,
            'vat_rate_id' => $vatRateId,
            'archived'    => array_key_exists('archived', $body) ? (bool) $body['archived'] : (bool) ($existing['archived'] ?? false),
        ];
        return [$data, null];
    }

    private function log(Request $request, string $action, int $id, array $payload): void
    {
        $this->logger->log(
            $action,
            $this->userId($request),
            'stock_fee_type',
            $id,
            $payload,
            $this->ipMatcher->clientIpFromRequest($request->getServerParams()),
            $request->getHeaderLine('User-Agent'),
            $this->currentSupplierId($request),
        );
    }
}
