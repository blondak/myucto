<?php

declare(strict_types=1);

namespace MyInvoice\Action\Eshop;

use MyInvoice\Action\Accounting\AccountingActionSupport;
use MyInvoice\Action\Stock\GuardsStockEnabled;
use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\StockItemPromoPriceRepository;
use MyInvoice\Repository\StockItemRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Eshop\Pricing\EffectivePriceResolver;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Akční (promoční) ceny karty — časově a množstevně omezená sleva nad
 * standardní cenotvorbou (migrace 1328).
 *
 *   GET /api/eshop/products/{id}/promo-prices    — akce karty + dopočtený stav
 *   PUT /api/eshop/products/{id}/promo-prices    — bulk replace (jako u /prices)
 *   GET /api/eshop/products/{id}/effective-price — co teď zaplatí zákazník
 */
final class ProductPromoPriceAction
{
    use AccountingActionSupport;
    use GuardsStockEnabled;

    private const QTY_MODES = ['stock', 'limited', 'unlimited'];
    private const MAX_ROWS = 50;

    public function __construct(
        private readonly Connection $db,
        private readonly StockItemRepository $items,
        private readonly StockItemPromoPriceRepository $promos,
        private readonly EffectivePriceResolver $resolver,
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
        $rows = $this->promos->listForItem($supplierId, $itemId);
        return Json::ok($response, $this->resolver->annotate($supplierId, $rows));
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
        $rows = is_array($body['promo_prices'] ?? null) ? $body['promo_prices'] : [];
        if (count($rows) > self::MAX_ROWS) {
            return Json::error($response, 'validation_failed', 'Karta může mít nejvýše ' . self::MAX_ROWS . ' akčních cen.', 400);
        }

        $prepared = [];
        foreach ($rows as $r) {
            if (!is_array($r)) {
                continue;
            }
            [$data, $error] = $this->validateRow($r);
            if ($error !== null) {
                return Json::error($response, 'validation_failed', $error, 400);
            }
            $id = (int) ($r['id'] ?? 0);
            // Cizí/neexistující id v payloadu se nesmí přepsat (IDOR) — repository
            // filtruje na tenanta, ale ověříme to explicitně a nahlas.
            if ($id > 0) {
                $existing = $this->promos->find($supplierId, $id);
                if ($existing === null || (int) $existing['stock_item_id'] !== $itemId) {
                    return Json::error($response, 'not_found', 'Akční cena nenalezena.', 404);
                }
            }
            $prepared[] = ['id' => $id, 'data' => $data];
        }

        // Reentrantní obal (vzor ProductPriceAction) — pod už běžící transakcí by
        // holé beginTransaction() shodilo request na PDOException.
        $pdo = $this->db->pdo();
        $owns = !$pdo->inTransaction();
        if ($owns) {
            $pdo->beginTransaction();
        }
        try {
            $keep = [];
            foreach ($prepared as $p) {
                if ($p['id'] > 0) {
                    $this->promos->update($supplierId, $p['id'], $p['data']);
                    $keep[] = $p['id'];
                } else {
                    $keep[] = $this->promos->insert($supplierId, $itemId, $p['data']);
                }
            }
            $this->promos->deleteForItemExcept($supplierId, $itemId, $keep);
            if ($owns) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($owns && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        $this->log($request, 'eshop.promo_prices_updated', $itemId, ['count' => count($prepared)]);
        $saved = $this->promos->listForItem($supplierId, $itemId);
        return Json::ok($response, $this->resolver->annotate($supplierId, $saved));
    }

    /** Platná cena karty pro dané množství a měnu (co teď zaplatí zákazník). */
    public function effective(Request $request, Response $response, array $args): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->guardStockEnabled($this->db, $supplierId, $response, $err)) {
            return $err;
        }
        $itemId = (int) $args['id'];
        if ($this->items->find($supplierId, $itemId) === null) {
            return Json::error($response, 'not_found', 'Karta zboží nenalezena.', 404);
        }
        $q = $request->getQueryParams();
        $currency = strtoupper(trim((string) ($q['currency'] ?? 'CZK')));
        if (!preg_match('/^[A-Z]{3}$/', $currency)) {
            return Json::error($response, 'validation_failed', 'Neplatný kód měny (ISO 4217, 3 znaky).', 400);
        }
        $onDate = trim((string) ($q['on_date'] ?? ''));
        if ($onDate !== '' && !$this->isDate($onDate)) {
            return Json::error($response, 'validation_failed', 'Neplatné datum (formát RRRR-MM-DD).', 400);
        }

        return Json::ok($response, $this->resolver->resolve(
            $supplierId,
            $itemId,
            $currency,
            (string) ($q['qty'] ?? '1'),
            $onDate !== '' ? $onDate : null,
        ));
    }

    /**
     * @param array<string,mixed> $r
     * @return array{0:array<string,mixed>, 1:?string} [data, chyba]
     */
    private function validateRow(array $r): array
    {
        $currency = strtoupper(trim((string) ($r['currency_code'] ?? 'CZK')));
        if (!preg_match('/^[A-Z]{3}$/', $currency)) {
            return [[], 'Neplatný kód měny (ISO 4217, 3 znaky).'];
        }
        $price = $this->numOrNull($r['promo_price'] ?? null);
        if ($price === null || bccomp($price, '0', 2) < 0) {
            return [[], 'Akční cena musí být nezáporné číslo.'];
        }

        $mode = (string) ($r['qty_mode'] ?? 'stock');
        if (!in_array($mode, self::QTY_MODES, true)) {
            return [[], 'Neplatný režim množstevního stropu akce.'];
        }
        $limit = $this->numOrNull($r['qty_limit'] ?? null);
        if ($mode === 'limited') {
            if ($limit === null || bccomp($limit, '0', 3) <= 0) {
                return [[], 'Pro omezený počet kusů zadej kladný počet.'];
            }
        } else {
            $limit = null; // strop drží sklad ('stock') nebo se neomezuje ('unlimited')
        }

        $from = $this->dateOrNull($r['valid_from'] ?? null);
        $to = $this->dateOrNull($r['valid_to'] ?? null);
        if ($from === false || $to === false) {
            return [[], 'Neplatné datum platnosti akce (formát RRRR-MM-DD).'];
        }
        if ($from !== null && $to !== null && $to < $from) {
            return [[], 'Konec platnosti akce nesmí předcházet jejímu začátku.'];
        }

        $label = $this->strOrNull($r['label'] ?? null, 60);
        if ($label === false) {
            return [[], 'Název akce může mít nejvýše 60 znaků.'];
        }
        $note = $this->strOrNull($r['note'] ?? null, 255);
        if ($note === false) {
            return [[], 'Poznámka může mít nejvýše 255 znaků.'];
        }

        return [[
            'currency_code' => $currency,
            'promo_price'   => bcadd($price, '0', 2),
            'label'         => $label,
            'valid_from'    => $from,
            'valid_to'      => $to,
            'qty_mode'      => $mode,
            'qty_limit'     => $limit !== null ? bcadd($limit, '0', 3) : null,
            'is_active'     => (bool) ($r['is_active'] ?? true),
            'note'          => $note,
        ], null];
    }

    private function numOrNull(mixed $v): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }
        $s = str_replace(',', '.', (string) $v);
        return is_numeric($s) ? $s : null;
    }

    /** @return string|null|false false = neplatný formát */
    private function dateOrNull(mixed $v): string|null|false
    {
        if ($v === null || trim((string) $v) === '') {
            return null;
        }
        $s = trim((string) $v);
        return $this->isDate($s) ? $s : false;
    }

    /** @return string|null|false false = příliš dlouhé */
    private function strOrNull(mixed $v, int $max): string|null|false
    {
        if ($v === null) {
            return null;
        }
        $s = trim((string) $v);
        if ($s === '') {
            return null;
        }
        return mb_strlen($s) > $max ? false : $s;
    }

    private function isDate(string $s): bool
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) !== 1) {
            return false;
        }
        [$y, $m, $d] = array_map('intval', explode('-', $s));
        return checkdate($m, $d, $y);
    }

    /** @param array<string,mixed> $payload */
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
