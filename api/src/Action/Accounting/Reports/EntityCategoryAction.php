<?php

declare(strict_types=1);

namespace MyInvoice\Action\Accounting\Reports;

use MyInvoice\Action\Accounting\AccountingActionSupport;
use MyInvoice\Http\GuardsAccountingMode;
use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Service\Accounting\Reports\EntityCategoryService;
use MyInvoice\Service\Accounting\Reports\ReportException;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;

/**
 * Kategorizace účetní jednotky dle §1b–1d ZoÚ (Epic F2, R9–R11) — kritéria
 * (aktiva netto, čistý obrat, zaměstnanci), prahy a odvozený rozsah výkazů.
 *
 *   GET /api/accounting/reports/entity-category?period_id=…
 */
final class EntityCategoryAction
{
    use AccountingActionSupport;
    use GuardsAccountingMode;

    public function __construct(
        private readonly EntityCategoryService $categories,
        private readonly AccountingPeriodRepository $periods,
        private readonly IpMatcher $ipMatcher,
        private readonly LoggerInterface $log,
        private readonly Connection $db,
    ) {}

    public function get(Request $request, Response $response): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;

        $periodId = (int) ($request->getQueryParams()['period_id'] ?? 0);
        if ($periodId <= 0) {
            return Json::error($response, 'validation_failed', 'period_id je povinný.', 422);
        }
        if ($this->periods->findById($supplierId, $periodId) === null) {
            return Json::error($response, 'not_found', 'Účetní období nenalezeno.', 404);
        }

        try {
            $data = $this->categories->evaluate($supplierId, $periodId);
        } catch (ReportException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        } catch (\Throwable $e) {
            $this->log->error('Účetní sestavu se nepodařilo sestavit: ' . $e->getMessage(), ['exception' => $e]);
            return Json::error($response, 'build_failed', 'Sestavu se nepodařilo vytvořit.', 500);
        }

        return Json::ok($response, $data);
    }
}
