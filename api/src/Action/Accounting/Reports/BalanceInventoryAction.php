<?php

declare(strict_types=1);

namespace MyInvoice\Action\Accounting\Reports;

use MyInvoice\Action\Accounting\AccountingActionSupport;
use MyInvoice\Http\GuardsAccountingMode;
use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Service\Accounting\Reports\BalanceInventoryService;
use MyInvoice\Service\Accounting\Reports\ReportException;
use MyInvoice\Service\Accounting\Reports\ReportXlsxExporter;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Pdf\BalanceInventoryPdfRenderer;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;

/**
 * Inventarizace rozvahových účtů (§29–30 ZoÚ) — REAL_data_followup_UX.md T2, vzor {@see TrialBalanceAction}.
 *
 *   GET /api/accounting/reports/balance-inventory        — data sestavy (?period_id=)
 *   GET /api/accounting/reports/balance-inventory/export — PDF / XLSX (?period_id=&format=pdf|xlsx)
 *
 * Vždy k celému období (rozvahový den = ends_on) — na rozdíl od F2 sestav se tu nedává
 * volitelný from/to rozsah, protože inventarizace je vázaná na konec účetního období.
 */
final class BalanceInventoryAction
{
    use AccountingActionSupport;
    use GuardsAccountingMode;

    public function __construct(
        private readonly BalanceInventoryService $inventory,
        private readonly AccountingPeriodRepository $periods,
        private readonly BalanceInventoryPdfRenderer $pdf,
        private readonly ReportXlsxExporter $xlsx,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
        private readonly LoggerInterface $log,
        private readonly Connection $db,
    ) {}

    public function get(Request $request, Response $response): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;
        $periodId = $this->periodId($request, $response, $supplierId, $err);
        if ($periodId === null) return $err;

        try {
            $data = $this->inventory->build($supplierId, $periodId);
        } catch (ReportException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        } catch (\Throwable $e) {
            $this->log->error('Inventarizaci účtů se nepodařilo sestavit: ' . $e->getMessage(), ['exception' => $e]);
            return Json::error($response, 'build_failed', 'Sestavu se nepodařilo vytvořit.', 500);
        }

        return Json::ok($response, $data);
    }

    public function export(Request $request, Response $response): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;
        $periodId = $this->periodId($request, $response, $supplierId, $err);
        if ($periodId === null) return $err;

        $format = strtolower(trim((string) ($request->getQueryParams()['format'] ?? '')));
        if (!in_array($format, ['pdf', 'xlsx'], true)) {
            return Json::error($response, 'validation_failed', "format musí být 'pdf' nebo 'xlsx'.", 422);
        }

        try {
            $data = $this->inventory->build($supplierId, $periodId);
            $out = $format === 'pdf'
                ? [
                    'bytes'    => $this->pdf->render($data),
                    'filename' => sprintf('inventarizace-uctu-%s.pdf', (string) ($data['period']['fiscal_year'] ?? '')),
                    'mime'     => 'application/pdf',
                ]
                : $this->xlsx->balanceInventory($data);
        } catch (ReportException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        } catch (\Throwable $e) {
            $this->log->error('Export inventarizace účtů selhal: ' . $e->getMessage(), ['exception' => $e]);
            return Json::error($response, 'build_failed', 'Sestavu se nepodařilo vytvořit.', 500);
        }

        $this->logger->log('report.accounting_export', $this->userId($request), 'report', null,
            ['report' => 'balance_inventory', 'format' => $format],
            $this->ipMatcher->clientIpFromRequest($request->getServerParams()),
            $request->getHeaderLine('User-Agent'), $supplierId);

        $response->getBody()->write($out['bytes']);
        return $response
            ->withHeader('Content-Type', $out['mime'])
            ->withHeader('Content-Disposition', 'attachment; filename="' . $out['filename'] . '"')
            ->withHeader('Content-Length', (string) strlen($out['bytes']))
            ->withHeader('Cache-Control', 'private, no-store');
    }

    private function periodId(Request $request, Response $response, int $supplierId, ?Response &$err): ?int
    {
        $periodId = (int) ($request->getQueryParams()['period_id'] ?? 0);
        if ($periodId <= 0) {
            $err = Json::error($response, 'validation_failed', 'period_id je povinný.', 422);
            return null;
        }
        if ($this->periods->findById($supplierId, $periodId) === null) {
            $err = Json::error($response, 'not_found', 'Účetní období nenalezeno.', 404);
            return null;
        }
        $err = null;
        return $periodId;
    }
}
