<?php

declare(strict_types=1);

namespace MyInvoice\Action\Invoice;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Repository\JournalEntryRepository;
use MyInvoice\Service\Accounting\DocumentLockService;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Ruční book/unbook vydané faktury (Epic F6, §4.6) — nutné pro tax_evidence firmy
 * (bez journalu a období je booked_at jediný mechanismus zámku pro roli client).
 *
 *   POST   /api/invoices/{id}/book — booked_at = NOW(), booked_by = user; jen non-draft; idempotentní
 *   DELETE /api/invoices/{id}/book — smaže booked_at; 409 'still_posted' při aktivním posted zápisu
 *
 * RBAC: kryje stávající route permission rules; v client permission rules cesty nejsou → klient 403.
 */
final class BookInvoiceAction
{
    public function __construct(
        private readonly InvoiceRepository $repo,
        private readonly Connection $db,
        private readonly JournalEntryRepository $journal,
        private readonly DocumentLockService $locks,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function book(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        $invoice = $this->repo->find($id);
        if (!SupplierGuard::owns($request, $invoice)) {
            return Json::error($response, 'not_found', 'Faktura nenalezena.', 404);
        }
        if ($invoice['status'] === 'draft') {
            return Json::error($response, 'not_draft', 'Koncept nelze označit jako zaúčtovaný.', 409);
        }

        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $userId = (int) ($user['id'] ?? 0);

        // Idempotentní — existující booked_at/booked_by se nepřepisuje.
        $this->db->pdo()->prepare(
            'UPDATE invoices
                SET booked_at = COALESCE(booked_at, NOW()),
                    booked_by = COALESCE(booked_by, ?)
              WHERE id = ? AND supplier_id = ?'
        )->execute([$userId > 0 ? $userId : null, $id, (int) $invoice['supplier_id']]);

        if (empty($invoice['booked_at'])) {
            $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
            $this->logger->log('invoice.booked', $userId > 0 ? $userId : null, 'invoice', $id, [
                'varsymbol' => $invoice['varsymbol'] ?? null,
            ], $ip, $request->getHeaderLine('User-Agent'));
        }

        return $this->respondWithInvoice($response, $id);
    }

    public function unbook(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        $invoice = $this->repo->find($id);
        if (!SupplierGuard::owns($request, $invoice)) {
            return Json::error($response, 'not_found', 'Faktura nenalezena.', 404);
        }

        $supplierId = (int) $invoice['supplier_id'];

        // Aktivní posted zápis drží zámek — nejdřív reverse v deníku (§4.6).
        $entry = $this->journal->findBySource($supplierId, 'invoice', $id);
        if ($entry !== null && $entry['posted_at'] !== null && $entry['reversed_by'] === null) {
            return Json::error(
                $response,
                'still_posted',
                'Doklad má aktivní účetní zápis — nejdřív ho stornuj v deníku.',
                409,
            );
        }

        $stmt = $this->db->pdo()->prepare(
            'UPDATE invoices SET booked_at = NULL, booked_by = NULL
              WHERE id = ? AND supplier_id = ? AND booked_at IS NOT NULL'
        );
        $stmt->execute([$id, $supplierId]);

        if ($stmt->rowCount() > 0) {
            $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
            $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
            $this->logger->log('invoice.unbooked', isset($user['id']) ? (int) $user['id'] : null, 'invoice', $id, [
                'varsymbol'          => $invoice['varsymbol'] ?? null,
                'previous_booked_at' => $invoice['booked_at'] ?? null,
            ], $ip, $request->getHeaderLine('User-Agent'));
        }

        return $this->respondWithInvoice($response, $id);
    }

    private function respondWithInvoice(Response $response, int $id): Response
    {
        $invoice = $this->repo->find($id);
        if ($invoice !== null) {
            $invoice['locked'] = $this->locks->forInvoice($invoice)->toArray();
        }
        return Json::ok($response, $invoice);
    }
}
