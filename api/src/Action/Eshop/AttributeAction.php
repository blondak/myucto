<?php

declare(strict_types=1);

namespace MyInvoice\Action\Eshop;

use MyInvoice\Action\Accounting\AccountingActionSupport;
use MyInvoice\Action\Stock\GuardsStockEnabled;
use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\StockAttributeRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Parametry/atributy zboží — definice + enum options (Epic ESHOP).
 *
 *   GET/POST /api/eshop/attributes ; GET/PUT/DELETE /api/eshop/attributes/{id}
 *   GET/POST /api/eshop/attributes/{id}/options
 *   PUT/DELETE /api/eshop/attribute-options/{oid}
 */
final class AttributeAction
{
    use AccountingActionSupport;
    use GuardsStockEnabled;

    private const DATA_TYPES = ['text', 'number', 'bool', 'enum'];

    public function __construct(
        private readonly Connection $db,
        private readonly StockAttributeRepository $attributes,
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
        $rows = $this->attributes->listForSupplier($supplierId, !empty($q['active']));
        foreach ($rows as &$r) {
            if ($r['data_type'] === 'enum') {
                $r['options'] = $this->attributes->listOptions($supplierId, (int) $r['id']);
            }
        }
        unset($r);
        return Json::ok($response, $rows);
    }

    public function get(Request $request, Response $response, array $args): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->guardStockEnabled($this->db, $supplierId, $response, $err)) {
            return $err;
        }
        $id = (int) $args['id'];
        $row = $this->attributes->find($supplierId, $id);
        if ($row === null) {
            return Json::error($response, 'not_found', 'Parametr nenalezen.', 404);
        }
        $row['options'] = $this->attributes->listOptions($supplierId, $id);
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
        if ($this->attributes->findByCode($supplierId, $data['code']) !== null) {
            return Json::error($response, 'attribute_code_taken', 'Parametr s tímto kódem už existuje.', 409);
        }
        $id = $this->attributes->insert($supplierId, $data);
        $this->log($request, 'eshop.attribute_created', $id, ['code' => $data['code']]);
        return Json::ok($response, $this->attributes->find($supplierId, $id) ?? [], 201);
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
        $existing = $this->attributes->find($supplierId, $id);
        if ($existing === null) {
            return Json::error($response, 'not_found', 'Parametr nenalezen.', 404);
        }
        $body = (array) ($request->getParsedBody() ?? []);
        [$data, $verr] = $this->validate($response, $body, $existing);
        if ($verr !== null) {
            return $verr;
        }
        $byCode = $this->attributes->findByCode($supplierId, $data['code']);
        if ($byCode !== null && (int) $byCode['id'] !== $id) {
            return Json::error($response, 'attribute_code_taken', 'Parametr s tímto kódem už existuje.', 409);
        }
        $this->attributes->update($supplierId, $id, $data);
        $this->log($request, 'eshop.attribute_updated', $id, ['code' => $data['code']]);
        return Json::ok($response, $this->attributes->find($supplierId, $id) ?? []);
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
        if ($this->attributes->find($supplierId, $id) === null) {
            return Json::error($response, 'not_found', 'Parametr nenalezen.', 404);
        }
        if ($this->attributes->isReferenced($supplierId, $id)) {
            return Json::error($response, 'attribute_in_use', 'Parametr nelze smazat — je použit u zboží. Archivujte jej místo mazání.', 409, ['suggestion' => 'archive']);
        }
        $this->attributes->delete($supplierId, $id);
        $this->log($request, 'eshop.attribute_deleted', $id, []);
        return Json::ok($response, ['deleted' => true]);
    }

    // ── Options ───────────────────────────────────────────────────────────────

    public function listOptions(Request $request, Response $response, array $args): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->guardStockEnabled($this->db, $supplierId, $response, $err)) {
            return $err;
        }
        $attrId = (int) $args['id'];
        if ($this->attributes->find($supplierId, $attrId) === null) {
            return Json::error($response, 'not_found', 'Parametr nenalezen.', 404);
        }
        return Json::ok($response, $this->attributes->listOptions($supplierId, $attrId));
    }

    public function createOption(Request $request, Response $response, array $args): Response
    {
        if (!$this->requireWrite($request, $response, $err)) {
            return $err;
        }
        $supplierId = $this->currentSupplierId($request);
        if (!$this->guardStockEnabled($this->db, $supplierId, $response, $err)) {
            return $err;
        }
        $attrId = (int) $args['id'];
        if ($this->attributes->find($supplierId, $attrId) === null) {
            return Json::error($response, 'not_found', 'Parametr nenalezen.', 404);
        }
        $body = (array) ($request->getParsedBody() ?? []);
        [$data, $verr] = $this->validateOption($response, $body);
        if ($verr !== null) {
            return $verr;
        }
        $id = $this->attributes->insertOption($supplierId, $attrId, $data);
        $this->log($request, 'eshop.attribute_option_created', $id, ['attribute_id' => $attrId]);
        return Json::ok($response, $this->attributes->findOption($supplierId, $id) ?? [], 201);
    }

    public function updateOption(Request $request, Response $response, array $args): Response
    {
        if (!$this->requireWrite($request, $response, $err)) {
            return $err;
        }
        $supplierId = $this->currentSupplierId($request);
        if (!$this->guardStockEnabled($this->db, $supplierId, $response, $err)) {
            return $err;
        }
        $oid = (int) $args['oid'];
        if ($this->attributes->findOption($supplierId, $oid) === null) {
            return Json::error($response, 'not_found', 'Volba nenalezena.', 404);
        }
        $body = (array) ($request->getParsedBody() ?? []);
        [$data, $verr] = $this->validateOption($response, $body);
        if ($verr !== null) {
            return $verr;
        }
        $this->attributes->updateOption($supplierId, $oid, $data);
        $this->log($request, 'eshop.attribute_option_updated', $oid, []);
        return Json::ok($response, $this->attributes->findOption($supplierId, $oid) ?? []);
    }

    public function deleteOption(Request $request, Response $response, array $args): Response
    {
        if (!$this->requireWrite($request, $response, $err)) {
            return $err;
        }
        $supplierId = $this->currentSupplierId($request);
        if (!$this->guardStockEnabled($this->db, $supplierId, $response, $err)) {
            return $err;
        }
        $oid = (int) $args['oid'];
        if ($this->attributes->findOption($supplierId, $oid) === null) {
            return Json::error($response, 'not_found', 'Volba nenalezena.', 404);
        }
        $this->attributes->deleteOption($supplierId, $oid);
        $this->log($request, 'eshop.attribute_option_deleted', $oid, []);
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
        if ($code === '' || mb_strlen($code) > 50) {
            return [[], Json::error($response, 'validation_failed', 'Kód parametru je povinný (max 50 znaků).', 400)];
        }
        if ($name === '' || mb_strlen($name) > 120) {
            return [[], Json::error($response, 'validation_failed', 'Název parametru je povinný (max 120 znaků).', 400)];
        }
        $dataType = (string) ($body['data_type'] ?? $existing['data_type'] ?? 'text');
        if (!in_array($dataType, self::DATA_TYPES, true)) {
            return [[], Json::error($response, 'validation_failed', 'Neplatný typ parametru.', 400)];
        }
        $unit = array_key_exists('unit', $body)
            ? (trim((string) $body['unit']) !== '' ? trim((string) $body['unit']) : null)
            : ($existing['unit'] ?? null);
        $data = [
            'code'          => $code,
            'name'          => $name,
            'data_type'     => $dataType,
            'unit'          => $unit,
            'is_filterable' => array_key_exists('is_filterable', $body) ? (bool) $body['is_filterable'] : (bool) ($existing['is_filterable'] ?? false),
            'is_multivalue' => array_key_exists('is_multivalue', $body) ? (bool) $body['is_multivalue'] : (bool) ($existing['is_multivalue'] ?? false),
            'display_order' => array_key_exists('display_order', $body) ? (int) $body['display_order'] : (int) ($existing['display_order'] ?? 0),
            'archived'      => array_key_exists('archived', $body) ? (bool) $body['archived'] : (bool) ($existing['archived'] ?? false),
        ];
        return [$data, null];
    }

    /**
     * @param array<string,mixed> $body
     * @return array{0:array<string,mixed>, 1:?Response}
     */
    private function validateOption(Response $response, array $body): array
    {
        $code = trim((string) ($body['code'] ?? ''));
        $label = trim((string) ($body['label'] ?? ''));
        if ($code === '' || mb_strlen($code) > 50) {
            return [[], Json::error($response, 'validation_failed', 'Kód volby je povinný (max 50 znaků).', 400)];
        }
        if ($label === '' || mb_strlen($label) > 120) {
            return [[], Json::error($response, 'validation_failed', 'Popisek volby je povinný (max 120 znaků).', 400)];
        }
        return [[
            'code'          => $code,
            'label'         => $label,
            'display_order' => (int) ($body['display_order'] ?? 0),
        ], null];
    }

    private function log(Request $request, string $action, int $id, array $payload): void
    {
        $this->logger->log(
            $action,
            $this->userId($request),
            'stock_attribute',
            $id,
            $payload,
            $this->ipMatcher->clientIpFromRequest($request->getServerParams()),
            $request->getHeaderLine('User-Agent'),
            $this->currentSupplierId($request),
        );
    }
}
