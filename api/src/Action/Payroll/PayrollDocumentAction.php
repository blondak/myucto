<?php

declare(strict_types=1);

namespace MyInvoice\Action\Payroll;

use MyInvoice\Http\Json;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Payroll\Document\PayrollDocumentService;
use MyInvoice\Service\Payroll\PayrollModuleAccess;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Psr7\Stream;

final class PayrollDocumentAction
{
    use PayrollActionSupport;

    public function __construct(
        private readonly PayrollDocumentService $documents,
        private readonly PayrollModuleAccess $moduleAccess,
        private readonly ActivityLogger $activity,
        private readonly IpMatcher $ipMatcher,
    ) {}

    /** @param array<string,string> $args */
    public function list(Request $request, Response $response, array $args): Response
    {
        if (!$this->requirePermission(
            $request,
            $response,
            'payroll.documents',
            AccessLevel::READ,
            $error,
        ) || !$this->requirePayrollEnabled($request, $response, $this->moduleAccess, $error)) {
            return $error ?? Json::error($response, 'forbidden', 'Pro tuto akci nemáš oprávnění.', 403);
        }
        $period = trim((string) ($request->getQueryParams()['period'] ?? ''));
        try {
            $result = $this->documents->listForPeriod(
                $this->currentSupplierId($request),
                $period,
            );
        } catch (\InvalidArgumentException $exception) {
            return Json::error($response, 'validation_failed', $exception->getMessage(), 422);
        }

        return Json::ok($response, [
            'period' => $period,
            'revisions' => $result['revisions'],
            'items' => array_map(self::publicDocument(...), $result['items']),
        ]);
    }

    /** @param array<string,string> $args */
    public function generateBundle(Request $request, Response $response, array $args): Response
    {
        if (!$this->requirePermission(
            $request,
            $response,
            'payroll.documents',
            AccessLevel::WRITE,
            $error,
        ) || !$this->requirePayrollEnabled($request, $response, $this->moduleAccess, $error)) {
            return $error ?? Json::error($response, 'forbidden', 'Pro tuto akci nemáš oprávnění.', 403);
        }
        $supplierId = $this->currentSupplierId($request);
        $userId = $this->userId($request);
        $runId = (int) ($args['runId'] ?? 0);
        $revisionId = (int) ($args['revisionId'] ?? 0);
        $idempotencyKey = trim($request->getHeaderLine('Idempotency-Key'));
        if ($userId === null || $runId <= 0 || $revisionId <= 0 || $idempotencyKey === '') {
            return Json::error($response, 'validation_failed', 'Požadavek na mzdový balíček je neplatný.', 422);
        }
        try {
            $document = $this->documents->generateMonthlyBundle(
                $supplierId,
                $runId,
                $revisionId,
                $idempotencyKey,
                $userId,
            );
        } catch (\InvalidArgumentException $exception) {
            return Json::error($response, 'validation_failed', $exception->getMessage(), 422);
        } catch (\Throwable) {
            return Json::error($response, 'bundle_failed', 'Mzdový balíček nelze vytvořit.', 409);
        }
        $this->activity->log(
            'payroll.monthly_bundle_generated',
            $userId,
            'payroll_document',
            (int) $document['id'],
            [
                'document_kind' => $document['document_kind'],
                'revision_id' => $document['revision_id'],
                'file_sha256' => $document['file_sha256'],
            ],
            $this->ipMatcher->clientIpFromRequest($request->getServerParams()),
            $request->getHeaderLine('User-Agent'),
            $supplierId,
        );
        return Json::ok($response, self::publicDocument($document), 201);
    }

    /** @param array<string,string> $args */
    public function grant(Request $request, Response $response, array $args): Response
    {
        if (!$this->requirePermission(
            $request,
            $response,
            'payroll.documents',
            AccessLevel::READ,
            $error,
        ) || !$this->requirePayrollEnabled($request, $response, $this->moduleAccess, $error)) {
            return $error ?? Json::error($response, 'forbidden', 'Pro tuto akci nemáš oprávnění.', 403);
        }
        $supplierId = $this->currentSupplierId($request);
        $userId = $this->userId($request);
        $documentId = (int) ($args['documentId'] ?? 0);
        if ($userId === null || $documentId <= 0) {
            return Json::error($response, 'not_found', 'Mzdový dokument nebyl nalezen.', 404);
        }
        try {
            $grant = $this->documents->issueDownloadGrant(
                $supplierId,
                $documentId,
                $userId,
            );
        } catch (\Throwable) {
            return Json::error($response, 'not_found', 'Mzdový dokument nebyl nalezen.', 404);
        }
        return Json::ok($response, $grant)
            ->withHeader('Cache-Control', 'private, no-store')
            ->withHeader('Pragma', 'no-cache');
    }

    /** @param array<string,string> $args */
    public function download(Request $request, Response $response, array $args): Response
    {
        if (!$this->requirePermission(
            $request,
            $response,
            'payroll.documents',
            AccessLevel::READ,
            $error,
        ) || !$this->requirePayrollEnabled($request, $response, $this->moduleAccess, $error)) {
            return $error ?? Json::error($response, 'forbidden', 'Pro tuto akci nemáš oprávnění.', 403);
        }
        $supplierId = $this->currentSupplierId($request);
        $userId = $this->userId($request);
        $documentId = (int) ($args['documentId'] ?? 0);
        $token = trim($request->getHeaderLine('X-Payroll-Download-Token'));
        if ($userId === null || $documentId <= 0) {
            return Json::error($response, 'not_found', 'Mzdový dokument nebyl nalezen.', 404);
        }
        try {
            $download = $this->documents->consumeDownload(
                $supplierId,
                $documentId,
                $userId,
                $token,
            );
        } catch (\Throwable) {
            return Json::error($response, 'not_found', 'Mzdový dokument nebyl nalezen.', 404);
        }
        $document = $download['document'];
        $bytes = $download['bytes'];
        $handle = fopen('php://temp', 'w+b');
        if ($handle === false || fwrite($handle, $bytes) !== strlen($bytes)) {
            return Json::error($response, 'download_failed', 'Dokument nelze stáhnout.', 500);
        }
        rewind($handle);

        $this->activity->log(
            'payroll.document_downloaded',
            $userId,
            'payroll_document',
            $documentId,
            [
                'document_kind' => $document['document_kind'],
                'revision_id' => $document['revision_id'],
                'file_sha256' => $document['file_sha256'],
            ],
            $this->ipMatcher->clientIpFromRequest($request->getServerParams()),
            $request->getHeaderLine('User-Agent'),
            $supplierId,
        );

        return $response
            ->withHeader('Content-Type', (string) $document['mime_type'])
            ->withHeader(
                'Content-Disposition',
                'attachment; filename="' . (string) $document['suggested_filename'] . '"',
            )
            ->withHeader('Content-Length', (string) strlen($bytes))
            ->withHeader('Cache-Control', 'private, no-store')
            ->withHeader('Pragma', 'no-cache')
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Content-Security-Policy', "default-src 'none'; sandbox")
            ->withBody(new Stream($handle));
    }

    /**
     * @param array<string,mixed> $document
     * @return array<string,mixed>
     */
    private static function publicDocument(array $document): array
    {
        $keys = [
            'id', 'run_id', 'revision_id', 'revision_no', 'revision_status',
            'office_id', 'office_name',
            'employee_id', 'employee_name', 'document_kind', 'document_revision_no',
            'supersedes_document_id', 'file_sha256', 'size_bytes', 'mime_type',
            'suggested_filename', 'created_at',
        ];
        $result = [];
        foreach ($keys as $key) {
            if (array_key_exists($key, $document)) {
                $result[$key] = $document[$key];
            }
        }
        return $result;
    }
}
