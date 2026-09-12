<?php

declare(strict_types=1);

namespace MyInvoice\Action\Accounting\Reports;

use MyInvoice\Action\Accounting\AccountingActionSupport;
use MyInvoice\Http\GuardsAccountingMode;
use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\Reports\ReportException;
use MyInvoice\Service\Accounting\Reports\StatementOverrideService;
use MyInvoice\Service\Accounting\Reports\StatementOverrideSuggester;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Log\LoggerInterface;

/**
 * Výjimky mapování účtů do výkazů pro konkrétní firmu (interní API, ne veřejné v1).
 *
 *   GET    /api/accounting/reports/statement-overrides?period_id=&statement_type=  — účty, řádky, výjimky
 *   PUT    /api/accounting/reports/statement-overrides                             — uložení celé sady (jedno Uložit)
 *   POST   /api/accounting/reports/statement-overrides                             — přidání jedné výjimky
 *   PUT    /api/accounting/reports/statement-overrides/{id}                        — úprava
 *   DELETE /api/accounting/reports/statement-overrides/{id}                        — smazání
 *   POST   /api/accounting/reports/statement-overrides/preview                     — náhled dopadu neuložené sady
 *   GET    /api/accounting/reports/statement-overrides/suggestions?period_id=      — návrh z podaného DPPO v evidenci
 *   POST   /api/accounting/reports/statement-overrides/suggestions?period_id=      — návrh z nahraného XML
 *
 * Firma se bere vždy ze SupplierGuard (výjimka jiné firmy není vidět ani upravitelná).
 * Zápisy jen účetní/admin — stejně jako ostatní účetní akce (requireWrite + RoutePermissionMap).
 */
final class StatementOverrideAction
{
    use AccountingActionSupport;
    use GuardsAccountingMode;

    private const MAX_XML_BYTES = 10 * 1024 * 1024;

    public function __construct(
        private readonly StatementOverrideService $service,
        private readonly StatementOverrideSuggester $suggester,
        private readonly Connection $db,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
        private readonly LoggerInterface $log,
    ) {}

    public function list(Request $request, Response $response): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;
        $q = $request->getQueryParams();
        $periodId = (int) ($q['period_id'] ?? 0);
        if ($periodId <= 0) {
            return Json::error($response, 'validation_failed', 'period_id je povinný.', 422);
        }
        $type = trim((string) ($q['statement_type'] ?? '')) ?: 'balance_sheet';

        return $this->run($response, function () use ($supplierId, $periodId, $type): array {
            $out = $this->service->overview($supplierId, $periodId, $type);
            $out['filed_return'] = $this->suggester->filedReturnForPeriod($supplierId, $periodId);
            return $out;
        });
    }

    public function save(Request $request, Response $response): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;
        if (!$this->requireWrite($request, $response, $err)) return $err;

        $body = (array) ($request->getParsedBody() ?? []);
        $versionId = (int) ($body['version_id'] ?? 0);
        $items = $body['overrides'] ?? null;
        if ($versionId <= 0 || !is_array($items)) {
            return Json::error($response, 'validation_failed', 'version_id a pole overrides jsou povinné.', 422);
        }

        return $this->run($response, function () use ($request, $supplierId, $versionId, $items): array {
            $saved = $this->service->save($supplierId, $versionId, array_values($items), $this->userId($request));
            $this->audit($request, $supplierId, 'save', ['version_id' => $versionId, 'count' => count($saved)]);
            return ['overrides' => $saved];
        });
    }

    public function create(Request $request, Response $response): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;
        if (!$this->requireWrite($request, $response, $err)) return $err;

        $body = (array) ($request->getParsedBody() ?? []);
        $versionId = (int) ($body['version_id'] ?? 0);
        if ($versionId <= 0) {
            return Json::error($response, 'validation_failed', 'version_id je povinný.', 422);
        }

        return $this->run($response, function () use ($request, $supplierId, $versionId, $body): array {
            $created = $this->service->create($supplierId, $versionId, $body, $this->userId($request));
            $this->audit($request, $supplierId, 'create', ['id' => $created['id'] ?? null, 'account_prefix' => $created['account_prefix'] ?? null]);
            return $created;
        }, 201);
    }

    /** @param array<string,string> $args */
    public function update(Request $request, Response $response, array $args): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;
        if (!$this->requireWrite($request, $response, $err)) return $err;

        $id = (int) ($args['id'] ?? 0);
        $body = (array) ($request->getParsedBody() ?? []);
        unset($body['id'], $body['version_id']);

        return $this->run($response, function () use ($request, $supplierId, $id, $body): array {
            $updated = $this->service->update($supplierId, $id, $body);
            $this->audit($request, $supplierId, 'update', ['id' => $id]);
            return $updated;
        });
    }

    /** @param array<string,string> $args */
    public function delete(Request $request, Response $response, array $args): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;
        if (!$this->requireWrite($request, $response, $err)) return $err;

        $id = (int) ($args['id'] ?? 0);

        return $this->run($response, function () use ($request, $supplierId, $id): array {
            $this->service->delete($supplierId, $id);
            $this->audit($request, $supplierId, 'delete', ['id' => $id]);
            return ['id' => $id, 'deleted' => true];
        });
    }

    public function preview(Request $request, Response $response): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;
        if (!$this->requireWrite($request, $response, $err)) return $err;

        $body = (array) ($request->getParsedBody() ?? []);
        $periodId = (int) ($body['period_id'] ?? 0);
        $type = trim((string) ($body['statement_type'] ?? '')) ?: 'balance_sheet';
        $items = $body['overrides'] ?? null;
        if ($periodId <= 0 || !is_array($items)) {
            return Json::error($response, 'validation_failed', 'period_id a pole overrides jsou povinné.', 422);
        }

        return $this->run($response, fn (): array => $this->service->preview($supplierId, $periodId, $type, array_values($items)));
    }

    public function suggestions(Request $request, Response $response): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;
        $periodId = (int) ($request->getQueryParams()['period_id'] ?? 0);
        if ($periodId <= 0) {
            return Json::error($response, 'validation_failed', 'period_id je povinný.', 422);
        }

        return $this->run($response, fn (): array => $this->suggester->suggestFromFiledReturn($supplierId, $periodId));
    }

    public function suggestFromUpload(Request $request, Response $response): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;
        if (!$this->requireWrite($request, $response, $err)) return $err;

        $body = (array) ($request->getParsedBody() ?? []);
        $periodId = (int) ($request->getQueryParams()['period_id'] ?? $body['period_id'] ?? 0);
        if ($periodId <= 0) {
            return Json::error($response, 'validation_failed', 'period_id je povinný.', 422);
        }
        $file = $this->firstFile($request->getUploadedFiles());
        if ($file === null || $file->getError() !== UPLOAD_ERR_OK) {
            return Json::error($response, 'bad_file', 'Nahrajte soubor XML podaného přiznání DPPDP9.', 415);
        }
        if ((int) ($file->getSize() ?? 0) > self::MAX_XML_BYTES) {
            return Json::error($response, 'bad_file', 'Soubor je větší než 10 MB.', 415);
        }
        $ext = strtolower((string) pathinfo((string) ($file->getClientFilename() ?? ''), PATHINFO_EXTENSION));
        if ($ext !== '' && $ext !== 'xml') {
            return Json::error($response, 'bad_file', 'Podporovaný formát je jen XML.', 415);
        }
        $xml = (string) $file->getStream()->getContents();

        return $this->run($response, fn (): array => $this->suggester->suggestFromXml($supplierId, $periodId, $xml));
    }

    /** @param array<string, UploadedFileInterface|array<int,UploadedFileInterface>> $uploads */
    private function firstFile(array $uploads): ?UploadedFileInterface
    {
        foreach ($uploads as $node) {
            if ($node instanceof UploadedFileInterface) {
                return $node;
            }
            if (is_array($node)) {
                foreach ($node as $sub) {
                    if ($sub instanceof UploadedFileInterface) {
                        return $sub;
                    }
                }
            }
        }

        return null;
    }

    /** @param callable(): array<string,mixed> $fn */
    private function run(Response $response, callable $fn, int $status = 200): Response
    {
        try {
            return Json::ok($response, $fn(), $status);
        } catch (ReportException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        } catch (\Throwable $e) {
            $this->log->error('Výjimky mapování výkazů: ' . $e->getMessage(), ['exception' => $e]);
            return Json::error($response, 'build_failed', 'Operaci se nepodařilo dokončit.', 500);
        }
    }

    /** @param array<string,mixed> $meta */
    private function audit(Request $request, int $supplierId, string $action, array $meta): void
    {
        $this->logger->log('accounting.statement_overrides.' . $action, $this->userId($request), 'statement_override', null,
            $meta,
            $this->ipMatcher->clientIpFromRequest($request->getServerParams()),
            $request->getHeaderLine('User-Agent'), $supplierId);
    }
}
