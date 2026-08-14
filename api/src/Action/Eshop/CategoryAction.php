<?php

declare(strict_types=1);

namespace MyInvoice\Action\Eshop;

use MyInvoice\Action\Accounting\AccountingActionSupport;
use MyInvoice\Action\Stock\GuardsStockEnabled;
use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\StockCategoryI18nRepository;
use MyInvoice\Repository\StockCategoryRepository;
use MyInvoice\Repository\StockLocaleRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Eshop\CategoryTreeService;
use MyInvoice\Service\Eshop\EshopException;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Kategorie zboží — strom (materialized path) + i18n (Epic ESHOP).
 *
 *   GET/POST /api/eshop/categories ; GET/PUT/DELETE /{id} ; POST /{id}/move
 *   GET/PUT  /api/eshop/categories/{id}/i18n
 */
final class CategoryAction
{
    use AccountingActionSupport;
    use GuardsStockEnabled;

    public function __construct(
        private readonly Connection $db,
        private readonly StockCategoryRepository $categories,
        private readonly StockCategoryI18nRepository $i18n,
        private readonly StockLocaleRepository $locales,
        private readonly CategoryTreeService $tree,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function list(Request $request, Response $response): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->guardStockEnabled($this->db, $supplierId, $response, $err)) {
            return $err;
        }
        return Json::ok($response, $this->categories->listForSupplier($supplierId));
    }

    public function get(Request $request, Response $response, array $args): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->guardStockEnabled($this->db, $supplierId, $response, $err)) {
            return $err;
        }
        $row = $this->categories->find($supplierId, (int) $args['id']);
        if ($row === null) {
            return Json::error($response, 'not_found', 'Kategorie nenalezena.', 404);
        }
        $row['i18n'] = $this->i18n->listForCategory($supplierId, (int) $args['id']);
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
        try {
            $row = $this->tree->create($supplierId, $body);
        } catch (EshopException $e) {
            return $this->fail($response, $e);
        }
        $this->log($request, 'eshop.category_created', (int) $row['id'], ['code' => $row['code'] ?? null]);
        return Json::ok($response, $row, 201);
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
        $body = (array) ($request->getParsedBody() ?? []);
        try {
            $row = $this->tree->update($supplierId, (int) $args['id'], $body);
        } catch (EshopException $e) {
            return $this->fail($response, $e);
        }
        $this->log($request, 'eshop.category_updated', (int) $args['id'], []);
        return Json::ok($response, $row);
    }

    public function move(Request $request, Response $response, array $args): Response
    {
        if (!$this->requireWrite($request, $response, $err)) {
            return $err;
        }
        $supplierId = $this->currentSupplierId($request);
        if (!$this->guardStockEnabled($this->db, $supplierId, $response, $err)) {
            return $err;
        }
        $body = (array) ($request->getParsedBody() ?? []);
        $parentId = array_key_exists('parent_id', $body) && $body['parent_id'] !== null && $body['parent_id'] !== ''
            ? (int) $body['parent_id']
            : null;
        try {
            $row = $this->tree->move($supplierId, (int) $args['id'], $parentId);
        } catch (EshopException $e) {
            return $this->fail($response, $e);
        }
        $this->log($request, 'eshop.category_moved', (int) $args['id'], ['parent_id' => $parentId]);
        return Json::ok($response, $row);
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
        try {
            $this->tree->delete($supplierId, (int) $args['id']);
        } catch (EshopException $e) {
            return $this->fail($response, $e);
        }
        $this->log($request, 'eshop.category_deleted', (int) $args['id'], []);
        return Json::ok($response, ['deleted' => true]);
    }

    public function getI18n(Request $request, Response $response, array $args): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->guardStockEnabled($this->db, $supplierId, $response, $err)) {
            return $err;
        }
        $id = (int) $args['id'];
        if ($this->categories->find($supplierId, $id) === null) {
            return Json::error($response, 'not_found', 'Kategorie nenalezena.', 404);
        }
        return Json::ok($response, $this->i18n->listForCategory($supplierId, $id));
    }

    public function putI18n(Request $request, Response $response, array $args): Response
    {
        if (!$this->requireWrite($request, $response, $err)) {
            return $err;
        }
        $supplierId = $this->currentSupplierId($request);
        if (!$this->guardStockEnabled($this->db, $supplierId, $response, $err)) {
            return $err;
        }
        $id = (int) $args['id'];
        if ($this->categories->find($supplierId, $id) === null) {
            return Json::error($response, 'not_found', 'Kategorie nenalezena.', 404);
        }
        $body = (array) ($request->getParsedBody() ?? []);
        $rows = is_array($body['translations'] ?? null) ? $body['translations'] : $body;
        $known = $this->locales->codes($supplierId);
        foreach ($rows as $r) {
            if (!is_array($r)) {
                continue;
            }
            $locale = trim((string) ($r['locale'] ?? ''));
            $name = trim((string) ($r['name'] ?? ''));
            if ($locale === '' || $name === '' || mb_strlen($locale) > 5) {
                continue;
            }
            // Stejné pravidlo jako u karty zboží — jazyky vede číselník (1370).
            if (!in_array($locale, $known, true)) {
                return Json::error($response, 'unknown_locale', "Jazyk „{$locale}\" není v číselníku jazyků.", 400);
            }
            $this->i18n->upsert($supplierId, $id, $locale, [
                'name'        => $name,
                'description' => isset($r['description']) && trim((string) $r['description']) !== '' ? trim((string) $r['description']) : null,
                'seo_slug'    => isset($r['seo_slug']) && trim((string) $r['seo_slug']) !== '' ? trim((string) $r['seo_slug']) : null,
            ]);
        }
        $this->log($request, 'eshop.category_i18n_updated', $id, []);
        return Json::ok($response, $this->i18n->listForCategory($supplierId, $id));
    }

    private function fail(Response $response, EshopException $e): Response
    {
        return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus, $e->details);
    }

    private function log(Request $request, string $action, int $id, array $payload): void
    {
        $this->logger->log(
            $action,
            $this->userId($request),
            'stock_category',
            $id,
            $payload,
            $this->ipMatcher->clientIpFromRequest($request->getServerParams()),
            $request->getHeaderLine('User-Agent'),
            $this->currentSupplierId($request),
        );
    }
}
