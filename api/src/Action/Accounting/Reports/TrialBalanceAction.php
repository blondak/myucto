<?php

declare(strict_types=1);

namespace MyInvoice\Action\Accounting\Reports;

use MyInvoice\Action\Accounting\AccountingActionSupport;
use MyInvoice\Http\GuardsAccountingMode;
use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Service\Accounting\Reports\ReportException;
use MyInvoice\Service\Accounting\Reports\ReportXlsxExporter;
use MyInvoice\Service\Accounting\Reports\TrialBalanceService;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Pdf\TrialBalancePdfRenderer;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;

/**
 * Obratová předvaha (Epic F2) — PS/obraty/KS + kontrolní vazby na obrat deníku.
 *
 *   GET /api/accounting/reports/trial-balance        — data sestavy
 *   GET /api/accounting/reports/trial-balance/export — PDF / XLSX (?format=pdf|xlsx)
 */
final class TrialBalanceAction
{
    use AccountingActionSupport;
    use GuardsAccountingMode;

    public function __construct(
        private readonly TrialBalanceService $trialBalance,
        private readonly AccountingPeriodRepository $periods,
        private readonly TrialBalancePdfRenderer $pdf,
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
        $params = $this->validateParams($request, $response, $supplierId, $err);
        if ($params === null) return $err;

        try {
            $data = $this->trialBalance->build($supplierId, $params['period_id'], $params['from'], $params['to'], $params['analytics'], $params['after_closing']);
        } catch (ReportException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        } catch (\Throwable $e) {
            $this->log->error('Účetní sestavu se nepodařilo sestavit: ' . $e->getMessage(), ['exception' => $e]);
            return Json::error($response, 'build_failed', 'Sestavu se nepodařilo vytvořit.', 500);
        }

        return Json::ok($response, $data);
    }

    public function export(Request $request, Response $response): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;
        $params = $this->validateParams($request, $response, $supplierId, $err);
        if ($params === null) return $err;

        $format = strtolower(trim((string) ($request->getQueryParams()['format'] ?? '')));
        if (!in_array($format, ['pdf', 'xlsx'], true)) {
            return Json::error($response, 'validation_failed', "format musí být 'pdf' nebo 'xlsx'.", 422);
        }

        try {
            $data = $this->trialBalance->build($supplierId, $params['period_id'], $params['from'], $params['to'], $params['analytics'], $params['after_closing']);
            $out = $format === 'pdf'
                ? [
                    'bytes'    => $this->pdf->render($data),
                    'filename' => sprintf('obratova-predvaha-%s.pdf', (string) ($data['period']['fiscal_year'] ?? '')),
                    'mime'     => 'application/pdf',
                ]
                : $this->xlsx->trialBalance($data);
        } catch (ReportException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        } catch (\Throwable $e) {
            $this->log->error('Účetní sestavu se nepodařilo sestavit: ' . $e->getMessage(), ['exception' => $e]);
            return Json::error($response, 'build_failed', 'Sestavu se nepodařilo vytvořit.', 500);
        }

        $this->logger->log('report.accounting_export', $this->userId($request), 'report', null,
            ['report' => 'trial_balance', 'format' => $format],
            $this->ipMatcher->clientIpFromRequest($request->getServerParams()),
            $request->getHeaderLine('User-Agent'), $supplierId);

        $response->getBody()->write($out['bytes']);
        return $response
            ->withHeader('Content-Type', $out['mime'])
            ->withHeader('Content-Disposition', 'attachment; filename="' . $out['filename'] . '"')
            ->withHeader('Content-Length', (string) strlen($out['bytes']))
            ->withHeader('Cache-Control', 'private, no-store');
    }

    /**
     * @return array{period_id:int, from:?string, to:?string, analytics:bool}|null
     */
    private function validateParams(Request $request, Response $response, int $supplierId, ?Response &$err): ?array
    {
        $q = $request->getQueryParams();

        $periodId = (int) ($q['period_id'] ?? 0);
        if ($periodId <= 0) {
            $err = Json::error($response, 'validation_failed', 'period_id je povinný.', 422);
            return null;
        }
        $period = $this->periods->findById($supplierId, $periodId);
        if ($period === null) {
            $err = Json::error($response, 'not_found', 'Účetní období nenalezeno.', 404);
            return null;
        }

        $range = [];
        foreach (['from', 'to'] as $key) {
            $range[$key] = null;
            $v = trim((string) ($q[$key] ?? ''));
            if ($v === '') continue;
            if (!$this->isDate($v)) {
                $err = Json::error($response, 'validation_failed', "{$key} musí být datum (YYYY-MM-DD).", 422);
                return null;
            }
            if ($v < (string) $period['starts_on'] || $v > (string) $period['ends_on']) {
                $err = Json::error($response, 'validation_failed', "{$key} musí ležet uvnitř zvoleného období.", 422);
                return null;
            }
            $range[$key] = $v;
        }
        if ($range['from'] !== null && $range['to'] !== null && $range['from'] > $range['to']) {
            $err = Json::error($response, 'validation_failed', 'from nesmí být větší než to.', 422);
            return null;
        }

        $err = null;
        return [
            'period_id' => $periodId,
            'from'      => $range['from'],
            'to'        => $range['to'],
            'analytics' => (string) ($q['analytics'] ?? '') === '1',
            // Výchozí je stav PŘED uzavřením knih — po uzavření jsou rozvahové účty
            // vynulované a účetní by k rozvahovému dni neviděla žádné zůstatky.
            'after_closing' => (string) ($q['after_closing'] ?? '') === '1',
        ];
    }

    private function isDate(string $v): bool
    {
        $d = \DateTimeImmutable::createFromFormat('Y-m-d', $v);
        return $d !== false && $d->format('Y-m-d') === $v;
    }
}
