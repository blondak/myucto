<?php

declare(strict_types=1);

namespace MyInvoice\Action\Accounting\Assets;

use MyInvoice\Action\Accounting\AccountingActionSupport;
use MyInvoice\Http\GuardsAccountingMode;
use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\Assets\AccountSummaryCardService;
use MyInvoice\Service\Accounting\Assets\AssetException;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;

/**
 * Souhrnná karta majetku z účtu bez karet (portfolio pozemků vedené jen v deníku).
 *
 *   GET  /api/accounting/assets/account-summary   — účty neodpisovaného majetku se zůstatkem a stav jejich souhrnné karty
 *   POST /api/accounting/assets/account-summary   — založení / srovnání souhrnné karty s deníkem {account_code}
 */
final class AssetAccountSummaryAction
{
    use AccountingActionSupport;
    use GuardsAccountingMode;

    public function __construct(
        private readonly AccountSummaryCardService $service,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
        private readonly LoggerInterface $log,
        private readonly Connection $db,
    ) {}

    public function candidates(Request $request, Response $response): Response
    {
        $supplierId = $this->currentSupplierId($request);
        try {
            return Json::ok($response, ['items' => $this->service->candidates($supplierId)]);
        } catch (\Throwable $e) {
            $this->log->error('Souhrnné karty účtů: ' . $e->getMessage(), ['exception' => $e]);
            return Json::error($response, 'operation_failed', 'Operaci se nepodařilo dokončit.', 500);
        }
    }

    public function sync(Request $request, Response $response): Response
    {
        if (!$this->requireWrite($request, $response, $err)) return $err;
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;
        $body = (array) ($request->getParsedBody() ?? []);
        $code = trim((string) ($body['account_code'] ?? ''));
        if ($code === '') {
            return Json::error($response, 'validation_failed', 'account_code je povinný.', 422);
        }

        try {
            $result = $this->service->sync($supplierId, $code, $this->userId($request));
        } catch (AssetException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        } catch (\Throwable $e) {
            $this->log->error('Souhrnná karta účtu: ' . $e->getMessage(), ['exception' => $e]);
            return Json::error($response, 'operation_failed', 'Operaci se nepodařilo dokončit.', 500);
        }

        $this->logger->log('asset.account_summary_synced', $this->userId($request), 'asset', (int) ($result['asset']['id'] ?? 0), [
            'account_code' => $code,
            'created' => $result['created'],
            'improvements' => $result['improvements'],
        ], $this->ipMatcher->clientIpFromRequest($request->getServerParams()), $request->getHeaderLine('User-Agent'), $supplierId);
        return Json::ok($response, $result, $result['created'] ? 201 : 200);
    }
}
