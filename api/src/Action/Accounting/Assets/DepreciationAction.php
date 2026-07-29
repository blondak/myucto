<?php

declare(strict_types=1);

namespace MyInvoice\Action\Accounting\Assets;

use MyInvoice\Action\Accounting\AccountingActionSupport;
use MyInvoice\Http\GuardsAccountingMode;
use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Service\Accounting\Assets\AssetException;
use MyInvoice\Service\Accounting\Assets\AssetService;
use MyInvoice\Service\Accounting\Assets\DepreciationPostingService;
use MyInvoice\Service\Accounting\PostingException;
use MyInvoice\Service\Accounting\UnbalancedEntryException;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * Odpisy majetku (Epic F3) — plán on-the-fly (R11), roční book (R12), přerušení §26/8 (R14).
 *
 *   GET    /api/accounting/assets/{id}/depreciation-plan            — plán tax+acc (minulost potvrzená, budoucnost dopočtená)
 *   POST   /api/accounting/assets/depreciations/book                — hromadné potvrzení + zaúčtování roku — účetní|admin
 *   POST   /api/accounting/assets/{id}/depreciation/pause           — přerušení daňového odpisu roku — účetní|admin
 *   DELETE /api/accounting/assets/{id}/depreciation/pause/{year}    — zrušení přerušení — účetní|admin
 */
final class DepreciationAction
{
    use AccountingActionSupport;
    use GuardsAccountingMode;

    public function __construct(
        private readonly AssetService $service,
        private readonly DepreciationPostingService $posting,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
        private readonly LoggerInterface $log,
        private readonly Connection $db,
        private readonly AccountingPeriodRepository $periods,
        private readonly ClockInterface $clock,
    ) {}

    public function plan(Request $request, Response $response, array $args): Response
    {
        $supplierId = $this->currentSupplierId($request);
        $id = (int) ($args['id'] ?? 0);

        try {
            $data = $this->service->plan($supplierId, $id);
        } catch (AssetException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        } catch (\Throwable $e) {
            return $this->serverError($response, $e, 'Plán odpisů se nepodařilo sestavit');
        }

        return Json::ok($response, $data);
    }

    public function bookYear(Request $request, Response $response): Response
    {
        if (!$this->requireWrite($request, $response, $err)) return $err;
        $supplierId = $this->currentSupplierId($request);
        $body = (array) ($request->getParsedBody() ?? []);

        $fiscalYear = (int) ($body['fiscal_year'] ?? 0);
        if ($fiscalYear < 2000 || $fiscalYear > 2100) {
            return Json::error($response, 'validation_failed', 'fiscal_year je povinný (celé číslo 2000–2100).', 422);
        }
        $period = $this->periods->findByYear($supplierId, $fiscalYear);
        $periodEnd = $period['ends_on'] ?? ($fiscalYear . '-12-31');
        $today = $this->clock->now()->format('Y-m-d');
        if ((string) $periodEnd > $today) {
            return Json::error(
                $response,
                'period_not_ended',
                'Odpisy lze zaúčtovat až po skončení období (' . $periodEnd . ').',
                409,
            );
        }

        try {
            $result = $this->posting->bookYear($supplierId, $fiscalYear, $this->auditMeta($request));
        } catch (AssetException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        } catch (UnbalancedEntryException | PostingException $e) {
            return $this->mapPostingError($response, $e);
        } catch (\Throwable $e) {
            return $this->serverError($response, $e, 'Zaúčtování odpisů roku selhalo');
        }

        $this->logEvent($request, 'asset.depreciation_booked', null, [
            'fiscal_year' => $fiscalYear,
            'booked'      => (int) ($result['booked'] ?? 0),
        ]);
        return Json::ok($response, $result);
    }

    public function pause(Request $request, Response $response, array $args): Response
    {
        if (!$this->requireWrite($request, $response, $err)) return $err;
        $supplierId = $this->currentSupplierId($request);
        $id = (int) ($args['id'] ?? 0);
        $body = (array) ($request->getParsedBody() ?? []);

        $fiscalYear = (int) ($body['fiscal_year'] ?? 0);
        if ($fiscalYear < 2000 || $fiscalYear > 2100) {
            return Json::error($response, 'validation_failed', 'fiscal_year je povinný (celé číslo 2000–2100).', 422);
        }

        try {
            $this->service->pauseYear($supplierId, $id, $fiscalYear);
        } catch (AssetException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        } catch (\Throwable $e) {
            return $this->serverError($response, $e, 'Přerušení odpisu se nepodařilo uložit');
        }

        $this->logEvent($request, 'asset.depreciation_paused', $id, ['fiscal_year' => $fiscalYear]);
        return Json::ok($response, ['paused' => true, 'fiscal_year' => $fiscalYear]);
    }

    public function unpause(Request $request, Response $response, array $args): Response
    {
        if (!$this->requireWrite($request, $response, $err)) return $err;
        $supplierId = $this->currentSupplierId($request);
        $id = (int) ($args['id'] ?? 0);
        $fiscalYear = (int) ($args['year'] ?? 0);

        try {
            $this->service->unpauseYear($supplierId, $id, $fiscalYear);
        } catch (AssetException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        } catch (\Throwable $e) {
            return $this->serverError($response, $e, 'Zrušení přerušení odpisu selhalo');
        }

        return Json::ok($response, ['deleted' => true, 'fiscal_year' => $fiscalYear]);
    }

    private function serverError(Response $response, \Throwable $e, string $logPrefix): Response
    {
        $this->log->error($logPrefix . ': ' . $e->getMessage(), ['exception' => $e]);
        return Json::error($response, 'operation_failed', 'Operaci se nepodařilo dokončit.', 500);
    }

    private function logEvent(Request $request, string $action, ?int $entityId, array $payload): void
    {
        $this->logger->log($action, $this->userId($request), 'asset', $entityId, $payload,
            $this->ipMatcher->clientIpFromRequest($request->getServerParams()),
            $request->getHeaderLine('User-Agent'), $this->currentSupplierId($request));
    }
}
