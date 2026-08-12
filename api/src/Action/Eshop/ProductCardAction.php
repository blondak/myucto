<?php

declare(strict_types=1);

namespace MyInvoice\Action\Eshop;

use MyInvoice\Action\Accounting\AccountingActionSupport;
use MyInvoice\Action\Stock\GuardsStockEnabled;
use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\StockItemI18nRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Eshop\EshopException;
use MyInvoice\Service\Eshop\Pricing\EffectivePriceResolver;
use MyInvoice\Service\Eshop\ProductCardService;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Karta Zboží pro eshop — agregát (Epic ESHOP).
 *
 *   GET/PUT /api/eshop/products/{id}          — plná karta (agregát satelitů)
 *   GET     /api/eshop/products/{id}/i18n     — jen překlady karty
 *
 * Základní skladová identita (sku/name/vat/cena) se edituje přes /api/stock/items;
 * tento endpoint řeší eshopový obsah (jazyky, kategorie, tagy, parametry, poplatky,
 * eshop sloupce). is_stocked=0 karty fungují bez stock_levels.
 */
final class ProductCardAction
{
    use AccountingActionSupport;
    use GuardsStockEnabled;

    public function __construct(
        private readonly Connection $db,
        private readonly ProductCardService $cards,
        private readonly StockItemI18nRepository $i18n,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
        private readonly EffectivePriceResolver $effectivePrice,
    ) {}

    public function get(Request $request, Response $response, array $args): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->guardStockEnabled($this->db, $supplierId, $response, $err)) {
            return $err;
        }
        $id = (int) $args['id'];
        $card = $this->cards->get($supplierId, $id);
        if ($card === null) {
            return Json::error($response, 'not_found', 'Karta zboží nenalezena.', 404);
        }
        // Platná cena karty (akční cena nad cenotvorbou) — jediný zdroj je resolver.
        $card['effective_price'] = $this->effectivePrice->resolve($supplierId, $id);
        return Json::ok($response, $card);
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
            $card = $this->cards->update($supplierId, (int) $args['id'], $body);
        } catch (EshopException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus, $e->details);
        }
        $this->log($request, 'eshop.product_updated', (int) $args['id'], []);
        return Json::ok($response, $card);
    }

    public function getI18n(Request $request, Response $response, array $args): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->guardStockEnabled($this->db, $supplierId, $response, $err)) {
            return $err;
        }
        return Json::ok($response, $this->i18n->listForItem($supplierId, (int) $args['id']));
    }

    private function log(Request $request, string $action, int $id, array $payload): void
    {
        $this->logger->log(
            $action,
            $this->userId($request),
            'stock_item',
            $id,
            $payload,
            $this->ipMatcher->clientIpFromRequest($request->getServerParams()),
            $request->getHeaderLine('User-Agent'),
            $this->currentSupplierId($request),
        );
    }
}
