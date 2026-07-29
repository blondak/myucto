<?php

declare(strict_types=1);

namespace MyInvoice\Action\Invoice;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Penalty\PenaltyInvoiceService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Penalizace faktury po splatnosti (úrok z prodlení dle NV č. 351/2013 Sb.):
 *
 *   GET  /api/invoices/{id}/penalty/preview  — náhled výpočtu úroku (nemodifikuje)
 *   POST /api/invoices/{id}/penalty          — založí penalizační fakturu (draft)
 *
 * Volitelné parametry: `as_of` (datum, ke kterému se úrok počítá; default dnes),
 * `principal` (override jistiny; default zbývající dlužná částka faktury).
 */
final class PenaltyInvoiceAction
{
    public function __construct(
        private readonly InvoiceRepository $repo,
        private readonly PenaltyInvoiceService $service,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function preview(Request $request, Response $response, array $args): Response
    {
        $source = $this->repo->find((int) ($args['id'] ?? 0));
        if (!SupplierGuard::owns($request, $source)) {
            return Json::error($response, 'not_found', 'Faktura nenalezena.', 404);
        }

        $q = $request->getQueryParams();
        try {
            $preview = $this->service->preview(
                $source,
                isset($q['as_of']) ? (string) $q['as_of'] : null,
                isset($q['principal']) ? (float) $q['principal'] : null,
            );
        } catch (\DomainException $e) {
            return Json::error($response, 'invalid_state', $e->getMessage(), 409);
        }

        return Json::ok($response, $preview);
    }

    public function create(Request $request, Response $response, array $args): Response
    {
        $source = $this->repo->find((int) ($args['id'] ?? 0));
        if (!SupplierGuard::owns($request, $source)) {
            return Json::error($response, 'not_found', 'Faktura nenalezena.', 404);
        }

        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $userId = (int) ($user['id'] ?? 0);
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $body = (array) ($request->getParsedBody() ?? []);

        try {
            $invoice = $this->service->create(
                $source,
                $userId,
                isset($body['as_of']) ? (string) $body['as_of'] : null,
                isset($body['principal']) ? (float) $body['principal'] : null,
                $ip,
                $request->getHeaderLine('User-Agent'),
            );
        } catch (\DomainException $e) {
            return Json::error($response, 'invalid_state', $e->getMessage(), 409);
        }

        return Json::ok($response, $invoice, 201);
    }
}
