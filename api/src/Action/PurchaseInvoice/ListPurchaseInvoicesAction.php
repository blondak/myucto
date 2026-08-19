<?php

declare(strict_types=1);

namespace MyInvoice\Action\PurchaseInvoice;

use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\PurchaseInvoiceRepository;
use MyInvoice\Service\Accounting\DocumentLockService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * GET /api/purchase-invoices
 *
 * Vrací seznam přijatých faktur seskupený po měsících (per tenant).
 * Filtry: status, document_kind, vendor_id, project_id (id | 'none' = bez zakázky),
 * year, month, date_from, date_to, currency, q, unpaid_only, overdue,
 * unpaid_as_of (YYYY-MM-DD — stav úhrady K DATU X, ne dnešní status; viz PurchaseInvoiceRepository::listGroupedByMonth)
 */
final class ListPurchaseInvoicesAction
{
    public function __construct(
        private readonly PurchaseInvoiceRepository $repo,
        private readonly Config $config,
        private readonly DocumentLockService $locks,
    ) {}

    public function __invoke(Request $request, Response $response): Response
    {
        $q = $request->getQueryParams();
        $filter = (array) ($q['filter'] ?? []);

        // Neuhrazené K DATU X (task #4) — historický protějšek `unpaid_only`. Validace
        // shodná se SaldoAction::isDate — chybný formát je 422, ne tiché "nefiltrovat".
        $unpaidAsOf = isset($filter['unpaid_as_of']) && is_scalar($filter['unpaid_as_of'])
            ? trim((string) $filter['unpaid_as_of'])
            : '';
        if ($unpaidAsOf !== '' && !$this->isDate($unpaidAsOf)) {
            return Json::error($response, 'validation_failed', "filter[unpaid_as_of] musí být datum (YYYY-MM-DD).", 422);
        }

        $filters = [
            'q'             => isset($q['q']) ? trim((string) $q['q']) : '',
            'status'        => $filter['status']        ?? null,
            'document_kind' => $filter['document_kind'] ?? null,
            'vendor_id'     => $filter['vendor_id']     ?? null,
            'project_id'    => $filter['project_id']    ?? null,
            'year'          => $filter['year']          ?? null,
            'month'         => $filter['month']         ?? null,
            'date_from'     => $filter['date_from']     ?? null,
            'date_to'       => $filter['date_to']       ?? null,
            'currency'      => $filter['currency']      ?? null,
            'unpaid_only'   => !empty($filter['unpaid_only']),
            'overdue'       => !empty($filter['overdue']),
            'unpaid_as_of'  => $unpaidAsOf !== '' ? $unpaidAsOf : null,
            'unmatched'     => !empty($filter['unmatched']),
            'needs_review'  => !empty($filter['needs_review']),
            'payment_ordered' => $filter['payment_ordered'] ?? null,
            'booked'        => $filter['booked'] ?? null,
            'import_batch_id' => $filter['import_batch_id'] ?? null,
            'supplier_id'   => (int) $request->getAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 0),
        ];

        // CSV split pro multi-select
        foreach (['status', 'document_kind'] as $f) {
            if (is_string($filters[$f]) && $filters[$f] !== '' && str_contains($filters[$f], ',')) {
                $filters[$f] = explode(',', $filters[$f]);
            }
        }

        $page = max(1, (int) ($q['page'] ?? 1));
        $default = (int) $this->config->get('pagination.invoices_per_page', 50);
        $perPage = min(200, max(5, (int) ($q['per_page'] ?? $default)));

        $result = $this->repo->listGroupedByMonth($filters, $page, $perPage);

        // Jednotný kontrakt zámku per-row (Epic F6, §4.5) — batch přes lockedMapForSources,
        // jeden IN dotaz na posted zápisy, žádné N+1.
        $ids = [];
        foreach ($result['data'] as $group) {
            foreach ($group['invoices'] as $row) {
                $ids[] = (int) $row['id'];
            }
        }
        if ($ids !== []) {
            $map = $this->locks->lockedMapForSources((int) $filters['supplier_id'], 'purchase_invoice', $ids);
            foreach ($result['data'] as &$group) {
                foreach ($group['invoices'] as &$row) {
                    $lock = $map[(int) $row['id']] ?? null;
                    if ($lock !== null) {
                        $row['locked'] = $lock->toArray();
                    }
                }
            }
            unset($group, $row);
        }

        return Json::ok($response, $result);
    }

    private function isDate(string $v): bool
    {
        $d = \DateTimeImmutable::createFromFormat('Y-m-d', $v);
        return $d !== false && $d->format('Y-m-d') === $v;
    }
}
