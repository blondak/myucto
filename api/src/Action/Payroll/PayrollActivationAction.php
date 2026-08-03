<?php

declare(strict_types=1);

namespace MyInvoice\Action\Payroll;

use MyInvoice\Http\Json;
use MyInvoice\Repository\Payroll\PayrollModuleStateRepository;
use MyInvoice\Repository\Payroll\PayrollStateConflictException;
use MyInvoice\Repository\Payroll\PayrollStateLockedException;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Payroll\PayrollModuleAccess;
use MyInvoice\Service\Payroll\SupportMatrix;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class PayrollActivationAction
{
    use PayrollActionSupport;

    public function __construct(
        private readonly PayrollModuleStateRepository $state,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
        private readonly SupportMatrix $supportMatrix,
        private readonly PayrollModuleAccess $access,
    ) {}

    public function get(Request $request, Response $response): Response
    {
        if (!$this->requirePermission($request, $response, 'payroll.settings', AccessLevel::READ, $error)) {
            return $error;
        }
        if (!$this->requirePayrollEnabled($request, $response, $this->access, $error)) {
            return $error;
        }

        return Json::ok($response, ['state' => $this->state->get($this->currentSupplierId($request))]);
    }

    public function put(Request $request, Response $response): Response
    {
        if (!$this->requirePermission($request, $response, 'payroll.settings', AccessLevel::WRITE, $error)) {
            return $error;
        }
        if (!$this->requirePayrollEnabled($request, $response, $this->access, $error)) {
            return $error;
        }

        $body = (array) ($request->getParsedBody() ?? []);
        if (!array_key_exists('enabled', $body) || !array_key_exists('row_version', $body)) {
            return Json::error($response, 'validation_failed', 'Chybí enabled nebo row_version.', 422);
        }
        $enabled = filter_var($body['enabled'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($enabled === null) {
            return Json::error($response, 'validation_failed', 'enabled musí být boolean.', 422);
        }
        $startPeriod = $enabled ? trim((string) ($body['start_period'] ?? '')) : null;
        if ($enabled && !$this->validPeriod($startPeriod)) {
            return Json::error(
                $response,
                'validation_failed',
                'Počáteční období musí mít formát YYYY-MM a podporovaný legislativní rok.',
                422,
            );
        }
        $version = filter_var($body['row_version'], FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 0],
        ]);
        if ($version === false) {
            return Json::error($response, 'validation_failed', 'row_version musí být nezáporné celé číslo.', 422);
        }

        $supplierId = $this->currentSupplierId($request);
        try {
            $state = $this->state->setActivation(
                $supplierId,
                $enabled,
                $startPeriod === null ? null : $startPeriod . '-01',
                (int) $version,
                $this->userId($request),
            );
        } catch (PayrollStateConflictException $e) {
            return Json::error($response, 'row_version_conflict', $e->getMessage(), 409, [
                'current_row_version' => $e->currentVersion,
            ]);
        } catch (PayrollStateLockedException $e) {
            return Json::error($response, 'payroll_state_locked', $e->getMessage(), 409);
        }

        $this->logger->log(
            $enabled ? 'payroll.activation.enabled' : 'payroll.activation.disabled',
            $this->userId($request),
            'payroll_module_state',
            $supplierId,
            ['status' => $state['status'], 'start_period' => $state['start_period']],
            $this->ipMatcher->clientIpFromRequest($request->getServerParams()),
            $request->getHeaderLine('User-Agent'),
            $supplierId,
        );

        return Json::ok($response, ['state' => $state]);
    }

    private function validPeriod(?string $period): bool
    {
        if ($period === null || !preg_match('/^([0-9]{4})-(0[1-9]|1[0-2])$/', $period, $matches)) {
            return false;
        }
        return $this->supportMatrix->supportsYear((int) $matches[1]);
    }
}
