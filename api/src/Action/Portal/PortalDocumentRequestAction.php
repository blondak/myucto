<?php

declare(strict_types=1);

namespace MyInvoice\Action\Portal;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\DocumentRequestRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Import\AiPdfExtractor;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\UploadedFileInterface;

/**
 * Epic F6 — klientský portál, chybějící doklady (Fáze F, audit 2026-07):
 *
 *   GET  /api/portal/document-requests               — vlastní požadavky (aktivní firma)
 *   POST /api/portal/document-requests/{id}/upload    — nahrání dokladu, field "file"
 *
 * Upload reuse existující AI extrakce (stejná cesta jako admin import
 * /api/admin/imports/ai-extract-pdf) — vytvoří purchase_invoice draft a spáruje
 * ho s požadavkem (status → uploaded). Supplier scope řeší SupplierScopeMiddleware
 * (klient bez membershipu = fail-closed 403 v resolveru) — KRITICKÉ pro tenant izolaci,
 * klient nesmí vidět ani ovlivnit požadavky jiné firmy.
 */
final class PortalDocumentRequestAction
{
    private const MAX_PDF_BYTES = 32 * 1024 * 1024;

    public function __construct(
        private readonly DocumentRequestRepository $repo,
        private readonly AiPdfExtractor $extractor,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function list(Request $request, Response $response): Response
    {
        $supplierId = SupplierGuard::currentId($request);
        if ($supplierId <= 0) {
            return Json::error($response, 'no_supplier', 'Žádná firma není dostupná.', 403);
        }
        return Json::ok($response, ['items' => $this->repo->listForSupplier($supplierId)]);
    }

    public function upload(Request $request, Response $response, array $args): Response
    {
        $supplierId = SupplierGuard::currentId($request);
        if ($supplierId <= 0) {
            return Json::error($response, 'no_supplier', 'Žádná firma není dostupná.', 403);
        }
        $id = (int) ($args['id'] ?? 0);
        $item = $this->repo->find($id, $supplierId);
        if ($item === null) {
            return Json::error($response, 'not_found', 'Požadavek nenalezen.', 404);
        }
        if ((string) $item['status'] === 'resolved') {
            return Json::error($response, 'already_resolved', 'Požadavek je už uzavřený.', 409);
        }

        $uploads = $request->getUploadedFiles();
        $file = $uploads['file'] ?? null;
        if (!$file instanceof UploadedFileInterface || $file->getError() !== UPLOAD_ERR_OK) {
            return Json::error($response, 'no_file', 'Nahrajte soubor v poli "file".', 400);
        }
        $size = (int) $file->getSize();
        if ($size <= 0 || $size > self::MAX_PDF_BYTES) {
            return Json::error($response, 'file_too_large', 'Soubor musí být <= ' . (int) (self::MAX_PDF_BYTES / 1024 / 1024) . ' MiB.', 413);
        }
        $bytes = (string) $file->getStream()->getContents();

        $userId = $this->userId($request);
        $originalName = $file->getClientFilename() ?: null;
        $result = $this->extractor->extractAndCreate($supplierId, (int) $userId, $bytes, null, $originalName);

        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('document_request.upload_attempt', $userId, 'document_request', $id, [
            'ok'     => $result['ok'],
            'source' => $result['source'] ?? null,
            'pdf_name' => $originalName,
        ], $ip, $request->getHeaderLine('User-Agent'), $supplierId);

        if (!$result['ok']) {
            return Json::error($response, 'extraction_failed', $result['error'] ?? 'Zpracování dokladu selhalo.', 422);
        }

        $purchaseInvoiceId = (int) ($result['purchase_invoice_id'] ?? 0);
        if ($purchaseInvoiceId > 0) {
            $this->repo->markUploaded($id, $supplierId, $purchaseInvoiceId);
        }

        $this->logger->log('document_request.uploaded', $userId, 'document_request', $id, [
            'purchase_invoice_id' => $purchaseInvoiceId,
        ], $ip, $request->getHeaderLine('User-Agent'), $supplierId);

        return Json::ok($response, $this->repo->find($id, $supplierId), 201);
    }

    private function userId(Request $request): ?int
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $id = (int) ($user['id'] ?? 0);
        return $id > 0 ? $id : null;
    }
}
