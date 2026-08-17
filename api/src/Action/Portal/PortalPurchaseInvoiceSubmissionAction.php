<?php

declare(strict_types=1);

namespace MyInvoice\Action\Portal;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\PurchaseInvoiceSubmissionRepository;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\PurchaseInvoice\PurchaseInvoiceSubmissionException;
use MyInvoice\Service\PurchaseInvoice\PurchaseInvoiceSubmissionUploadService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\UploadedFileInterface;

/** Klientská strana fronty: přehled, spontánní předání a náhradní originál. */
final class PortalPurchaseInvoiceSubmissionAction
{
    private const MAX_FILES = 20;
    private const STATUSES = ['submitted', 'processing', 'needs_information', 'processed', 'rejected'];

    public function __construct(
        private readonly PurchaseInvoiceSubmissionRepository $submissions,
        private readonly PurchaseInvoiceSubmissionUploadService $upload,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function list(Request $request, Response $response): Response
    {
        if ($denied = $this->deny($request, $response)) return $denied;
        $supplierId = SupplierGuard::currentId($request);
        $q = $request->getQueryParams();
        $status = isset($q['status']) && in_array((string) $q['status'], self::STATUSES, true)
            ? (string) $q['status'] : null;
        $limit = max(1, min(100, (int) ($q['limit'] ?? 50)));
        $offset = max(0, (int) ($q['offset'] ?? 0));
        return Json::ok($response, $this->submissions->paginate($supplierId, $status, $limit, $offset));
    }

    public function upload(Request $request, Response $response): Response
    {
        if ($denied = $this->deny($request, $response, true)) return $denied;
        return $this->handleUpload($request, $response, null, 'portal');
    }

    public function resubmit(Request $request, Response $response, array $args): Response
    {
        if ($denied = $this->deny($request, $response, true)) return $denied;
        $id = (int) ($args['id'] ?? 0);
        return $this->handleUpload($request, $response, $id, 'portal');
    }

    private function handleUpload(
        Request $request,
        Response $response,
        ?int $supersedesId,
        string $via,
    ): Response {
        $supplierId = SupplierGuard::currentId($request);
        if ($supplierId <= 0) return Json::error($response, 'no_supplier', 'Žádná firma není dostupná.', 403);
        $body = (array) ($request->getParsedBody() ?? []);
        $files = $this->files($request);
        if ($files === []) return Json::error($response, 'no_file', 'Vyberte alespoň jeden soubor.', 400);
        if ($supersedesId !== null && count($files) !== 1) {
            return Json::error($response, 'single_replacement_required', 'Náhrada musí obsahovat právě jeden soubor.', 400);
        }
        if (count($files) > self::MAX_FILES) {
            return Json::error($response, 'too_many_files', 'Najednou lze předat nejvýše 20 souborů.', 413);
        }

        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $userId = (int) ($user['id'] ?? 0);
        $items = [];
        $errors = [];
        $firstError = null;
        foreach ($files as $file) {
            try {
                $items[] = $this->upload->submit(
                    $file,
                    $supplierId,
                    $userId > 0 ? $userId : null,
                    $via,
                    isset($body['note']) ? (string) $body['note'] : null,
                    isset($body['document_kind_hint']) ? (string) $body['document_kind_hint'] : null,
                    null,
                    $supersedesId,
                );
            } catch (PurchaseInvoiceSubmissionException $e) {
                $firstError ??= $e;
                $errors[] = [
                    'filename' => basename(str_replace('\\', '/', (string) $file->getClientFilename())),
                    'code' => $e->errorCode,
                    'message' => $e->getMessage(),
                ];
            }
        }
        if ($items === [] && $firstError instanceof PurchaseInvoiceSubmissionException) {
            return Json::error(
                $response,
                $firstError->errorCode,
                $firstError->getMessage(),
                $firstError->httpStatus,
                ['files' => $errors],
            );
        }

        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        foreach ($items as $item) {
            $submission = $item['submission'];
            $this->logger->log(
                !empty($item['duplicate']) ? 'purchase_invoice_submission.duplicate' : 'purchase_invoice_submission.created',
                $userId ?: null,
                'purchase_invoice_submission',
                (int) $submission['id'],
                [
                    'document_id' => (int) $submission['document_id'],
                    'filename' => (string) $submission['original_name'],
                    'via' => $via,
                    'supersedes_submission_id' => $supersedesId,
                ],
                $ip,
                $request->getHeaderLine('User-Agent'),
                $supplierId,
            );
        }

        $duplicates = count(array_filter($items, static fn(array $item): bool => !empty($item['duplicate'])));
        return Json::ok($response, [
            'items' => array_map(static fn(array $item): array => $item['submission'] + [
                'duplicate' => (bool) $item['duplicate'],
            ], $items),
            'created' => count($items) - $duplicates,
            'duplicates' => $duplicates,
            'errors' => $errors,
        ], $errors !== [] ? 207 : ($duplicates === count($items) ? 200 : 201));
    }

    /** @return list<UploadedFileInterface> */
    private function files(Request $request): array
    {
        $uploads = $request->getUploadedFiles();
        $raw = $uploads['file'] ?? $uploads['files'] ?? null;
        $list = is_array($raw) ? array_values($raw) : ($raw instanceof UploadedFileInterface ? [$raw] : []);
        return array_values(array_filter($list, static fn(mixed $v): bool => $v instanceof UploadedFileInterface));
    }

    private function deny(Request $request, Response $response, bool $write = false): ?Response
    {
        $level = $write ? AccessLevel::WRITE : AccessLevel::READ;
        if (!RequestAuthorization::isClientType($request)
            || !RequestAuthorization::allows($request, 'documents.submit', $level)) {
            return Json::error($response, 'forbidden', 'K předávání dokladů nemáte oprávnění.', 403);
        }
        return null;
    }
}
