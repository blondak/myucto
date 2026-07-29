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
use MyInvoice\Service\Accounting\Reports\SaldoService;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Pdf\SaldoPdfRenderer;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;

/**
 * Saldokonto (audit 2026-07, nález H13 — fáze D6/1): otevřené položky
 * účtů pohledávek/závazků per partner + konfrontace se zůstatkem účtu z deníku.
 *
 *   GET /api/accounting/reports/saldo         — data sestavy
 *   GET /api/accounting/reports/saldo/export  — PDF / XLSX (?format=pdf|xlsx)
 */
final class SaldoAction
{
    use AccountingActionSupport;
    use GuardsAccountingMode;

    public function __construct(
        private readonly SaldoService $saldo,
        private readonly AccountingPeriodRepository $periods,
        private readonly SaldoPdfRenderer $pdf,
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
            $data = $this->saldo->build($supplierId, $params['period_id'], $params['as_of'], $params['account'], $params['partner_id']);
        } catch (ReportException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        } catch (\Throwable $e) {
            $this->log->error('Saldokonto se nepodařilo sestavit: ' . $e->getMessage(), ['exception' => $e]);
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
            $data = $this->saldo->build($supplierId, $params['period_id'], $params['as_of'], $params['account'], $params['partner_id']);
            $out = $format === 'pdf'
                ? [
                    'bytes'    => $this->pdf->render($data),
                    'filename' => sprintf('saldokonto-%s.pdf', (string) ($data['as_of'] ?? '')),
                    'mime'     => 'application/pdf',
                ]
                : $this->xlsx->saldo($data);
        } catch (ReportException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        } catch (\Throwable $e) {
            $this->log->error('Saldokonto se nepodařilo sestavit: ' . $e->getMessage(), ['exception' => $e]);
            return Json::error($response, 'build_failed', 'Sestavu se nepodařilo vytvořit.', 500);
        }

        $this->logger->log('report.accounting_export', $this->userId($request), 'report', null,
            ['report' => 'saldo', 'format' => $format],
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
     * @return array{period_id:int, as_of:?string, account:?string, partner_id:?int}|null
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

        $asOf = trim((string) ($q['as_of'] ?? ''));
        if ($asOf !== '') {
            if (!$this->isDate($asOf)) {
                $err = Json::error($response, 'validation_failed', 'as_of musí být datum (YYYY-MM-DD).', 422);
                return null;
            }
            if ($asOf < (string) $period['starts_on'] || $asOf > (string) $period['ends_on']) {
                $err = Json::error($response, 'validation_failed', 'as_of musí ležet uvnitř zvoleného období.', 422);
                return null;
            }
        }

        $account = trim((string) ($q['account'] ?? 'all'));
        if ($account === '') $account = 'all';
        if ($account !== 'all' && !preg_match('/^[0-9]{3,}$/', $account)) {
            $err = Json::error($response, 'validation_failed', "account musí být kód účtu (např. 311) nebo 'all'.", 422);
            return null;
        }

        $partnerId = (int) ($q['partner_id'] ?? 0);

        $err = null;
        return [
            'period_id'  => $periodId,
            'as_of'      => $asOf === '' ? null : $asOf,
            'account'    => $account,
            'partner_id' => $partnerId > 0 ? $partnerId : null,
        ];
    }

    private function isDate(string $v): bool
    {
        $d = \DateTimeImmutable::createFromFormat('Y-m-d', $v);
        return $d !== false && $d->format('Y-m-d') === $v;
    }
}
