<?php

declare(strict_types=1);

namespace MyInvoice\Action\Eshop;

use MyInvoice\Action\Accounting\AccountingActionSupport;
use MyInvoice\Action\Stock\GuardsStockEnabled;
use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\StockTagRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Štítky zboží — číselník (Epic ESHOP).
 *
 *   GET/POST /api/eshop/tags ; GET/PUT/DELETE /api/eshop/tags/{id}
 */
final class StockTagAction
{
    use AccountingActionSupport;
    use GuardsStockEnabled;

    public function __construct(
        private readonly Connection $db,
        private readonly StockTagRepository $tags,
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
        return Json::ok($response, $this->tags->listForSupplier($supplierId, !empty($q['active'])));
    }

    public function get(Request $request, Response $response, array $args): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->guardStockEnabled($this->db, $supplierId, $response, $err)) {
            return $err;
        }
        $row = $this->tags->find($supplierId, (int) $args['id']);
        if ($row === null) {
            return Json::error($response, 'not_found', 'Štítek nenalezen.', 404);
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
        if ($this->tags->findByCode($supplierId, $data['code']) !== null) {
            return Json::error($response, 'tag_code_taken', 'Štítek s tímto kódem už existuje.', 409);
        }
        $id = $this->tags->insert($supplierId, $data);
        $this->log($request, 'eshop.tag_created', $id, ['code' => $data['code']]);
        return Json::ok($response, $this->tags->find($supplierId, $id) ?? [], 201);
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
        $existing = $this->tags->find($supplierId, $id);
        if ($existing === null) {
            return Json::error($response, 'not_found', 'Štítek nenalezen.', 404);
        }
        $body = (array) ($request->getParsedBody() ?? []);
        [$data, $verr] = $this->validate($response, $body, $existing);
        if ($verr !== null) {
            return $verr;
        }
        $byCode = $this->tags->findByCode($supplierId, $data['code']);
        if ($byCode !== null && (int) $byCode['id'] !== $id) {
            return Json::error($response, 'tag_code_taken', 'Štítek s tímto kódem už existuje.', 409);
        }
        $this->tags->update($supplierId, $id, $data);
        $this->log($request, 'eshop.tag_updated', $id, ['code' => $data['code']]);
        return Json::ok($response, $this->tags->find($supplierId, $id) ?? []);
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
        if ($this->tags->find($supplierId, $id) === null) {
            return Json::error($response, 'not_found', 'Štítek nenalezen.', 404);
        }
        $this->tags->delete($supplierId, $id);
        $this->log($request, 'eshop.tag_deleted', $id, []);
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
            return [[], Json::error($response, 'validation_failed', 'Kód štítku je povinný (max 50 znaků).', 400)];
        }
        if ($name === '' || mb_strlen($name) > 100) {
            return [[], Json::error($response, 'validation_failed', 'Název štítku je povinný (max 100 znaků).', 400)];
        }
        $color = null;
        if (array_key_exists('color', $body)) {
            $c = trim((string) $body['color']);
            if ($c !== '' && preg_match('/^#[0-9a-fA-F]{6}$/', $c) !== 1) {
                return [[], Json::error($response, 'validation_failed', 'Barva musí být ve formátu #RRGGBB.', 400)];
            }
            $color = $c !== '' ? $c : null;
        } else {
            $color = $existing['color'] ?? null;
        }
        $data = [
            'code'     => $code,
            'name'     => $name,
            'color'    => $color,
            'archived' => array_key_exists('archived', $body) ? (bool) $body['archived'] : (bool) ($existing['archived'] ?? false),
        ];
        return [$data, null];
    }

    private function log(Request $request, string $action, int $id, array $payload): void
    {
        $this->logger->log(
            $action,
            $this->userId($request),
            'stock_tag',
            $id,
            $payload,
            $this->ipMatcher->clientIpFromRequest($request->getServerParams()),
            $request->getHeaderLine('User-Agent'),
            $this->currentSupplierId($request),
        );
    }
}
