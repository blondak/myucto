<?php

declare(strict_types=1);

namespace MyInvoice\Action\Stock;

use MyInvoice\Action\Accounting\AccountingActionSupport;
use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\StockLevelRepository;
use MyInvoice\Support\Pagination;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Stavy zásob — čtecí přehledy (Epic SKLAD).
 *
 *   GET /api/stock/levels        — stav skladu, stránkovaný (filtry: warehouse_id, item_type,
 *                                   below_min, active, q, item_ids)
 *   GET /api/stock/availability  — dávková dostupnost karet (badge v editoru FV)
 */
final class StockLevelAction
{
    use AccountingActionSupport;
    use GuardsStockEnabled;

    public function __construct(
        private readonly Connection $db,
        private readonly StockLevelRepository $levels,
    ) {}

    public function levels(Request $request, Response $response): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->guardStockEnabled($this->db, $supplierId, $response, $err)) {
            return $err;
        }
        $q = $request->getQueryParams();
        $filters = [];
        if (!empty($q['warehouse_id'])) {
            $filters['warehouse_id'] = (int) $q['warehouse_id'];
        }
        if (!empty($q['item_type'])) {
            $filters['item_type'] = (string) $q['item_type'];
        }
        if (!empty($q['below_min'])) {
            $filters['below_min'] = true;
        }
        if (array_key_exists('active', $q) && $q['active'] !== '') {
            $filters['active'] = (bool) (int) $q['active'];
        }
        if (!empty($q['q'])) {
            $filters['q'] = (string) $q['q'];
        }
        if (!empty($q['item_ids'])) {
            $ids = array_values(array_filter(
                array_map('intval', array_filter(explode(',', (string) $q['item_ids']), static fn ($v): bool => trim((string) $v) !== '')),
                static fn (int $v): bool => $v > 0,
            ));
            if ($ids !== []) {
                $filters['item_ids'] = $ids;
            }
        }

        $p = Pagination::fromQuery($q, 50);
        [$rows, $total] = $this->levels->levelsPaged($supplierId, $filters, $p['per_page'], $p['offset']);
        return Json::ok($response, Pagination::envelope($rows, $total, $p['page'], $p['per_page']));
    }

    public function availability(Request $request, Response $response): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->guardStockEnabled($this->db, $supplierId, $response, $err)) {
            return $err;
        }
        $q = $request->getQueryParams();
        $raw = (string) ($q['item_ids'] ?? '');
        $itemIds = array_values(array_filter(
            array_map('intval', array_filter(explode(',', $raw), static fn ($v): bool => trim((string) $v) !== '')),
            static fn (int $v): bool => $v > 0,
        ));
        $warehouseId = !empty($q['warehouse_id']) ? (int) $q['warehouse_id'] : null;
        return Json::ok($response, $this->levels->availability($supplierId, $itemIds, $warehouseId));
    }
}
