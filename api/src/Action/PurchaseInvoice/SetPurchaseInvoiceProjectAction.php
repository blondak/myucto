<?php

declare(strict_types=1);

namespace MyInvoice\Action\PurchaseInvoice;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Http\TenantReferenceGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\PurchaseInvoiceRepository;
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * POST /api/purchase-invoices/{id}/project — zařazení dokladu k zakázce (issue #29).
 *
 * Body: { project_id: number|null }
 *
 * ## Proč to není součást PUT /api/purchase-invoices/{id}
 *
 * Zařazení k akci přichází typicky AŽ po zaúčtování: účetní došlou fakturu zaúčtuje,
 * projektový vedoucí ji pak přiřadí k akci. Plný PUT je u zaúčtovaného dokladu
 * dostupný jen adminovi s `?force=1` (§35 — doklad × deník se nesmí rozejít), takže
 * přes něj by ekonomika akcí v praxi nikdy nevznikla.
 *
 * Zakázka je přitom čistě ANALYTICKÁ dimenze — nemění účet, stranu, částku, období
 * ani DPH. Zápis v deníku proto zůstává co do účetního obsahu identický a §35 se
 * neporušuje; jen se stejnou hodnotou přerazítkují jeho řádky
 * ({@see PostingService::restampProjectDimension}), aby sestava a doklad říkaly totéž.
 *
 * Z téhož důvodu tu NENÍ zámek období (`GuardsDocumentLock`): dimenze nemá vliv na
 * žádný uzavřený výkaz — zakázku lze doplnit i u dokladu v uzamčeném období.
 * Stornovaný doklad se ale k akci nepřiřazuje (nic už nenese).
 */
final class SetPurchaseInvoiceProjectAction
{
    public function __construct(
        private readonly PurchaseInvoiceRepository $repo,
        private readonly PostingService $posting,
        private readonly TenantReferenceGuard $tenantRefs,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        if ($id <= 0) {
            return Json::error($response, 'invalid_id', 'Neplatné ID', 400);
        }

        $supplierId = SupplierGuard::currentId($request);
        $existing = $this->repo->find($id, $supplierId);
        if ($existing === null) {
            return Json::error($response, 'not_found', 'Přijatá faktura nenalezena.', 404);
        }
        if (($existing['status'] ?? '') === 'cancelled') {
            return Json::error($response, 'not_editable', 'Stornovaný doklad nelze zařadit k zakázce.', 409);
        }

        $body = (array) ($request->getParsedBody() ?? []);
        $projectId = ($body['project_id'] ?? null) !== null && (int) $body['project_id'] > 0
            ? (int) $body['project_id']
            : null;

        if ($projectId !== null) {
            $bad = $this->tenantRefs->violations($supplierId, ['project_id' => $projectId], ['project_id']);
            if ($bad !== []) {
                return Json::error($response, 'invalid_reference', TenantReferenceGuard::message($bad), 400);
            }
        }

        $before = ($existing['project_id'] ?? null) !== null ? (int) $existing['project_id'] : null;
        if ($before === $projectId) {
            return Json::ok($response, $this->repo->find($id, $supplierId));
        }

        $this->repo->updateProject($id, $supplierId, $projectId);
        $restamped = $this->posting->restampProjectDimension($supplierId, 'purchase_invoice', $id, $projectId);

        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('purchase_invoice.project_changed', $user['id'] ?? null, 'purchase_invoice', $id, [
            'from'                 => $before,
            'to'                   => $projectId,
            'journal_lines_updated' => $restamped,
        ], $ip, $request->getHeaderLine('User-Agent'));

        return Json::ok($response, $this->repo->find($id, $supplierId));
    }
}
