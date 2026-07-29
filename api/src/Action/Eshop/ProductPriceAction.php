<?php

declare(strict_types=1);

namespace MyInvoice\Action\Eshop;

use MyInvoice\Action\Accounting\AccountingActionSupport;
use MyInvoice\Action\Stock\GuardsStockEnabled;
use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\StockItemPriceRepository;
use MyInvoice\Repository\StockItemRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Eshop\Pricing\PriceRecomputeDispatcher;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Cenotvorba karty — ceny per měna + přepočet (Epic ESHOP).
 *
 *   GET /api/eshop/products/{id}/prices
 *   PUT /api/eshop/products/{id}/prices              — bulk definice + přepočet
 *   POST /api/eshop/products/{id}/prices/recompute   — vynucený přepočet
 */
final class ProductPriceAction
{
    use AccountingActionSupport;
    use GuardsStockEnabled;

    private const MODES = ['markup', 'fixed'];
    private const ROUNDINGS = ['none', '0.01', '0.10', '0.50', '1', '9_ending'];

    public function __construct(
        private readonly Connection $db,
        private readonly StockItemRepository $items,
        private readonly StockItemPriceRepository $prices,
        private readonly PriceRecomputeDispatcher $dispatcher,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function get(Request $request, Response $response, array $args): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->guardStockEnabled($this->db, $supplierId, $response, $err)) {
            return $err;
        }
        $itemId = (int) $args['id'];
        if ($this->items->find($supplierId, $itemId) === null) {
            return Json::error($response, 'not_found', 'Karta zboží nenalezena.', 404);
        }
        return Json::ok($response, $this->prices->listForItem($supplierId, $itemId));
    }

    public function put(Request $request, Response $response, array $args): Response
    {
        if (!$this->requireWrite($request, $response, $err)) {
            return $err;
        }
        $supplierId = $this->currentSupplierId($request);
        if (!$this->guardStockEnabled($this->db, $supplierId, $response, $err)) {
            return $err;
        }
        $itemId = (int) $args['id'];
        if ($this->items->find($supplierId, $itemId) === null) {
            return Json::error($response, 'not_found', 'Karta zboží nenalezena.', 404);
        }
        $body = (array) ($request->getParsedBody() ?? []);
        $rows = is_array($body['prices'] ?? null) ? $body['prices'] : [];

        // Validace + normalizace řádků.
        $prepared = [];
        $keepCurrencies = [];
        foreach ($rows as $r) {
            if (!is_array($r)) {
                continue;
            }
            $currency = strtoupper(trim((string) ($r['currency_code'] ?? '')));
            if (!preg_match('/^[A-Z]{3}$/', $currency)) {
                return Json::error($response, 'validation_failed', 'Neplatný kód měny (ISO 4217, 3 znaky).', 400);
            }
            $mode = (string) ($r['price_mode'] ?? 'markup');
            if (!in_array($mode, self::MODES, true)) {
                return Json::error($response, 'validation_failed', 'Neplatný režim ceny.', 400);
            }
            $rounding = (string) ($r['rounding'] ?? 'none');
            if (!in_array($rounding, self::ROUNDINGS, true)) {
                return Json::error($response, 'validation_failed', 'Neplatné zaokrouhlení.', 400);
            }
            $markup = $this->numOrNull($r['markup_pct'] ?? null);
            $fixed = $this->numOrNull($r['fixed_price'] ?? null);
            if ($mode === 'fixed' && $fixed === null) {
                return Json::error($response, 'validation_failed', 'Pevná cena musí být zadaná pro režim „fixed".', 400);
            }
            if ($mode === 'markup' && $markup === null) {
                $markup = '0';
            }
            $keepCurrencies[$currency] = true;
            $prepared[] = [
                'currency_code'      => $currency,
                'price_mode'         => $mode,
                'markup_pct'         => $markup,
                'fixed_price'        => $fixed,
                'rounding'           => $rounding,
                'is_manual_override' => (bool) ($r['is_manual_override'] ?? false),
            ];
        }

        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            // Smaž měny, které v payloadu nejsou.
            foreach ($this->prices->listForItem($supplierId, $itemId) as $existing) {
                if (!isset($keepCurrencies[strtoupper((string) $existing['currency_code'])])) {
                    $this->prices->delete($supplierId, $itemId, (string) $existing['currency_code']);
                }
            }
            foreach ($prepared as $p) {
                $this->prices->upsert($supplierId, $itemId, $p['currency_code'], $p);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        // Přepočet (mimo předchozí tx — recompute si otevře vlastní).
        $result = $this->dispatcher->recomputeItem($supplierId, $itemId);
        $this->log($request, 'eshop.prices_updated', $itemId, ['currencies' => array_keys($keepCurrencies)]);
        return Json::ok($response, $result);
    }

    public function recompute(Request $request, Response $response, array $args): Response
    {
        if (!$this->requireWrite($request, $response, $err)) {
            return $err;
        }
        $supplierId = $this->currentSupplierId($request);
        if (!$this->guardStockEnabled($this->db, $supplierId, $response, $err)) {
            return $err;
        }
        $itemId = (int) $args['id'];
        if ($this->items->find($supplierId, $itemId) === null) {
            return Json::error($response, 'not_found', 'Karta zboží nenalezena.', 404);
        }
        $result = $this->dispatcher->recomputeItem($supplierId, $itemId);
        $this->log($request, 'eshop.prices_recomputed', $itemId, []);
        return Json::ok($response, $result);
    }

    private function numOrNull(mixed $v): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }
        $s = str_replace(',', '.', (string) $v);
        return is_numeric($s) ? $s : null;
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
