<?php

declare(strict_types=1);

namespace MyInvoice\Action\Intrastat;

use MyInvoice\Action\Accounting\AccountingActionSupport;
use MyInvoice\Action\Stock\GuardsStockEnabled;
use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Service\Intrastat\InstatEvoCsvExporter;
use MyInvoice\Service\Intrastat\IntrastatInputException;
use MyInvoice\Service\Intrastat\IntrastatService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class IntrastatAction
{
    use AccountingActionSupport;
    use GuardsStockEnabled;

    public function __construct(
        private readonly Connection $db,
        private readonly IntrastatService $service,
        private readonly InstatEvoCsvExporter $csv,
    ) {}

    public function preview(Request $request, Response $response): Response
    {
        if (!$this->requirePermission($request, $response, 'stock', AccessLevel::READ, $err)) {
            return $err;
        }
        $supplierId = $this->currentSupplierId($request);
        if (!$this->guardStockEnabled($this->db, $supplierId, $response, $err)) {
            return $err;
        }

        try {
            $result = $this->service->prepare($supplierId, (array) ($request->getParsedBody() ?? []));
            return Json::ok($response, $result['preview']);
        } catch (IntrastatInputException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 422, ['issues' => $e->issues]);
        }
    }

    public function export(Request $request, Response $response): Response
    {
        if (!$this->requirePermission($request, $response, 'stock', AccessLevel::READ, $err)) {
            return $err;
        }
        $supplierId = $this->currentSupplierId($request);
        if (!$this->guardStockEnabled($this->db, $supplierId, $response, $err)) {
            return $err;
        }

        try {
            $result = $this->service->prepare($supplierId, (array) ($request->getParsedBody() ?? []));
        } catch (IntrastatInputException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 422, ['issues' => $e->issues]);
        }
        if ((int) $result['preview']['summary']['error_count'] > 0) {
            return Json::error(
                $response,
                'intrastat_validation_failed',
                'Export obsahuje chyby. Opravte je podle náhledu a export zopakujte.',
                422,
                ['preview' => $result['preview']],
            );
        }

        $bytes = $this->csv->export($result['csv_rows']);
        $response->getBody()->write($bytes);
        return $response
            ->withHeader('Content-Type', 'text/csv; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $result['filename'] . '"')
            ->withHeader('Content-Length', (string) strlen($bytes))
            ->withHeader('Cache-Control', 'private, no-store')
            ->withHeader('X-Content-Type-Options', 'nosniff');
    }
}
