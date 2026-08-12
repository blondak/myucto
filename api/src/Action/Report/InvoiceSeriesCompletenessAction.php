<?php

declare(strict_types=1);

namespace MyInvoice\Action\Report;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\Invoice\InvoiceSeriesCompletenessService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * FR3 (vendor audit 2026-08) — report úplnosti číselné řady VYDANÝCH dokladů.
 *
 *   GET /api/reports/invoice-series-completeness?year=2026
 *
 * Mezera v řadě je auditní signál pro FÚ; report jen HLÁSÍ, nic neopravuje. Dostupné
 * bez ohledu na režim účetnictví (podvojné i daňová evidence číslují doklady stejně).
 */
final class InvoiceSeriesCompletenessAction
{
    public function __construct(
        private readonly InvoiceSeriesCompletenessService $service,
    ) {}

    public function __invoke(Request $request, Response $response): Response
    {
        if (!RequestAuthorization::allows($request, 'reports', AccessLevel::READ)) {
            return Json::error($response, 'forbidden', 'Nemáš oprávnění.', 403);
        }
        $supplierId = SupplierGuard::currentId($request);

        $q = $request->getQueryParams();
        $year = (int) ($q['year'] ?? date('Y'));
        if ($year < 2000 || $year > 2100) {
            return Json::error($response, 'validation_failed', 'Neplatný rok.', 400);
        }

        $series = $this->service->build($supplierId, $year);
        $totalMissing = 0;
        foreach ($series as $s) {
            foreach ($s['buckets'] as $b) {
                // `missing` je stropovaný výčet, `missing_total` skutečný počet — souhrn
                // musí sčítat ten druhý, jinak by u useknutého období hlásil méně mezer.
                $totalMissing += $b['missing_total'];
            }
        }

        return Json::ok($response, [
            'year'          => $year,
            'series'        => $series,
            'total_missing' => $totalMissing,
        ]);
    }
}
