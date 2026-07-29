<?php

declare(strict_types=1);

namespace MyInvoice\Action\PurchaseInvoice;

use MyInvoice\Http\GuardsDocumentLock;
use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\PurchaseInvoiceRepository;
use MyInvoice\Service\Accounting\DocumentLockService;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Invoice\PurchaseInvoiceCalculator;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Validation\InvoiceAmountPolicy;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * PUT /api/purchase-invoices/{id}/items
 *
 * Replace items pro draft. Pro forms, které dělají edit items odděleně od hlavičky.
 * Pro draft only — vystavené faktury jsou immutable bez force.
 */
final class SetPurchaseInvoiceItemsAction
{
    use GuardsDocumentLock;

    public function __construct(
        private readonly PurchaseInvoiceRepository $repo,
        private readonly PurchaseInvoiceCalculator $calc,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
        private readonly DocumentLockService $locks,
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

        // Zámek dokladu (Epic F6) — PŘED status guardem (klient dostane 403 document_locked).
        if ($deny = $this->denyIfLocked($request, $response, $this->locks->forPurchaseInvoice($existing), 'purchase_invoice', $id)) {
            return $deny;
        }

        if ($existing['status'] !== 'draft') {
            return Json::error($response, 'not_editable', 'Items lze upravovat pouze u konceptu.', 409);
        }

        $body = (array) ($request->getParsedBody() ?? []);
        $items = $body['items'] ?? [];
        if (!is_array($items)) {
            return Json::error($response, 'validation_failed', 'items musí být pole', 400, ['fields' => ['items' => ['items musí být pole']]]);
        }

        $vatRates = $this->repo->vatRateMap();
        $errors = [];
        foreach (array_values($items) as $i => $item) {
            if (!is_array($item)) {
                $errors["items.{$i}"][] = 'Neplatná položka';
                continue;
            }
            $errors = array_merge($errors, InvoiceAmountPolicy::validateItem($item, $i));
            $rateId = (int) ($item['vat_rate_id'] ?? 0);
            if ($rateId === 0 || !array_key_exists($rateId, $vatRates)) {
                $errors["items.{$i}.vat_rate_id"][] = 'Neznámá DPH sazba';
            }
        }
        if (!empty($errors)) {
            return Json::error($response, 'validation_failed', 'Validace selhala', 400, ['fields' => $errors]);
        }

        $this->repo->replaceItems($id, $items);
        $this->calc->recompute($id);

        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('purchase_invoice.items_updated', $user['id'] ?? null, 'purchase_invoice', $id,
            ['count' => count($items)], $ip, $request->getHeaderLine('User-Agent'));

        return Json::ok($response, $this->repo->find($id, $supplierId));
    }
}
