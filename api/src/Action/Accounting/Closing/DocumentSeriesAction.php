<?php

declare(strict_types=1);

namespace MyInvoice\Action\Accounting\Closing;

use MyInvoice\Action\Accounting\AccountingActionSupport;
use MyInvoice\Http\GuardsAccountingMode;
use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\Closing\ClosingException;
use MyInvoice\Service\Accounting\Closing\DocumentSeriesService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Číselné řady účetního deníku (Epic F4, R13) — UZ/OT/KR/PP/ID, pokladní,
 * skladové a další řady per firma a rok. Řádek řady vzniká lazy při prvním
 * výdeji čísla; tady se čte a edituje prefix, tvar čísla i čítač.
 *
 *   GET /api/accounting/document-series               — seznam řad firmy
 *   PUT /api/accounting/document-series/{code}/{year} — prefix / number_format / next_number — účetní|admin
 *
 * Body PUT (aspoň jedna položka):
 *   { "prefix": "26HP", "number_format": "{PREFIX}{CCCCC}", "next_number": 11 }
 *
 * `number_format` = "" nebo null vrátí řadu na vestavěné `{PREFIX}-{YYYY}-{CCCC}`,
 * `next_number` je číslo PŘÍŠTÍHO vydaného dokladu (#22 — převzetí řady z jiného
 * systému; stejná sémantika jako PUT /settings/supplier/invoice-counter).
 */
final class DocumentSeriesAction
{
    use AccountingActionSupport;
    use GuardsAccountingMode;

    /** Editovatelné jsou všechny řady, které umí vydat číslo. */
    private const SERIES_CODES = DocumentSeriesService::DEFAULT_PREFIXES;

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

    public function update(Request $request, Response $response, array $args): Response
    {
        if (!$this->requireWrite($request, $response, $err)) return $err;
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;

        $code = (string) ($args['code'] ?? '');
        if (!isset(self::SERIES_CODES[$code])) {
            return Json::error($response, 'validation_failed',
                'Neznámá řada — povolené: ' . implode(', ', array_keys(self::SERIES_CODES)) . '.', 422);
        }
        $year = (int) ($args['year'] ?? 0);
        if ($year < 2000 || $year > 2200) {
            return Json::error($response, 'validation_failed', 'Rok řady musí být rozumný účetní rok.', 422);
        }

        $body    = (array) ($request->getParsedBody() ?? []);
        $changes = [];

        if (array_key_exists('prefix', $body)) {
            $changes['prefix'] = strtoupper(trim((string) $body['prefix']));
        }
        if (array_key_exists('number_format', $body)) {
            $changes['number_format'] = $body['number_format'];
        }
        if (array_key_exists('next_number', $body) && trim((string) $body['next_number']) !== '') {
            if (!is_numeric($body['next_number'])) {
                return Json::error($response, 'validation_failed', 'next_number musí být celé číslo.', 422);
            }
            $changes['next_number'] = (int) $body['next_number'];
        }
        if ($changes === []) {
            return Json::error($response, 'validation_failed',
                'Zadej aspoň jedno z: prefix, number_format, next_number.', 422);
        }

        try {
            $this->series->updateSeries($supplierId, $code, $year, $changes);
        } catch (ClosingException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        }

        return Json::ok($response, $this->series->list($supplierId));
    }
}
