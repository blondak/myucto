<?php

declare(strict_types=1);

namespace MyInvoice\Action\Payroll;

use MyInvoice\Http\Json;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\Payroll\Import\Takeover\TakeoverTabularImportService;
use MyInvoice\Service\Payroll\Migration\PayrollTakeoverReader;
use MyInvoice\Service\Payroll\PayrollModuleAccess;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Převzaté mzdy roku přechodu: přehled, čtení za osobu a tabulkový import.
 *
 * Čtení je mzdová sestava (`payroll.reports`), import zapisuje mzdová data
 * osoby, a tedy nese totéž právo jako počáteční stavy kumulací
 * (`payroll.employment.write`). Vše jen ze session: jsou to osobní údaje.
 */
final class PayrollTakeoverWageAction
{
    use PayrollActionSupport;

    public function __construct(
        private readonly PayrollTakeoverReader $reader,
        private readonly TakeoverTabularImportService $imports,
        private readonly PayrollModuleAccess $access,
    ) {}

    /** Přehled za firmu: které měsíce, kolik osob, z jakého zdroje. @param array{year:string} $args */
    public function overview(Request $request, Response $response, array $args): Response
    {
        if (($error = $this->authorize($request, $response, AccessLevel::READ)) !== null) {
            return $error;
        }
        try {
            $query = $request->getQueryParams();
            $source = isset($query['source']) && is_string($query['source']) && $query['source'] !== ''
                ? $query['source']
                : null;

            return Json::ok($response, ['takeover' => $this->reader->forSupplier(
                $this->currentSupplierId($request),
                (int) $args['year'],
                $source,
            )->toArray()]);
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 422);
        }
    }

    /** @param array{year:string,employeeId:string} $args */
    public function person(Request $request, Response $response, array $args): Response
    {
        if (($error = $this->authorize($request, $response, AccessLevel::READ)) !== null) {
            return $error;
        }
        try {
            return Json::ok($response, ['takeover' => $this->reader->forEmployee(
                $this->currentSupplierId($request),
                (int) $args['employeeId'],
                (int) $args['year'],
            )->toArray()]);
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 422);
        }
    }

    /** Vzorový soubor; bez něj hlavičku nikdo netrefí. */
    public function importTemplate(Request $request, Response $response): Response
    {
        if (($error = $this->authorize($request, $response, AccessLevel::READ)) !== null) {
            return $error;
        }
        $response->getBody()->write(TakeoverTabularImportService::template());

        return $response
            ->withHeader('Content-Type', 'text/csv; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="prevzate-mzdy-vzor.csv"');
    }

    public function importPreview(Request $request, Response $response): Response
    {
        if (($error = $this->authorize($request, $response, AccessLevel::WRITE)) !== null) {
            return $error;
        }
        try {
            $body = $this->body($request);

            return Json::ok($response, ['preview' => $this->imports->preview(
                $this->currentSupplierId($request),
                is_string($body['source'] ?? null) ? $body['source'] : '',
                is_string($body['format'] ?? null) ? $body['format'] : '',
                is_string($body['source_name'] ?? null) ? $body['source_name'] : '',
                $this->content($body),
            )]);
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 422);
        }
    }

    public function importApply(Request $request, Response $response): Response
    {
        if (($error = $this->authorize($request, $response, AccessLevel::WRITE)) !== null) {
            return $error;
        }
        try {
            $body = $this->body($request);

            return Json::ok($response, ['import' => $this->imports->apply(
                $this->currentSupplierId($request),
                is_string($body['source'] ?? null) ? $body['source'] : '',
                is_string($body['format'] ?? null) ? $body['format'] : '',
                is_string($body['source_name'] ?? null) ? $body['source_name'] : '',
                $this->content($body),
            )]);
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 422);
        }
    }

    /** @return array<string,mixed> */
    private function body(Request $request): array
    {
        $body = $request->getParsedBody();
        if (!is_array($body)) {
            throw new \InvalidArgumentException('Tělo požadavku musí být objekt.');
        }

        return $body;
    }

    /** @param array<string,mixed> $body */
    private function content(array $body): string
    {
        $encoded = is_string($body['content_base64'] ?? null) ? $body['content_base64'] : '';
        if ($encoded === '' || strlen($encoded) > 6_700_000) {
            throw new \InvalidArgumentException('Importní obsah chybí nebo překračuje bezpečný limit.');
        }
        $decoded = base64_decode($encoded, true);
        if ($decoded === false) {
            throw new \InvalidArgumentException('content_base64 není platné Base64.');
        }

        return $decoded;
    }

    private function authorize(Request $request, Response $response, AccessLevel $level): ?Response
    {
        if (!RequestAuthorization::isSessionAuth($request)) {
            return Json::sessionRequired($response, 'Tento endpoint je dostupný pouze z přihlášené webové session.');
        }
        $error = null;
        $permission = $level === AccessLevel::WRITE ? 'payroll.employment.write' : 'payroll.reports';
        if (!$this->requirePermission($request, $response, $permission, $level, $error)) {
            return $error ?? throw new \LogicException('Chybí chybová odpověď oprávnění.');
        }
        if (!$this->requirePayrollEnabled($request, $response, $this->access, $error)) {
            return $error ?? throw new \LogicException('Chybí chybová odpověď modulu.');
        }

        return null;
    }
}
