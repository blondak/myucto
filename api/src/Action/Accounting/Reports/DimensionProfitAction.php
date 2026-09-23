<?php

declare(strict_types=1);

namespace MyInvoice\Action\Accounting\Reports;

use MyInvoice\Action\Accounting\AccountingActionSupport;
use MyInvoice\Http\Json;
use MyInvoice\Repository\DimensionRepository;
use MyInvoice\Service\Accounting\Reports\DimensionProfitService;
use MyInvoice\Service\Accounting\Reports\FinancialStatementService;
use MyInvoice\Service\Accounting\Reports\ReportException;
use MyInvoice\Service\Accounting\Reports\ReportXlsxExporter;
use MyInvoice\Service\Automation\AutomationFeedService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Výsledovka po dimenzi.
 *
 *   GET /api/accounting/reports/dimension-profit?type_id=&from=&to=
 *       [&scope=group][&value_id=][&responsible_user_id=][&accounts=1]
 *   GET /api/accounting/reports/dimension-profit/export — totéž jako XLSX (vždy s rozpadem po účtech)
 *
 * `scope=group` u globálního typu sečte firmy skupiny ({@see DimensionGroupScope}),
 * `value_id` omezí sestavu na větev hodnoty, `responsible_user_id` na hodnoty odpovědné
 * osoby, `accounts=1` přidá rozpad po syntetických účtech.
 */
final class DimensionProfitAction
{
    use AccountingActionSupport;
    use DimensionGroupScope;

    public function __construct(
        private readonly DimensionProfitService $service,
        private readonly DimensionRepository $dimensions,
        private readonly AutomationFeedService $feed,
        private readonly ReportXlsxExporter $xlsx,
        private readonly FinancialStatementService $statements,
    ) {}

    public function __invoke(Request $request, Response $response): Response
    {
        $data = $this->report($request, $response, false, $err);
        return $data === null ? $err : Json::ok($response, $data);
    }

    public function export(Request $request, Response $response): Response
    {
        $data = $this->report($request, $response, true, $err);
        if ($data === null) {
            return $err;
        }
        $data['entity'] = $this->statements->entityHeader($this->currentSupplierId($request));
        $out = $this->xlsx->dimensionProfit($data);
        $response->getBody()->write($out['bytes']);
        return $response
            ->withHeader('Content-Type', $out['mime'])
            ->withHeader('Content-Disposition', 'attachment; filename="' . $out['filename'] . '"')
            ->withHeader('Content-Length', (string) strlen($out['bytes']))
            ->withHeader('Cache-Control', 'private, no-store');
    }

    /** @return array<string,mixed>|null */
    private function report(Request $request, Response $response, bool $forceAccounts, ?Response &$err): ?array
    {
        $err = null;
        $supplierId = $this->currentSupplierId($request);
        $q = $request->getQueryParams();
        $typeId = (int) ($q['type_id'] ?? 0);
        $from = (string) ($q['from'] ?? '');
        $to = (string) ($q['to'] ?? '');
        if ($typeId <= 0 || !self::isReportDate($from) || !self::isReportDate($to) || $from > $to) {
            $err = Json::error($response, 'validation_failed', 'type_id, from a to (YYYY-MM-DD, from ≤ to) jsou povinné.', 422);
            return null;
        }
        $type = $this->dimensions->findType($supplierId, $typeId);
        if ($type === null) {
            $err = Json::error($response, 'not_found', 'Typ dimenze nenalezen.', 404);
            return null;
        }
        [$supplierIds, $hidden] = $this->groupScopeSuppliers($request, $this->dimensions, $this->feed, $supplierId, $type);

        $options = [
            'value_id' => (int) ($q['value_id'] ?? 0) ?: null,
            'responsible_user_id' => (int) ($q['responsible_user_id'] ?? 0) ?: null,
            'accounts' => $forceAccounts || (string) ($q['accounts'] ?? '') === '1',
        ];
        if ($options['value_id'] !== null) {
            $value = $this->dimensions->findValue($supplierId, $options['value_id']);
            if ($value === null || (int) $value['type_id'] !== $typeId) {
                $err = Json::error($response, 'not_found', 'Hodnota dimenze nenalezena.', 404);
                return null;
            }
        }
        try {
            $data = $this->service->build($supplierId, $typeId, $from, $to, $supplierIds, $options);
        } catch (ReportException $e) {
            $err = Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
            return null;
        }
        $data['hidden_companies'] = $hidden;
        return $data;
    }
}
