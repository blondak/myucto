<?php

declare(strict_types=1);

namespace MyInvoice\Action\Payroll;

use MyInvoice\Http\Json;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\Payroll\PayrollTimeValue;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Payroll\Import\Attendance\AttendanceImportService;
use MyInvoice\Service\Payroll\Import\ImportFiles;
use MyInvoice\Service\Payroll\PayrollModuleAccess;
use MyInvoice\Service\Tenant\SupplierAccessResolver;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Mzdy → Importy → Docházka a Mapování sloupců. Jen session: soubory
 * s osobními údaji celé firmy se nahrávají z obrazovky, ne z integrace přes token.
 */
final class PayrollAttendanceImportAction
{
    use PayrollActionSupport;

    public function __construct(
        private readonly AttendanceImportService $imports,
        private readonly PayrollModuleAccess $access,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
        private readonly SupplierAccessResolver $supplierAccess,
    ) {
    }

    public function preview(Request $request, Response $response): Response
    {
        if (($error = $this->authorize($request, $response, 'payroll.inputs.write')) !== null) {
            return $error;
        }
        try {
            $body = $this->input($request);
            $preview = $this->imports->preview(
                $this->currentSupplierId($request),
                $this->string($body, 'period'),
                ImportFiles::fromRequest($body['files'] ?? null, ['xlsx', 'csv']),
                $body['rules'] ?? null,
                $this->profileId($body),
                $body['components'] ?? null,
            );
        } catch (\InvalidArgumentException|\UnexpectedValueException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 422);
        }

        return Json::ok($response, $preview);
    }

    public function apply(Request $request, Response $response): Response
    {
        if (($error = $this->authorize($request, $response, 'payroll.inputs.write')) !== null) {
            return $error;
        }
        try {
            $body = $this->input($request);
            $result = $this->imports->apply(
                $this->currentSupplierId($request),
                $this->string($body, 'period'),
                ImportFiles::fromRequest($body['files'] ?? null, ['xlsx', 'csv']),
                $body['rules'] ?? null,
                $body['links'] ?? null,
                ($body['save_links'] ?? false) === true,
                ($body['create_inputs'] ?? false) === true,
                $this->userId($request),
                $body['components'] ?? null,
                ($body['create_components'] ?? false) === true,
                $this->profileId($body),
                ($body['adopt_personal_numbers'] ?? false) === true,
            );
        } catch (\InvalidArgumentException|\UnexpectedValueException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 422);
        }
        $batch = PayrollTimeValue::row($result['batch'] ?? null, 'batch');
        $inputs = PayrollTimeValue::row($result['inputs'] ?? null, 'inputs');
        $this->logger->log(
            'payroll.attendance_import.applied',
            $this->userId($request),
            'payroll_attendance_import',
            PayrollTimeValue::int($batch['id'] ?? null, 'id'),
            [
                'period' => $batch['period'] ?? null,
                'person_count' => $batch['person_count'] ?? null,
                'metric_count' => $batch['metric_count'] ?? null,
                'inputs_created' => $inputs['created'] ?? 0,
                'components_created' => $result['components_created'] ?? [],
                'links_saved' => $result['links_saved'] ?? 0,
                'replayed' => $result['replayed'] ?? false,
            ],
            $this->ipMatcher->clientIpFromRequest($this->serverParams($request)),
            $request->getHeaderLine('User-Agent'),
            $this->currentSupplierId($request),
        );

        return Json::ok($response, $result, 201);
    }

    public function persons(Request $request, Response $response): Response
    {
        if (($error = $this->authorize($request, $response, 'payroll.person.write')) !== null) {
            return $error;
        }
        try {
            $body = $this->input($request);
            $result = $this->imports->persons(
                $this->currentSupplierId($request),
                $this->string($body, 'period'),
                $body['persons'] ?? null,
                $this->userId($request),
                $this->ipMatcher->clientIpFromRequest($this->serverParams($request)),
                $request->getHeaderLine('User-Agent'),
            );
        } catch (\InvalidArgumentException|\UnexpectedValueException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 422);
        }

        return Json::ok($response, $result, 201);
    }

    public function batches(Request $request, Response $response): Response
    {
        if (($error = $this->authorize($request, $response, 'payroll.inputs.write')) !== null) {
            return $error;
        }
        $period = $request->getQueryParams()['period'] ?? null;
        try {
            $batches = $this->imports->batches(
                $this->currentSupplierId($request),
                is_string($period) ? trim($period) : null,
            );
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 422);
        }

        return Json::ok($response, ['batches' => $batches]);
    }

    /** @param array{id:string} $args */
    public function batch(Request $request, Response $response, array $args): Response
    {
        if (($error = $this->authorize($request, $response, 'payroll.inputs.write')) !== null) {
            return $error;
        }
        $detail = $this->imports->batch($this->currentSupplierId($request), (int) $args['id']);
        if ($detail === null) {
            return Json::error($response, 'not_found', 'Dávka importu nebyla nalezena.', 404);
        }

        return Json::ok($response, $detail);
    }

    public function profiles(Request $request, Response $response): Response
    {
        if (($error = $this->authorize($request, $response, 'payroll.inputs.write')) !== null) {
            return $error;
        }

        return Json::ok($response, ['profiles' => $this->imports->profiles($this->currentSupplierId($request))]);
    }

    public function saveProfile(Request $request, Response $response): Response
    {
        if (($error = $this->authorize($request, $response, 'payroll.settings')) !== null) {
            return $error;
        }
        try {
            $body = $this->input($request);
            $profile = $this->imports->saveProfile(
                $this->currentSupplierId($request),
                $body['id'] ?? null,
                $body['name'] ?? null,
                $body['rules'] ?? null,
                $this->userId($request),
                $body['components'] ?? null,
            );
        } catch (\InvalidArgumentException|\UnexpectedValueException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 422);
        }

        return Json::ok($response, ['profile' => $profile]);
    }

    /** @param array{id:string} $args */
    public function deleteProfile(Request $request, Response $response, array $args): Response
    {
        if (($error = $this->authorize($request, $response, 'payroll.settings')) !== null) {
            return $error;
        }
        if (!$this->imports->deleteProfile($this->currentSupplierId($request), (int) $args['id'])) {
            return Json::error($response, 'not_found', 'Profil mapování nebyl nalezen.', 404);
        }

        return Json::ok($response, ['deleted' => true]);
    }

    /**
     * Kopie profilu do jiné firmy. O přístupu k cílové firmě rozhoduje týž
     * resolver jako přepínač firem (členství, globální správce, firma vázaná
     * na doménu) — kopie tak nemůže otevřít cestu, kterou by přepnutí zavřelo.
     *
     * @param array{id:string} $args
     */
    public function copyProfile(Request $request, Response $response, array $args): Response
    {
        if (($error = $this->authorize($request, $response, 'payroll.settings')) !== null) {
            return $error;
        }
        try {
            $body = $this->input($request);
            $target = $body['target_supplier_id'] ?? null;
            if (!is_int($target) || $target <= 0) {
                throw new \InvalidArgumentException('Vyberte firmu, do které se má profil zkopírovat.');
            }
            if (!$this->mayAccessSupplier($request, $target)) {
                return Json::error($response, 'forbidden', 'Do vybrané firmy nemáte přístup.', 403);
            }
            $profile = $this->imports->copyProfile(
                $this->currentSupplierId($request),
                (int) $args['id'],
                $target,
                $this->userId($request),
            );
        } catch (\OutOfBoundsException $e) {
            return Json::error($response, 'not_found', $e->getMessage(), 404);
        } catch (\InvalidArgumentException|\UnexpectedValueException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 422);
        }

        return Json::ok($response, ['profile' => $profile], 201);
    }

    /** @param array{id:string} $args */
    public function exportProfile(Request $request, Response $response, array $args): Response
    {
        if (($error = $this->authorize($request, $response, 'payroll.inputs.write')) !== null) {
            return $error;
        }
        try {
            $export = $this->imports->exportProfile($this->currentSupplierId($request), (int) $args['id']);
        } catch (\OutOfBoundsException $e) {
            return Json::error($response, 'not_found', $e->getMessage(), 404);
        }

        return Json::ok($response, $export);
    }

    public function importProfile(Request $request, Response $response): Response
    {
        if (($error = $this->authorize($request, $response, 'payroll.settings')) !== null) {
            return $error;
        }
        try {
            $body = $this->input($request);
            $profile = $this->imports->importProfile(
                $this->currentSupplierId($request),
                $body['profile'] ?? null,
                $body['name'] ?? null,
                $this->userId($request),
            );
        } catch (\InvalidArgumentException|\UnexpectedValueException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 422);
        }

        return Json::ok($response, ['profile' => $profile], 201);
    }

    private function mayAccessSupplier(Request $request, int $supplierId): bool
    {
        $access = $this->supplierAccess->resolve(
            $request->withHeader(SupplierScopeMiddleware::HEADER_NAME, (string) $supplierId),
        );

        return !$access->denied && $access->supplierId === $supplierId;
    }

    private function authorize(Request $request, Response $response, string $permission): ?Response
    {
        if (!RequestAuthorization::isSessionAuth($request)) {
            return Json::sessionRequired($response);
        }
        $error = null;
        if (!$this->requirePermission($request, $response, $permission, AccessLevel::WRITE, $error)) {
            return $error;
        }
        if (!$this->requirePayrollEnabled($request, $response, $this->access, $error)) {
            return $error;
        }

        return null;
    }

    /** @param array<string,mixed> $body */
    private function profileId(array $body): ?int
    {
        $profileId = $body['profile_id'] ?? null;
        if ($profileId !== null && (!is_int($profileId) || $profileId <= 0)) {
            throw new \InvalidArgumentException('Profil mapování nemá platné ID.');
        }

        return $profileId;
    }

    /** @return array<string,mixed> */
    private function input(Request $request): array
    {
        $body = $request->getParsedBody();

        return is_array($body) ? PayrollTimeValue::row($body, 'request_body') : [];
    }

    /** @param array<string,mixed> $body */
    private function string(array $body, string $key): string
    {
        $value = $body[$key] ?? null;
        if (!is_string($value)) {
            throw new \InvalidArgumentException('Vyberte období importu.');
        }

        return trim($value);
    }

    /** @return array<string,mixed> */
    private function serverParams(Request $request): array
    {
        return PayrollTimeValue::row($request->getServerParams(), 'server_params');
    }
}
