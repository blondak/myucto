<?php

declare(strict_types=1);

namespace MyInvoice\Action\Payroll;

use MyInvoice\Http\Json;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\Payroll\PayrollDeductionAgreementConflictException;
use MyInvoice\Repository\Payroll\PayrollDeductionAgreementRepository;
use MyInvoice\Repository\Payroll\PayrollTimeValue;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Payroll\Net\DeductionAgreementCommand;
use MyInvoice\Service\Payroll\Net\DeductionAgreementStatus;
use MyInvoice\Service\Payroll\Net\DeductionAgreementTerms;
use MyInvoice\Service\Payroll\PayrollModuleAccess;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class PayrollDeductionAgreementAction
{
    use PayrollActionSupport;

    public function __construct(
        private readonly PayrollDeductionAgreementRepository $repository,
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
            $query = $request->getQueryParams();
            $employeeId = $this->optionalPositiveInt($query['employee_id'] ?? null, 'employee_id');
            $status = isset($query['status']) && $query['status'] !== ''
                ? DeductionAgreementStatus::from(
                    PayrollTimeValue::string($query['status'], 'status'),
                )
                : null;
        } catch (\ValueError|\InvalidArgumentException|\UnexpectedValueException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 422);
        }

        return Json::ok($response, [
            'agreements' => $this->repository->listAgreements(
                $this->currentSupplierId($request),
                $employeeId,
                $status,
            ),
        ]);
    }

    /** @param array{id:string} $args */
    public function detail(Request $request, Response $response, array $args): Response
    {
        if (($error = $this->authorize($request, $response, AccessLevel::READ)) !== null) {
            return $error;
        }
        $agreement = $this->repository->find(
            $this->currentSupplierId($request),
            (int) $args['id'],
        );

        return $agreement === null
            ? Json::error($response, 'not_found', 'Dohoda o srážce nebyla nalezena.', 404)
            : Json::ok($response, ['agreement' => $agreement]);
    }

    public function create(Request $request, Response $response): Response
    {
        if (($error = $this->authorize($request, $response, AccessLevel::WRITE)) !== null) {
            return $error;
        }
        try {
            $body = $this->input($request);
            $status = DeductionAgreementStatus::from(
                is_string($body['status'] ?? null) && $body['status'] !== ''
                    ? $body['status']
                    : 'draft',
            );
            $agreement = $this->repository->create(
                $this->currentSupplierId($request),
                $this->positiveInt($body['employee_id'] ?? null, 'employee_id'),
                DeductionAgreementTerms::fromRequest($body),
                $status,
                $this->userId($request),
            );
            $this->audit($request, 'payroll.deduction_agreement.created', $agreement);
        } catch (\ValueError|\InvalidArgumentException|\UnexpectedValueException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 422);
        } catch (\OutOfBoundsException $e) {
            return Json::error($response, 'not_found', $e->getMessage(), 404);
        } catch (\DomainException $e) {
            return Json::error($response, 'invalid_agreement', $e->getMessage(), 409);
        }

        return Json::ok($response, ['agreement' => $agreement], 201);
    }

    /** @param array{id:string} $args */
    public function update(Request $request, Response $response, array $args): Response
    {
        if (($error = $this->authorize($request, $response, AccessLevel::WRITE)) !== null) {
            return $error;
        }
        try {
            $body = $this->input($request);
            $agreement = $this->repository->update(
                $this->currentSupplierId($request),
                (int) $args['id'],
                DeductionAgreementTerms::fromRequest($body),
                $this->positiveInt($body['row_version'] ?? null, 'row_version'),
                $this->optionalDate($body['effective_from'] ?? null, 'effective_from'),
                $this->optionalText($body['reason'] ?? null, 'reason'),
                $this->userId($request),
            );
            $this->audit($request, 'payroll.deduction_agreement.updated', $agreement);
        } catch (\ValueError|\InvalidArgumentException|\UnexpectedValueException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 422);
        } catch (PayrollDeductionAgreementConflictException $e) {
            return Json::error($response, 'row_version_conflict', $e->getMessage(), 409, [
                'current_row_version' => $e->currentVersion,
            ]);
        } catch (\OutOfBoundsException $e) {
            return Json::error($response, 'not_found', $e->getMessage(), 404);
        } catch (\DomainException $e) {
            return Json::error($response, 'invalid_agreement', $e->getMessage(), 409);
        }

        return Json::ok($response, ['agreement' => $agreement]);
    }

    /** @param array{id:string,command:string} $args */
    public function transition(Request $request, Response $response, array $args): Response
    {
        if (($error = $this->authorize($request, $response, AccessLevel::WRITE)) !== null) {
            return $error;
        }
        try {
            $body = $this->input($request);
            $agreement = $this->repository->transition(
                $this->currentSupplierId($request),
                (int) $args['id'],
                DeductionAgreementCommand::from($args['command']),
                $this->positiveInt($body['row_version'] ?? null, 'row_version'),
                $this->optionalDate($body['effective_on'] ?? null, 'effective_on'),
                $this->optionalText($body['reason'] ?? null, 'reason'),
                $this->userId($request),
            );
            $this->audit(
                $request,
                'payroll.deduction_agreement.transitioned',
                $agreement,
                ['command' => $args['command']],
            );
        } catch (\ValueError|\InvalidArgumentException|\UnexpectedValueException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 422);
        } catch (PayrollDeductionAgreementConflictException $e) {
            return Json::error($response, 'row_version_conflict', $e->getMessage(), 409, [
                'current_row_version' => $e->currentVersion,
            ]);
        } catch (\OutOfBoundsException $e) {
            return Json::error($response, 'not_found', $e->getMessage(), 404);
        } catch (\DomainException $e) {
            return Json::error($response, 'invalid_agreement_transition', $e->getMessage(), 409);
        }

        return Json::ok($response, ['agreement' => $agreement]);
    }

    private function authorize(
        Request $request,
        Response $response,
        AccessLevel $level,
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
        $permission = $level === AccessLevel::WRITE ? 'payroll.inputs.write' : 'payroll';
        if (!$this->requirePermission($request, $response, $permission, $level, $error)) {
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

        return $body === null ? [] : PayrollTimeValue::row($body, 'request_body');
    }

    private function positiveInt(mixed $value, string $field): int
    {
        $result = filter_var($value, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        if (!is_int($result)) {
            throw new \InvalidArgumentException("Pole {$field} musí být kladné celé číslo.");
        }

        return $result;
    }

    private function optionalPositiveInt(mixed $value, string $field): ?int
    {
        return $value === null || $value === ''
            ? null
            : $this->positiveInt($value, $field);
    }

    private function optionalDate(mixed $value, string $field): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) !== 1) {
            throw new \InvalidArgumentException("Pole {$field} musí být datum ve tvaru RRRR-MM-DD.");
        }

        return $value;
    }

    private function optionalText(mixed $value, string $field): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $text = trim(PayrollTimeValue::string($value, $field));
        if (mb_strlen($text, 'UTF-8') > 500) {
            throw new \InvalidArgumentException("Pole {$field} může mít nejvýše 500 znaků.");
        }

        return $text === '' ? null : $text;
    }

    /**
     * @param array<string,mixed> $agreement
     * @param array<string,mixed> $context
     */
    private function audit(
        Request $request,
        string $action,
        array $agreement,
        array $context = [],
    ): void {
        $payload = [
            'status' => PayrollTimeValue::string($agreement['status'] ?? null, 'status'),
            'row_version' => PayrollTimeValue::int($agreement['row_version'] ?? null, 'row_version'),
            'version_no' => PayrollTimeValue::int($agreement['version_no'] ?? null, 'version_no'),
            ...$this->logger->redact($context),
        ];
        $this->logger->log(
            $action,
            $this->userId($request),
            'payroll_deduction_agreement',
            PayrollTimeValue::int($agreement['id'] ?? null, 'id'),
            $payload,
            $this->ipMatcher->clientIpFromRequest(
                PayrollTimeValue::row($request->getServerParams(), 'server_params'),
            ),
            $request->getHeaderLine('User-Agent'),
            $this->currentSupplierId($request),
        );
    }
}
