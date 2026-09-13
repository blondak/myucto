<?php

declare(strict_types=1);

namespace MyInvoice\Action\Eshop;

use MyInvoice\Action\Accounting\AccountingActionSupport;
use MyInvoice\Action\Stock\GuardsStockEnabled;
use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\StockPackagingUnitRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Číselník balení — kódy nadřazených jednotek firmy (issue #17).
 *
 *   GET/POST /api/eshop/packaging-units ; GET/PUT/DELETE /api/eshop/packaging-units/{id}
 *
 * Kód, který už používá nějaká karta (`usage_count` > 0), nejde přejmenovat ani
 * smazat — karta by ztratila vazbu na číselník. Místo smazání ho lze deaktivovat:
 * nové karty ho pak nenabídnou, stávající si ho drží.
 */
final class PackagingUnitAction
{
    use AccountingActionSupport;
    use GuardsStockEnabled;

    public function __construct(
        private readonly Connection $db,
        private readonly StockPackagingUnitRepository $units,
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
        return Json::ok($response, $this->units->listForSupplier($supplierId, !empty($q['active'])));
    }

    public function get(Request $request, Response $response, array $args): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->guardStockEnabled($this->db, $supplierId, $response, $err)) {
            return $err;
        }
        $row = $this->units->find($supplierId, (int) $args['id']);
        if ($row === null) {
            return Json::error($response, 'not_found', 'Balení nenalezeno.', 404);
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
        [$data, $verr] = $this->validate($response, (array) ($request->getParsedBody() ?? []));
        if ($verr !== null) {
            return $verr;
        }
        if ($this->units->findByCode($supplierId, $data['code']) !== null) {
            return Json::error($response, 'packaging_unit_code_taken', 'Balení s tímto kódem už existuje.', 409);
        }
        $id = $this->units->insert($supplierId, $data);
        $this->log($request, 'eshop.packaging_unit_created', $id, ['code' => $data['code']]);
        return Json::ok($response, $this->units->find($supplierId, $id) ?? [], 201);
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
        $existing = $this->units->find($supplierId, $id);
        if ($existing === null) {
            return Json::error($response, 'not_found', 'Balení nenalezeno.', 404);
        }
        [$data, $verr] = $this->validate($response, (array) ($request->getParsedBody() ?? []), $existing);
        if ($verr !== null) {
            return $verr;
        }
        if ($data['code'] !== $existing['code']) {
            if ($existing['usage_count'] > 0) {
                return Json::error(
                    $response,
                    'packaging_unit_code_in_use',
                    'Kód balení používají skladové karty — nelze ho změnit.',
                    422,
                    ['usage_count' => $existing['usage_count']],
                );
            }
            $byCode = $this->units->findByCode($supplierId, $data['code']);
            if ($byCode !== null && (int) $byCode['id'] !== $id) {
                return Json::error($response, 'packaging_unit_code_taken', 'Balení s tímto kódem už existuje.', 409);
            }
        }
        $this->units->update($supplierId, $id, $data);
        $this->log($request, 'eshop.packaging_unit_updated', $id, ['code' => $data['code']]);
        return Json::ok($response, $this->units->find($supplierId, $id) ?? []);
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
        $existing = $this->units->find($supplierId, $id);
        if ($existing === null) {
            return Json::error($response, 'not_found', 'Balení nenalezeno.', 404);
        }
        if ($existing['usage_count'] > 0) {
            return Json::error(
                $response,
                'packaging_unit_in_use',
                'Balení používají skladové karty — nelze ho smazat. Deaktivujte ho místo mazání.',
                409,
                ['usage_count' => $existing['usage_count'], 'suggestion' => 'deactivate'],
            );
        }
        $this->units->delete($supplierId, $id);
        $this->log($request, 'eshop.packaging_unit_deleted', $id, ['code' => $existing['code']]);
        return Json::ok($response, ['deleted' => true]);
    }

    /**
     * @param array<string,mixed> $body
     * @param array<string,mixed>|null $existing
     * @return array{0:array{code:string, name:string, is_active:bool, display_order:int}, 1:?Response}
     */
    private function validate(Response $response, array $body, ?array $existing = null): array
    {
        $code = trim((string) ($body['code'] ?? $existing['code'] ?? ''));
        $name = trim((string) ($body['name'] ?? $existing['name'] ?? ''));
        if ($code === '' || mb_strlen($code) > 20 || preg_match('/\s/u', $code) === 1) {
            return [[], Json::error($response, 'validation_failed', 'Kód balení je povinný (max 20 znaků, bez mezer).', 400)];
        }
        if ($name === '' || mb_strlen($name) > 100) {
            return [[], Json::error($response, 'validation_failed', 'Název balení je povinný (max 100 znaků).', 400)];
        }
        return [[
            'code'          => $code,
            'name'          => $name,
            'is_active'     => array_key_exists('is_active', $body) ? (bool) $body['is_active'] : (bool) ($existing['is_active'] ?? true),
            'display_order' => array_key_exists('display_order', $body) ? (int) $body['display_order'] : (int) ($existing['display_order'] ?? 0),
        ], null];
    }

    private function log(Request $request, string $action, int $id, array $payload): void
    {
        $this->logger->log(
            $action,
            $this->userId($request),
            'stock_packaging_unit',
            $id,
            $payload,
            $this->ipMatcher->clientIpFromRequest($request->getServerParams()),
            $request->getHeaderLine('User-Agent'),
            $this->currentSupplierId($request),
        );
    }
}
