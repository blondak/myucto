<?php

declare(strict_types=1);

namespace MyInvoice\Action\Accounting\Reports;

use MyInvoice\Action\Accounting\AccountingActionSupport;
use MyInvoice\Http\GuardsAccountingMode;
use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\Payroll\PayrollSheetService;
use MyInvoice\Service\Accounting\Reports\ReportException;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Pdf\MzdovyListPdfRenderer;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;

/**
 * Mzdový list (§38j ZDP) — povinná roční evidence za zaměstnance/jednatele-společníka.
 *
 *   GET /api/accounting/reports/payroll-sheet?year=&employee_id=&format=pdf
 *
 * Data staví {@see PayrollSheetService} z měsíčních snapshotů uložených
 * {@see \MyInvoice\Service\Accounting\Payroll\PayrollPostingService::post()}.
 */
final class PayrollSheetAction
{
    use AccountingActionSupport;
    use GuardsAccountingMode;

    public function __construct(
        private readonly PayrollSheetService $sheet,
        private readonly MzdovyListPdfRenderer $pdf,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
        private readonly LoggerInterface $log,
        private readonly Connection $db,
    ) {}

    public function export(Request $request, Response $response): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;

        $q = $request->getQueryParams();
        $year = (int) ($q['year'] ?? 0);
        if ($year < 2018 || $year > 2100) {
            return Json::error($response, 'validation_failed', 'Neplatný rok.', 422);
        }
        $employeeId = (int) ($q['employee_id'] ?? 0);
        if ($employeeId <= 0) {
            return Json::error($response, 'validation_failed', 'employee_id je povinný.', 422);
        }
        $format = strtolower(trim((string) ($q['format'] ?? 'pdf')));
        if ($format !== 'pdf') {
            return Json::error($response, 'validation_failed', "format musí být 'pdf'.", 422);
        }

        try {
            $data = $this->sheet->build($supplierId, $employeeId, $year);
            $bytes = $this->pdf->render($data);
        } catch (ReportException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        } catch (\Throwable $e) {
            $this->log->error('Export mzdového listu selhal: ' . $e->getMessage(), ['exception' => $e]);
            return Json::error($response, 'build_failed', 'Sestavu se nepodařilo vytvořit.', 500);
        }

        $this->logger->log('report.accounting_export', $this->userId($request), 'report', $employeeId,
            ['report' => 'payroll_sheet', 'year' => $year],
            $this->ipMatcher->clientIpFromRequest($request->getServerParams()),
            $request->getHeaderLine('User-Agent'), $supplierId);

        $filename = sprintf('mzdovy-list-%d-%d.pdf', $year, $employeeId);
        $response->getBody()->write($bytes);
        return $response
            ->withHeader('Content-Type', 'application/pdf')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->withHeader('Content-Length', (string) strlen($bytes))
            ->withHeader('Cache-Control', 'private, no-store');
    }
}
