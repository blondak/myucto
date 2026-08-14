<?php

declare(strict_types=1);

namespace MyInvoice\Action\Eshop;

use MyInvoice\Action\Accounting\AccountingActionSupport;
use MyInvoice\Action\Stock\GuardsStockEnabled;
use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\StockLocaleRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Jazyky — číselník jazykových mutací e-shopu (migrace 1370).
 *
 *   GET    /api/eshop/locales        — seznam (?active=1 jen nearchivované)
 *   POST   /api/eshop/locales        — nový
 *   GET    /api/eshop/locales/{id}   — detail
 *   PUT    /api/eshop/locales/{id}   — úprava
 *   DELETE /api/eshop/locales/{id}   — smazání (jen bez překladů)
 */
final class LocaleAction
{
    use AccountingActionSupport;
    use GuardsStockEnabled;

    /** BCP 47 podmnožina, kterou udrží VARCHAR(5): `cs`, `pt-BR`. */
    private const CODE_PATTERN = '/^[a-z]{2}(-[A-Z]{2})?$/';

    public function __construct(
        private readonly Connection $db,
        private readonly StockLocaleRepository $locales,
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
        return Json::ok($response, $this->locales->listForSupplier($supplierId, $activeOnly));
    }

    public function get(Request $request, Response $response, array $args): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->guardStockEnabled($this->db, $supplierId, $response, $err)) {
            return $err;
        }
        $row = $this->locales->find($supplierId, (int) $args['id']);
        if ($row === null) {
            return Json::error($response, 'not_found', 'Jazyk nenalezen.', 404);
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
        if ($this->locales->findByCode($supplierId, $data['code']) !== null) {
            return Json::error($response, 'locale_code_taken', 'Jazyk s tímto kódem už existuje.', 409);
        }
        $id = $this->locales->insert($supplierId, $data);
        if ($data['is_default']) {
            $this->locales->clearDefaultExcept($supplierId, $id);
        }
        $this->log($request, 'eshop.locale_created', $id, ['code' => $data['code']]);
        return Json::ok($response, $this->locales->find($supplierId, $id) ?? [], 201);
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
        $existing = $this->locales->find($supplierId, $id);
        if ($existing === null) {
            return Json::error($response, 'not_found', 'Jazyk nenalezen.', 404);
        }
        $body = (array) ($request->getParsedBody() ?? []);
        [$data, $verr] = $this->validate($response, $body, $existing);
        if ($verr !== null) {
            return $verr;
        }
        $byCode = $this->locales->findByCode($supplierId, $data['code']);
        if ($byCode !== null && (int) $byCode['id'] !== $id) {
            return Json::error($response, 'locale_code_taken', 'Jazyk s tímto kódem už existuje.', 409);
        }
        // Přejmenovat kód jazyka, ke kterému už existují překlady, by je odpojilo
        // od číselníku (stock_item_i18n.locale je hodnota, ne FK) — proto zákaz.
        if ($data['code'] !== (string) $existing['code'] && $this->locales->isReferenced($supplierId, (string) $existing['code'])) {
            return Json::error($response, 'locale_code_locked', 'Kód jazyka nelze změnit — už jsou na něj navázané překlady.', 409);
        }
        $this->locales->update($supplierId, $id, $data);
        if ($data['is_default']) {
            $this->locales->clearDefaultExcept($supplierId, $id);
        }
        $this->log($request, 'eshop.locale_updated', $id, ['code' => $data['code']]);
        return Json::ok($response, $this->locales->find($supplierId, $id) ?? []);
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
        $existing = $this->locales->find($supplierId, $id);
        if ($existing === null) {
            return Json::error($response, 'not_found', 'Jazyk nenalezen.', 404);
        }
        if ($this->locales->isReferenced($supplierId, (string) $existing['code'])) {
            return Json::error($response, 'locale_in_use', 'Jazyk nelze smazat — existují v něm překlady zboží nebo kategorií. Archivujte jej místo mazání.', 409, ['suggestion' => 'archive']);
        }
        $this->locales->delete($supplierId, $id);
        $this->log($request, 'eshop.locale_deleted', $id, ['code' => $existing['code']]);
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
        if (!preg_match(self::CODE_PATTERN, $code)) {
            return [[], Json::error($response, 'validation_failed', 'Kód jazyka musí být ve tvaru „cs" nebo „pt-BR".', 400)];
        }
        if ($name === '' || mb_strlen($name) > 100) {
            return [[], Json::error($response, 'validation_failed', 'Název jazyka je povinný (max 100 znaků).', 400)];
        }
        $data = [
            'code'          => $code,
            'name'          => $name,
            'display_order' => array_key_exists('display_order', $body) ? (int) $body['display_order'] : (int) ($existing['display_order'] ?? 0),
            'is_default'    => array_key_exists('is_default', $body) ? (bool) $body['is_default'] : (bool) ($existing['is_default'] ?? false),
            'archived'      => array_key_exists('archived', $body) ? (bool) $body['archived'] : (bool) ($existing['archived'] ?? false),
        ];
        // Archivovaný jazyk nemůže být zároveň výchozí — karta by předvyplňovala
        // jazyk, který v nabídce není.
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
            'stock_locale',
            $id,
            $payload,
            $this->ipMatcher->clientIpFromRequest($request->getServerParams()),
            $request->getHeaderLine('User-Agent'),
            $this->currentSupplierId($request),
        );
    }
}
