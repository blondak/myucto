<?php

declare(strict_types=1);

namespace MyInvoice\Action\Accounting\Closing;

use MyInvoice\Action\Accounting\AccountingActionSupport;
use MyInvoice\Http\GuardsAccountingMode;
use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\Closing\DocumentSeriesService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Číselné řady účetního deníku (Epic F4, R13) — UZ/OT/KR/PP/ID per firma a rok.
 * Řádek řady vzniká lazy při prvním výdeji čísla; tady se jen čte a edituje prefix.
 *
 *   GET /api/accounting/document-series               — seznam řad firmy
 *   PUT /api/accounting/document-series/{code}/{year} — změna prefixu — účetní|admin
 */
final class DocumentSeriesAction
{
    use AccountingActionSupport;
    use GuardsAccountingMode;

    private const SERIES_CODES = ['closing', 'opening', 'fx', 'transfer', 'manual'];

    public function __construct(
        private readonly DocumentSeriesService $series,
        private readonly Connection $db,
    ) {}

    public function list(Request $request, Response $response): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;
        return Json::ok($response, $this->series->list($supplierId));
    }

    public function updatePrefix(Request $request, Response $response, array $args): Response
    {
        if (!$this->requireWrite($request, $response, $err)) return $err;
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;

        $code = (string) ($args['code'] ?? '');
        if (!in_array($code, self::SERIES_CODES, true)) {
            return Json::error($response, 'validation_failed',
                'Neznámá řada — povolené: ' . implode(', ', self::SERIES_CODES) . '.', 422);
        }
        $year = (int) ($args['year'] ?? 0);
        if ($year < 2000 || $year > 2200) {
            return Json::error($response, 'validation_failed', 'Rok řady musí být rozumný účetní rok.', 422);
        }

        $body = (array) ($request->getParsedBody() ?? []);
        $prefix = strtoupper(trim((string) ($body['prefix'] ?? '')));
        if (!preg_match('/^[A-Z0-9]{1,10}$/', $prefix)) {
            return Json::error($response, 'validation_failed', 'prefix musí být 1–10 znaků A–Z / 0–9.', 422);
        }

        if (!$this->series->updatePrefix($supplierId, $code, $year, $prefix)) {
            return Json::error($response, 'not_found',
                'Řada pro daný rok zatím neexistuje — vznikne při prvním výdeji čísla.', 404);
        }

        return Json::ok($response, $this->series->list($supplierId));
    }
}
