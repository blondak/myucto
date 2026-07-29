<?php

declare(strict_types=1);

namespace MyInvoice\Action\Stock;

use MyInvoice\Action\Accounting\AccountingActionSupport;
use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Pdf\StockStatusPdfRenderer;
use MyInvoice\Service\Pdf\StockValuationPdfRenderer;
use MyInvoice\Service\Stock\StockException;
use MyInvoice\Service\Stock\StockReportService;
use MyInvoice\Service\Stock\StockReportXlsxExporter;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Skladové sestavy (Epic SKLAD §6, §8.1) — Stav zásob · Ocenění k datu.
 *
 *   GET /api/stock/reports/status              — data (filtry warehouse_id, item_type, below_min, active, q)
 *   GET /api/stock/reports/valuation?date=      — data k historickému datu (B8 limit-guard)
 *   GET /api/stock/reports/{name}/export        — PDF/XLSX (?format=pdf|xlsx), name = status|valuation
 */
final class StockReportAction
{
    use AccountingActionSupport;
    use GuardsStockEnabled;

    private const REPORTS = ['status', 'valuation'];

    public function __construct(
        private readonly Connection $db,
        private readonly StockReportService $service,
        private readonly StockStatusPdfRenderer $statusPdf,
        private readonly StockValuationPdfRenderer $valuationPdf,
        private readonly StockReportXlsxExporter $xlsx,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function status(Request $request, Response $response): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->guardStockEnabled($this->db, $supplierId, $response, $err)) {
            return $err;
        }
        try {
            return Json::ok($response, $this->service->status($supplierId, $this->statusFilters($request)));
        } catch (\Throwable $e) {
            return $this->mapStockError($response, $e);
        }
    }

    public function valuation(Request $request, Response $response): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->guardStockEnabled($this->db, $supplierId, $response, $err)) {
            return $err;
        }
        $q = $request->getQueryParams();
        $date = trim((string) ($q['date'] ?? (new \DateTimeImmutable())->format('Y-m-d')));
        $filters = [];
        if (!empty($q['warehouse_id'])) {
            $filters['warehouse_id'] = (int) $q['warehouse_id'];
        }
        try {
            return Json::ok($response, $this->service->valuation($supplierId, $date, $filters));
        } catch (\Throwable $e) {
            return $this->mapStockError($response, $e);
        }
    }

    public function export(Request $request, Response $response, array $args): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->guardStockEnabled($this->db, $supplierId, $response, $err)) {
            return $err;
        }
        $name = (string) ($args['name'] ?? '');
        if (!in_array($name, self::REPORTS, true)) {
            return Json::error($response, 'not_found', 'Neznámá sestava.', 404);
        }
        $format = strtolower(trim((string) ($request->getQueryParams()['format'] ?? '')));
        if (!in_array($format, ['pdf', 'xlsx'], true)) {
            return Json::error($response, 'validation_failed', "format musí být 'pdf' nebo 'xlsx'.", 422);
        }

        try {
            if ($name === 'status') {
                $data = $this->service->status($supplierId, $this->statusFilters($request));
                $out = $format === 'pdf'
                    ? ['bytes' => $this->statusPdf->render($data), 'filename' => 'stav-zasob.pdf', 'mime' => 'application/pdf']
                    : $this->xlsx->status($data);
            } else {
                $q = $request->getQueryParams();
                $date = trim((string) ($q['date'] ?? (new \DateTimeImmutable())->format('Y-m-d')));
                $filters = !empty($q['warehouse_id']) ? ['warehouse_id' => (int) $q['warehouse_id']] : [];
                $data = $this->service->valuation($supplierId, $date, $filters);
                $out = $format === 'pdf'
                    ? ['bytes' => $this->valuationPdf->render($data), 'filename' => 'oceneni-zasob-' . $date . '.pdf', 'mime' => 'application/pdf']
                    : $this->xlsx->valuation($data);
            }
        } catch (\Throwable $e) {
            return $this->mapStockError($response, $e);
        }

        $this->logger->log('report.stock_export', $this->userId($request), 'report', null,
            ['report' => $name, 'format' => $format],
            $this->ipMatcher->clientIpFromRequest($request->getServerParams()),
            $request->getHeaderLine('User-Agent'), $supplierId);

        $response->getBody()->write($out['bytes']);
        return $response
            ->withHeader('Content-Type', $out['mime'])
            ->withHeader('Content-Disposition', 'attachment; filename="' . $out['filename'] . '"')
            ->withHeader('Content-Length', (string) strlen($out['bytes']))
            ->withHeader('Cache-Control', 'private, no-store');
    }

    /** @return array<string,mixed> */
    private function statusFilters(Request $request): array
    {
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
        return $filters;
    }

    private function mapStockError(Response $response, \Throwable $e): Response
    {
        if ($e instanceof StockException) {
            return Json::error($response, 'stock.error.' . $e->errorCode, $e->getMessage(), $e->httpStatus, ['items' => $e->details]);
        }
        throw $e;
    }
}
