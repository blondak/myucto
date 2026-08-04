<?php

declare(strict_types=1);

namespace MyInvoice\Action\Payroll;

use MyInvoice\Http\Json;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\Payroll\PayrollEmploymentConflictException;
use MyInvoice\Repository\Payroll\PayrollInputConflictException;
use MyInvoice\Repository\Payroll\PayrollQuickInputRepository;
use MyInvoice\Repository\Payroll\PayrollTimeValue;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Payroll\Component\PayrollQuickInputValidator;
use MyInvoice\Service\Payroll\PayrollModuleAccess;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class PayrollQuickInputsAction
{
    use PayrollActionSupport;

    public function __construct(
        private readonly PayrollQuickInputRepository $quickInputs,
        private readonly PayrollQuickInputValidator $validator,
        private readonly PayrollModuleAccess $access,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function list(Request $request, Response $response): Response
    {
        if (($error = $this->authorize($request, $response, AccessLevel::READ)) !== null) {
            return $error;
        }
        try {
            $period = $this->validator->period($request->getQueryParams()['period'] ?? null);
            return Json::ok($response, [
                'month' => $this->quickInputs->month($this->currentSupplierId($request), $period),
            ]);
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 422);
        }
    }

    public function save(Request $request, Response $response): Response
    {
        if (($error = $this->authorize($request, $response, AccessLevel::WRITE)) !== null) {
            return $error;
        }
        try {
            $body = $request->getParsedBody();
            $data = $this->validator->validate(
                is_array($body) ? PayrollTimeValue::row($body, 'request_body') : [],
            );
            $month = $this->quickInputs->save(
                $this->currentSupplierId($request),
                $data['period'],
                $data['rows'],
                $this->userId($request),
            );
        } catch (PayrollEmploymentConflictException $e) {
            return Json::error(
                $response,
                'employment_row_version_conflict',
                $e->getMessage(),
                409,
                ['current_row_version' => $e->currentVersion],
            );
        } catch (PayrollInputConflictException $e) {
            return Json::error($response, 'row_version_conflict', $e->getMessage(), 409, [
                'current_row_version' => $e->currentVersion,
            ]);
        } catch (\DomainException $e) {
            return Json::error($response, 'input_state_conflict', $e->getMessage(), 409);
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 422);
        }
        $this->logger->log(
            'payroll.quick_inputs.saved',
            $this->userId($request),
            'payroll_month',
            null,
            ['period' => $data['period'], 'employment_count' => count($data['rows'])],
            $this->ipMatcher->clientIpFromRequest(
                PayrollTimeValue::row($request->getServerParams(), 'server_params'),
            ),
            $request->getHeaderLine('User-Agent'),
            $this->currentSupplierId($request),
        );
        return Json::ok($response, ['month' => $month]);
    }

    private function authorize(Request $request, Response $response, AccessLevel $level): ?Response
    {
        if ($request->getAttribute(AuthMiddleware::ATTR_METHOD) === 'bearer') {
            return Json::error(
                $response,
                'session_required',
                'Tento endpoint je dostupný pouze z přihlášené relace.',
                403,
            );
        }
        $error = null;
        $permission = $level === AccessLevel::READ ? 'payroll' : 'payroll.inputs.write';
        if (!$this->requirePermission($request, $response, $permission, $level, $error)) {
            return $error;
        }
        if (!$this->requirePayrollEnabled($request, $response, $this->access, $error)) {
            return $error;
        }
        return null;
    }
}
