<?php

declare(strict_types=1);

namespace MyInvoice\Action\Payroll;

use MyInvoice\Http\Json;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Payroll\Import\Jmhz\JmhzExternalSubmissionStore;
use MyInvoice\Service\Payroll\PayrollModuleAccess;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Podání ČSSZ, která za firmu podal předchozí mzdový program (převod z PAMICA,
 * nahrané XML hlášení).
 *
 *   GET    /api/payroll/submissions/jmhz-external        přehled (bez obsahu podání)
 *   DELETE /api/payroll/submissions/jmhz-external/{id}   odebrání záznamu z historie
 *
 * Záznam blokuje přípravu řádného hlášení za týž měsíc. Když neodpovídá skutečnosti
 * (hlášení ve skutečnosti neodešlo, soubor jiného období), musí ho jít odebrat
 * na obrazovce, ne jen v databázi.
 */
final class PayrollJmhzExternalSubmissionAction
{
    use PayrollActionSupport;

    public function __construct(
        private readonly JmhzExternalSubmissionStore $store,
        private readonly PayrollModuleAccess $access,
        private readonly ActivityLogger $activity,
    ) {}

    public function list(Request $request, Response $response): Response
    {
        if (($denied = $this->authorize($request, $response, AccessLevel::READ)) !== null) {
            return $denied;
        }
        $environment = $this->environment($request);
        if ($environment === null) {
            return $this->invalid($response, 'Prostředí musí být test nebo production.');
        }

        return $this->noStore(Json::ok($response, [
            'environment' => $environment,
            'items' => $this->store->overview($this->currentSupplierId($request), $environment),
        ]));
    }

    /** @param array<string,string> $args */
    public function delete(Request $request, Response $response, array $args): Response
    {
        if (($denied = $this->authorize($request, $response, AccessLevel::WRITE)) !== null) {
            return $denied;
        }
        $environment = $this->environment($request);
        if ($environment === null) {
            return $this->invalid($response, 'Prostředí musí být test nebo production.');
        }
        $id = filter_var($args['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!is_int($id)) {
            return $this->invalid($response, 'Podání musí být kladné celé číslo.');
        }
        $supplierId = $this->currentSupplierId($request);
        if (!$this->store->delete($supplierId, $environment, $id)) {
            return $this->noStore(Json::error($response, 'not_found', 'Převzaté podání v téhle firmě není.', 404));
        }
        $this->activity->log('payroll.jmhz_external_deleted', $this->userId($request), 'payroll_external_jmhz_submission', $id,
            ['environment' => $environment], supplierId: $supplierId);

        return $this->noStore(Json::ok($response, ['deleted' => true, 'id' => $id]));
    }

    private function environment(Request $request): ?string
    {
        $value = $request->getQueryParams()['environment'] ?? 'production';

        return in_array($value, ['test', 'production'], true) ? $value : null;
    }

    private function authorize(Request $request, Response $response, AccessLevel $level): ?Response
    {
        if (!RequestAuthorization::isSessionAuth($request)) {
            return Json::sessionRequired($response);
        }
        $error = null;
        if (!$this->requirePermission($request, $response, 'payroll.submissions', $level, $error)) {
            return $error;
        }
        if (!$this->requirePayrollEnabled($request, $response, $this->access, $error)) {
            return $error;
        }

        return null;
    }

    private function invalid(Response $response, string $message): Response
    {
        return $this->noStore(Json::error($response, 'validation_failed', $message, 422));
    }

    private function noStore(Response $response): Response
    {
        return $response
            ->withHeader('Cache-Control', 'private, no-store')
            ->withHeader('Pragma', 'no-cache');
    }
}
