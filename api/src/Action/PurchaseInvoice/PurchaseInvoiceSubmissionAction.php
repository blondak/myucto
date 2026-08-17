<?php

declare(strict_types=1);

namespace MyInvoice\Action\PurchaseInvoice;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\PurchaseInvoiceSubmissionRepository;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\PurchaseInvoice\PurchaseInvoiceSubmissionException;
use MyInvoice\Service\PurchaseInvoice\PurchaseInvoiceSubmissionProcessingService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/** Účetní fronta příchozích originálů. */
final class PurchaseInvoiceSubmissionAction
{
    private const STATUSES = ['submitted', 'processing', 'needs_information', 'processed', 'rejected'];

    public function __construct(
        private readonly PurchaseInvoiceSubmissionRepository $submissions,
        private readonly PurchaseInvoiceSubmissionProcessingService $processing,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function list(Request $request, Response $response): Response
    {
        if ($denied = $this->deny($request, $response)) return $denied;
        $q = $request->getQueryParams();
        $status = isset($q['status']) && in_array((string) $q['status'], self::STATUSES, true)
            ? (string) $q['status'] : null;
        return Json::ok($response, $this->submissions->paginate(
            SupplierGuard::currentId($request),
            $status,
            (int) ($q['limit'] ?? 50),
            (int) ($q['offset'] ?? 0),
        ));
    }

    public function get(Request $request, Response $response, array $args): Response
    {
        if ($denied = $this->deny($request, $response)) return $denied;
        $item = $this->submissions->find((int) ($args['id'] ?? 0), SupplierGuard::currentId($request));
        return $item !== null
            ? Json::ok($response, $item)
            : Json::error($response, 'not_found', 'Podání nebylo nalezeno.', 404);
    }

    public function extract(Request $request, Response $response, array $args): Response
    {
        if ($denied = $this->deny($request, $response, true)) return $denied;
        if (!RequestAuthorization::allows($request, 'purchase_invoices.create', AccessLevel::WRITE)) {
            return Json::error($response, 'forbidden', 'Nemáte oprávnění vytvořit přijatou fakturu.', 403);
        }
        $supplierId = SupplierGuard::currentId($request);
        $id = (int) ($args['id'] ?? 0);
        $userId = $this->userId($request);
        try {
            $result = $this->processing->extract(
                $id,
                $supplierId,
                $userId,
                RequestAuthorization::allows($request, 'purchase_invoices.scan', AccessLevel::WRITE),
            );
        } catch (PurchaseInvoiceSubmissionException $e) {
            $this->audit($request, 'purchase_invoice_submission.extraction_failed', $id, [
                'code' => $e->errorCode,
                'error' => $e->getMessage(),
            ]);
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        }
        $this->audit($request, 'purchase_invoice_submission.processed', $id, $result);
        return Json::ok($response, $this->submissions->find($id, $supplierId));
    }

    public function needsInformation(Request $request, Response $response, array $args): Response
    {
        return $this->review($request, $response, $args, 'needs_information');
    }

    public function reject(Request $request, Response $response, array $args): Response
    {
        return $this->review($request, $response, $args, 'rejected');
    }

    private function review(Request $request, Response $response, array $args, string $status): Response
    {
        if ($denied = $this->deny($request, $response, true)) return $denied;
        $supplierId = SupplierGuard::currentId($request);
        $id = (int) ($args['id'] ?? 0);
        $body = (array) ($request->getParsedBody() ?? []);
        $reason = trim((string) ($body['reason'] ?? ''));
        if ($reason === '') return Json::error($response, 'reason_required', 'Doplňte důvod pro klienta.', 400);
        $changed = $status === 'needs_information'
            ? $this->submissions->needsInformation($id, $supplierId, $reason)
            : $this->submissions->reject($id, $supplierId, $reason);
        if (!$changed) return Json::error($response, 'invalid_status', 'Stav podání se mezitím změnil.', 409);
        $this->audit($request, 'purchase_invoice_submission.' . $status, $id, ['reason' => $reason]);
        return Json::ok($response, $this->submissions->find($id, $supplierId));
    }

    private function deny(Request $request, Response $response, bool $write = false): ?Response
    {
        $level = $write ? AccessLevel::WRITE : AccessLevel::READ;
        if (RequestAuthorization::isClientType($request)
            || !RequestAuthorization::allows($request, 'documents.inbox', $level)) {
            return Json::error($response, 'forbidden', 'K příchozím dokladům nemáte oprávnění.', 403);
        }
        return null;
    }

    private function userId(Request $request): int
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        return max(0, (int) ($user['id'] ?? 0));
    }

    /** @param array<string,mixed> $context */
    private function audit(Request $request, string $event, int $id, array $context): void
    {
        $this->logger->log(
            $event,
            $this->userId($request) ?: null,
            'purchase_invoice_submission',
            $id,
            $context,
            $this->ipMatcher->clientIpFromRequest($request->getServerParams()),
            $request->getHeaderLine('User-Agent'),
            SupplierGuard::currentId($request),
        );
    }
}
