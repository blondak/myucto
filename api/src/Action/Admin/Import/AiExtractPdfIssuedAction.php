<?php

declare(strict_types=1);

namespace MyInvoice\Action\Admin\Import;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Import\AiIssuedInvoiceExtractor;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\UploadedFileInterface;

/**
 * POST /api/admin/imports/ai-extract-pdf-issued
 *
 * Prodejní zrcadlo {@see AiExtractPdfAction}. Multipart upload jednoho PDF / obrázku /
 * ISDOC → ISDOC priorita, AI fallback → vytvoří DRAFT vydané faktury, který uživatel
 * zkontroluje v editoru a teprve pak vystaví.
 *
 * Body: multipart form-data, field "pdf" = soubor.
 * Optional ?model=... (override per request, validovaný proti whitelistu providera).
 */
final class AiExtractPdfIssuedAction
{
    private const MAX_PDF_BYTES = 32 * 1024 * 1024;

    public function __construct(
        private readonly AiIssuedInvoiceExtractor $extractor,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function __invoke(Request $request, Response $response): Response
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        // Zakládá vydanou fakturu → invoices.create (WRITE). Stejný klíč jako ruční „Nová faktura".
        if (!RequestAuthorization::allows($request, 'invoices.create', AccessLevel::WRITE)) {
            return Json::error($response, 'forbidden', 'Chybí oprávnění vystavovat faktury.', 403);
        }
        $supplierId = SupplierGuard::currentId($request);
        if ($supplierId === 0) {
            return Json::error($response, 'no_supplier', 'Chybí supplier kontext.', 400);
        }

        $uploads = $request->getUploadedFiles();
        $file = $uploads['pdf'] ?? null;
        if (!$file instanceof UploadedFileInterface || $file->getError() !== UPLOAD_ERR_OK) {
            return Json::error($response, 'no_file', 'Nahrajte PDF v poli "pdf".', 400);
        }
        $size = (int) $file->getSize();
        if ($size <= 0 || $size > self::MAX_PDF_BYTES) {
            return Json::error($response, 'file_too_large', 'PDF musí být <= ' . self::MAX_PDF_BYTES . ' B.', 413);
        }
        $bytes = (string) $file->getStream()->getContents();

        $modelOverride = (string) ($request->getQueryParams()['model'] ?? '') ?: null;
        if ($modelOverride !== null) {
            $allowedModels = $this->extractor->capabilities($supplierId)->models;
            if (!in_array($modelOverride, $allowedModels, true)) {
                return Json::error($response, 'validation_failed', 'Neplatný model.', 400);
            }
        }

        $userId = (int) ($user['id'] ?? 0);
        $originalName = $file->getClientFilename() ?: null;
        $result = $this->extractor->extractAndCreate($supplierId, $userId, $bytes, $modelOverride, $originalName);

        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('import.ai_extract_issued', $userId, 'invoice',
            $result['invoice_id'] ?? null,
            [
                'source'   => $result['source'] ?? null,
                'ok'       => $result['ok'],
                'provider' => $result['provider'] ?? null,
                'model'    => $result['model'] ?? null,
                'region'   => $result['region'] ?? null,
                'usage'    => $result['usage'] ?? null,
                'pdf_size' => $size,
                'pdf_name' => $file->getClientFilename(),
            ],
            $ip, $request->getHeaderLine('User-Agent'),
        );

        if (!$result['ok']) {
            return Json::error($response, 'extraction_failed',
                $result['error'] ?? 'Extrakce selhala',
                422,
                ['ai_data' => $result['ai_data'] ?? null, 'source' => $result['source'] ?? null],
            );
        }
        return Json::ok($response, $result, 201);
    }
}
