<?php

declare(strict_types=1);

namespace MyInvoice\Action\Payroll;

use MyInvoice\Http\Json;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\Payroll\PayrollInputApprovalException;
use MyInvoice\Repository\Payroll\PayrollInputCancellationException;
use MyInvoice\Repository\Payroll\PayrollInputConflictException;
use MyInvoice\Repository\Payroll\PayrollInputRepository;
use MyInvoice\Repository\Payroll\PayrollTimeValue;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Payroll\Component\PayrollInputPreviewService;
use MyInvoice\Service\Payroll\Component\PayrollInputValidator;
use MyInvoice\Service\Payroll\PayrollModuleAccess;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class PayrollInputsAction
{
    use PayrollActionSupport;

    public function __construct(
        private readonly PayrollInputRepository $inputs,
        private readonly PayrollInputValidator $validator,
        private readonly PayrollInputPreviewService $preview,
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
            $period = $this->period(
                $request->getQueryParams()['period'] ?? null,
            );
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 422);
        }
        return Json::ok($response, [
            'inputs' => $this->inputs->list(
                $this->currentSupplierId($request),
                $period,
            ),
        ]);
    }

    public function preview(Request $request, Response $response): Response
    {
        if (($error = $this->authorize($request, $response, AccessLevel::WRITE)) !== null) {
            return $error;
        }
        try {
            $data = $this->validator->validate($this->input($request));
            $preview = $this->preview->preview(
                $this->currentSupplierId($request),
                $data,
            );
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 422);
        }
        return Json::ok($response, ['preview' => $preview]);
    }

    public function create(Request $request, Response $response): Response
    {
        if (($error = $this->authorize($request, $response, AccessLevel::WRITE)) !== null) {
            return $error;
        }
        try {
            $input = $this->inputs->create(
                $this->currentSupplierId($request),
                $this->validator->validate($this->input($request)),
                $this->userId($request),
            );
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 422);
        }
        $this->audit($request, 'payroll.input.created', $input);
        return Json::ok($response, ['input' => $input], 201);
    }

    /** @param array<string,string> $args */
    public function update(Request $request, Response $response, array $args): Response
    {
        if (($error = $this->authorize($request, $response, AccessLevel::WRITE)) !== null) {
            return $error;
        }
        $body = $this->input($request);
        $version = $this->rowVersion($body['row_version'] ?? null);
        if ($version === null) {
            return Json::error(
                $response,
                'validation_failed',
                'row_version musí být kladné celé číslo.',
                422,
            );
        }
        unset($body['row_version']);
        try {
            $input = $this->inputs->update(
                $this->currentSupplierId($request),
                (int) ($args['id'] ?? 0),
                $this->validator->validate($body),
                $version,
            );
        } catch (\InvalidArgumentException|\DomainException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 422);
        } catch (PayrollInputConflictException $e) {
            return Json::error($response, 'row_version_conflict', $e->getMessage(), 409, [
                'current_row_version' => $e->currentVersion,
            ]);
        }
        if ($input === null) {
            return Json::error($response, 'not_found', 'Mzdový vstup nebyl nalezen.', 404);
        }
        $this->audit($request, 'payroll.input.updated', $input);
        return Json::ok($response, ['input' => $input]);
    }

    /**
     * Zrušení vlastního konceptu mzdového vstupu.
     *
     * Nulový nebo omylem založený koncept jinak zablokuje mzdový běh a jediným
     * východiskem by bylo ho schválit — čímž by se dostal na výplatní pásku.
     *
     * @param array<string,string> $args
     */
    public function cancel(Request $request, Response $response, array $args): Response
    {
        if (($error = $this->authorize($request, $response, AccessLevel::WRITE)) !== null) {
            return $error;
        }
        $version = $this->rowVersion(
            $this->input($request)['row_version'] ?? null,
        );
        if ($version === null) {
            return Json::error(
                $response,
                'validation_failed',
                'row_version musí být kladné celé číslo.',
                422,
            );
        }
        try {
            $input = $this->inputs->cancel(
                $this->currentSupplierId($request),
                (int) ($args['id'] ?? 0),
                $version,
            );
        } catch (PayrollInputCancellationException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), 409);
        } catch (PayrollInputConflictException $e) {
            return Json::error($response, 'row_version_conflict', $e->getMessage(), 409, [
                'current_row_version' => $e->currentVersion,
            ]);
        }
        if ($input === null) {
            return Json::error($response, 'not_found', 'Mzdový vstup nebyl nalezen.', 404);
        }
        $this->audit($request, 'payroll.input.cancelled', $input);
        return Json::ok($response, ['input' => $input]);
    }

    /** @param array<string,string> $args */
    public function approve(Request $request, Response $response, array $args): Response
    {
        if (($error = $this->authorize(
            $request,
            $response,
            AccessLevel::WRITE,
            'payroll.approve',
        )) !== null) {
            return $error;
        }
        $version = $this->rowVersion(
            $this->input($request)['row_version'] ?? null,
        );
        if ($version === null) {
            return Json::error(
                $response,
                'validation_failed',
                'row_version musí být kladné celé číslo.',
                422,
            );
        }
        try {
            $input = $this->inputs->approve(
                $this->currentSupplierId($request),
                (int) ($args['id'] ?? 0),
                $version,
                $this->userId($request),
            );
        } catch (PayrollInputApprovalException $e) {
            return Json::error(
                $response,
                $e->errorCode,
                $e->getMessage(),
                409,
            );
        } catch (PayrollInputConflictException $e) {
            return Json::error($response, 'row_version_conflict', $e->getMessage(), 409, [
                'current_row_version' => $e->currentVersion,
            ]);
        }
        if ($input === null) {
            return Json::error($response, 'not_found', 'Mzdový vstup nebyl nalezen.', 404);
        }
        $this->audit($request, 'payroll.input.approved', $input);
        return Json::ok($response, ['input' => $input]);
    }

    private function authorize(
        Request $request,
        Response $response,
        AccessLevel $level,
        ?string $permissionOverride = null,
    ): ?Response {
        if ($request->getAttribute(AuthMiddleware::ATTR_METHOD) === 'bearer') {
            return Json::error(
                $response,
                'session_required',
                'Tento endpoint je dostupný pouze z přihlášené relace.',
                403,
            );
        }
        $error = null;
        $permission = $permissionOverride ?? (
            $level === AccessLevel::READ
                ? 'payroll'
                : 'payroll.inputs.write'
        );
        if (!$this->requirePermission(
            $request,
            $response,
            $permission,
            $level,
            $error,
        )) {
            return $error;
        }
        if (!$this->requirePayrollEnabled($request, $response, $this->access, $error)) {
            return $error;
        }
        return null;
    }

    /** @return array<string,mixed> */
    private function input(Request $request): array
    {
        $body = $request->getParsedBody();
        return is_array($body)
            ? PayrollTimeValue::row($body, 'request_body')
            : [];
    }

    private function period(mixed $value): string
    {
        if (!is_string($value)) {
            throw new \InvalidArgumentException('period musí být měsíc YYYY-MM.');
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m', $value);
        if ($date === false || $date->format('Y-m') !== $value) {
            throw new \InvalidArgumentException('period musí být měsíc YYYY-MM.');
        }
        return $value . '-01';
    }

    private function rowVersion(mixed $value): ?int
    {
        $version = filter_var($value, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        return $version === false ? null : (int) $version;
    }

    /** @param array<string,mixed> $input */
    private function audit(Request $request, string $action, array $input): void
    {
        $supplierId = $this->currentSupplierId($request);
        $this->logger->log(
            $action,
            $this->userId($request),
            'payroll_input',
            PayrollTimeValue::int($input['id'] ?? null, 'id'),
            [
                'employee_id' => PayrollTimeValue::int(
                    $input['employee_id'] ?? null,
                    'employee_id',
                ),
                'employment_id' => PayrollTimeValue::int(
                    $input['employment_id'] ?? null,
                    'employment_id',
                ),
                'component_id' => PayrollTimeValue::int(
                    $input['component_id'] ?? null,
                    'component_id',
                ),
                'period_start' => PayrollTimeValue::string(
                    $input['period_start'] ?? null,
                    'period_start',
                ),
                'status' => PayrollTimeValue::string(
                    $input['status'] ?? null,
                    'status',
                ),
                'row_version' => PayrollTimeValue::int(
                    $input['row_version'] ?? null,
                    'row_version',
                ),
            ],
            $this->ipMatcher->clientIpFromRequest($this->serverParams($request)),
            $request->getHeaderLine('User-Agent'),
            $supplierId,
        );
    }

    /** @return array<string,mixed> */
    private function serverParams(Request $request): array
    {
        return PayrollTimeValue::row($request->getServerParams(), 'server_params');
    }
}
