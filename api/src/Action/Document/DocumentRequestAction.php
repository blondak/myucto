<?php

declare(strict_types=1);

namespace MyInvoice\Action\Document;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\DocumentRequestRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Vyžádání chybějících dokladů od klienta (Fáze F, audit 2026-07) — účetní pohled:
 *
 *   GET    /api/document-requests            — list (?status=requested,uploaded,resolved)
 *   POST   /api/document-requests             — ruční založení
 *   GET    /api/document-requests/{id}        — detail
 *   POST   /api/document-requests/{id}/resolve — uzavřít (i bez uploadu)
 *   POST   /api/document-requests/{id}/reopen  — vrátit na requested (chybný upload)
 *   DELETE /api/document-requests/{id}        — zrušit požadavek
 *
 * RBAC: PermissionMiddleware (accountant|admin CRUD, readonly GET). Klient sem nemá přístup
 * (terminální client permission rules větev) — používá zrcadlový PortalDocumentRequestAction.
 */
final class DocumentRequestAction
{
    public function __construct(
        private readonly DocumentRequestRepository $repo,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function list(Request $request, Response $response): Response
    {
        $supplierId = SupplierGuard::currentId($request);
        $statusParam = trim((string) ($request->getQueryParams()['status'] ?? ''));
        $statuses = $statusParam !== '' ? array_filter(array_map('trim', explode(',', $statusParam))) : [];
        return Json::ok($response, ['items' => $this->repo->listForSupplier($supplierId, $statuses)]);
    }

    public function get(Request $request, Response $response, array $args): Response
    {
        $supplierId = SupplierGuard::currentId($request);
        $item = $this->repo->find((int) ($args['id'] ?? 0), $supplierId);
        if ($item === null) return Json::error($response, 'not_found', 'Požadavek nenalezen.', 404);
        return Json::ok($response, $item);
    }

    public function create(Request $request, Response $response): Response
    {
        $supplierId = SupplierGuard::currentId($request);
        $body = (array) ($request->getParsedBody() ?? []);
        $err = $this->validate($body);
        if ($err !== null) return Json::error($response, 'validation_failed', $err, 400);

        $id = $this->repo->create($supplierId, [
            'description'  => trim((string) $body['description']),
            'amount'       => isset($body['amount']) && $body['amount'] !== '' ? round((float) $body['amount'], 2) : null,
            'context_date' => $this->nullableDate($body['context_date'] ?? null),
            'deadline'     => $this->nullableDate($body['deadline'] ?? null),
            'bank_transaction_id' => null,
        ], $this->userId($request));

        $this->log($request, 'document_request.created', $id, ['description' => $body['description']], $supplierId);
        return Json::ok($response, $this->repo->find($id, $supplierId), 201);
    }

    public function resolve(Request $request, Response $response, array $args): Response
    {
        $supplierId = SupplierGuard::currentId($request);
        $id = (int) ($args['id'] ?? 0);
        if ($this->repo->find($id, $supplierId) === null) {
            return Json::error($response, 'not_found', 'Požadavek nenalezen.', 404);
        }
        $this->repo->resolve($id, $supplierId, $this->userId($request));
        $this->log($request, 'document_request.resolved', $id, [], $supplierId);
        return Json::ok($response, $this->repo->find($id, $supplierId));
    }

    public function reopen(Request $request, Response $response, array $args): Response
    {
        $supplierId = SupplierGuard::currentId($request);
        $id = (int) ($args['id'] ?? 0);
        if ($this->repo->find($id, $supplierId) === null) {
            return Json::error($response, 'not_found', 'Požadavek nenalezen.', 404);
        }
        $this->repo->reopen($id, $supplierId);
        $this->log($request, 'document_request.reopened', $id, [], $supplierId);
        return Json::ok($response, $this->repo->find($id, $supplierId));
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        $supplierId = SupplierGuard::currentId($request);
        $id = (int) ($args['id'] ?? 0);
        if ($this->repo->find($id, $supplierId) === null) {
            return Json::error($response, 'not_found', 'Požadavek nenalezen.', 404);
        }
        $this->repo->delete($id, $supplierId);
        $this->log($request, 'document_request.deleted', $id, [], $supplierId);
        return Json::ok($response, ['deleted' => true]);
    }

    private function validate(array $body): ?string
    {
        $description = trim((string) ($body['description'] ?? ''));
        if ($description === '') return 'Popis je povinný.';
        if (mb_strlen($description) > 500) return 'Popis je příliš dlouhý (max 500 znaků).';
        if (isset($body['amount']) && $body['amount'] !== '' && !is_numeric($body['amount'])) {
            return 'Částka musí být číslo.';
        }
        return null;
    }

    private function nullableDate(mixed $v): ?string
    {
        $s = trim((string) ($v ?? ''));
        return $s === '' ? null : $s;
    }

    private function userId(Request $request): ?int
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $id = (int) ($user['id'] ?? 0);
        return $id > 0 ? $id : null;
    }

    /** @param array<string,mixed> $payload */
    private function log(Request $request, string $action, int $id, array $payload, int $supplierId): void
    {
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log($action, $this->userId($request), 'document_request', $id, $payload, $ip, $request->getHeaderLine('User-Agent'), $supplierId);
    }
}
