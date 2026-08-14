<?php

declare(strict_types=1);

namespace MyInvoice\Action\Eshop;

use MyInvoice\Action\Accounting\AccountingActionSupport;
use MyInvoice\Action\Stock\GuardsStockEnabled;
use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\StockCurrencyRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Měny — číselník prodejních měn e-shopu (migrace 1371).
 *
 *   GET    /api/eshop/currencies        — seznam (?active=1 jen nearchivované)
 *   POST   /api/eshop/currencies        — nová
 *   GET    /api/eshop/currencies/{id}   — detail
 *   PUT    /api/eshop/currencies/{id}   — úprava
 *   DELETE /api/eshop/currencies/{id}   — smazání (jen bez cen)
 *
 * Nesouvisí s `currencies` (měnové účty dodavatele) — viz docblock repository.
 */
final class CurrencyAction
{
    use AccountingActionSupport;
    use GuardsStockEnabled;

    /** ISO 4217 — tři velká písmena. */
    private const CODE_PATTERN = '/^[A-Z]{3}$/';

    public function __construct(
        private readonly Connection $db,
        private readonly StockCurrencyRepository $currencies,
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
        $activeOnly = !empty($q['active']);
        return Json::ok($response, $this->currencies->listForSupplier($supplierId, $activeOnly));
    }

    public function get(Request $request, Response $response, array $args): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->guardStockEnabled($this->db, $supplierId, $response, $err)) {
            return $err;
        }
        $row = $this->currencies->find($supplierId, (int) $args['id']);
        if ($row === null) {
            return Json::error($response, 'not_found', 'Měna nenalezena.', 404);
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
        if ($this->currencies->findByCode($supplierId, $data['code']) !== null) {
            return Json::error($response, 'currency_code_taken', 'Měna s tímto kódem už existuje.', 409);
        }
        $id = $this->currencies->insert($supplierId, $data);
        if ($data['is_default']) {
            $this->currencies->clearDefaultExcept($supplierId, $id);
        }
        $this->log($request, 'eshop.currency_created', $id, ['code' => $data['code']]);
        return Json::ok($response, $this->currencies->find($supplierId, $id) ?? [], 201);
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
        $existing = $this->currencies->find($supplierId, $id);
        if ($existing === null) {
            return Json::error($response, 'not_found', 'Měna nenalezena.', 404);
        }
        $body = (array) ($request->getParsedBody() ?? []);
        [$data, $verr] = $this->validate($response, $body, $existing);
        if ($verr !== null) {
            return $verr;
        }
        $byCode = $this->currencies->findByCode($supplierId, $data['code']);
        if ($byCode !== null && (int) $byCode['id'] !== $id) {
            return Json::error($response, 'currency_code_taken', 'Měna s tímto kódem už existuje.', 409);
        }
        // Přejmenovat kód měny, ve které už jsou ceny, by je odpojilo od číselníku
        // (stock_item_prices.currency_code je hodnota, ne FK) — proto zákaz.
        if ($data['code'] !== (string) $existing['code'] && $this->currencies->isReferenced($supplierId, (string) $existing['code'])) {
            return Json::error($response, 'currency_code_locked', 'Kód měny nelze změnit — už jsou v ní zadané ceny.', 409);
        }
        $this->currencies->update($supplierId, $id, $data);
        if ($data['is_default']) {
            $this->currencies->clearDefaultExcept($supplierId, $id);
        }
        $this->log($request, 'eshop.currency_updated', $id, ['code' => $data['code']]);
        return Json::ok($response, $this->currencies->find($supplierId, $id) ?? []);
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
        $existing = $this->currencies->find($supplierId, $id);
        if ($existing === null) {
            return Json::error($response, 'not_found', 'Měna nenalezena.', 404);
        }
        if ($this->currencies->isReferenced($supplierId, (string) $existing['code'])) {
            return Json::error($response, 'currency_in_use', 'Měnu nelze smazat — existují v ní ceny zboží. Archivujte ji místo mazání.', 409, ['suggestion' => 'archive']);
        }
        $this->currencies->delete($supplierId, $id);
        $this->log($request, 'eshop.currency_deleted', $id, ['code' => $existing['code']]);
        return Json::ok($response, ['deleted' => true]);
    }

    /**
     * @param array<string,mixed> $body
     * @param array<string,mixed>|null $existing
     * @return array{0:array<string,mixed>, 1:?Response}
     */
    private function validate(Response $response, array $body, ?array $existing = null): array
    {
        $code = strtoupper(trim((string) ($body['code'] ?? $existing['code'] ?? '')));
        $name = trim((string) ($body['name'] ?? $existing['name'] ?? ''));
        if (!preg_match(self::CODE_PATTERN, $code)) {
            return [[], Json::error($response, 'validation_failed', 'Kód měny musí být trojpísmenný kód ISO 4217, například „CZK".', 400)];
        }
        if ($name === '' || mb_strlen($name) > 100) {
            return [[], Json::error($response, 'validation_failed', 'Název měny je povinný (max 100 znaků).', 400)];
        }
        $data = [
            'code'          => $code,
            'name'          => $name,
            'symbol'        => array_key_exists('symbol', $body) ? $body['symbol'] : ($existing['symbol'] ?? null),
            'display_order' => array_key_exists('display_order', $body) ? (int) $body['display_order'] : (int) ($existing['display_order'] ?? 0),
            'is_default'    => array_key_exists('is_default', $body) ? (bool) $body['is_default'] : (bool) ($existing['is_default'] ?? false),
            'archived'      => array_key_exists('archived', $body) ? (bool) $body['archived'] : (bool) ($existing['archived'] ?? false),
        ];
        // Archivovaná měna nemůže být zároveň výchozí — ceník by předvyplňoval
        // měnu, která v nabídce není.
        if ($data['archived']) {
            $data['is_default'] = false;
        }
        return [$data, null];
    }

    private function log(Request $request, string $action, int $id, array $payload): void
    {
        $this->logger->log(
            $action,
            $this->userId($request),
            'stock_currency',
            $id,
            $payload,
            $this->ipMatcher->clientIpFromRequest($request->getServerParams()),
            $request->getHeaderLine('User-Agent'),
            $this->currentSupplierId($request),
        );
    }
}
