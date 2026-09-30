<?php

declare(strict_types=1);

namespace MyInvoice\Action\PurchaseInvoice;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\Accounting\DocumentLockService;
use MyInvoice\Service\Accounting\JournalSourceSummaryService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class GetPurchaseInvoicePreviewAction
{
    public function __construct(
        private readonly JournalSourceSummaryService $summaries,
        private readonly DocumentLockService $locks,
    ) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        if (!RequestAuthorization::allows($request, 'purchase_invoices', AccessLevel::READ)) {
            return Json::error($response, 'forbidden', 'Chybí oprávnění ke čtení přijatých faktur.', 403);
        }
        $supplierId = SupplierGuard::currentId($request);
        $id = (int) ($args['id'] ?? 0);
        $summary = $this->summaries->purchaseInvoicePreview($supplierId, $id);
        if ($summary === null) {
            return Json::error($response, 'not_found', 'Přijatá faktura nenalezena.', 404);
        }
        $entryId = 0;
        if (RequestAuthorization::allows($request, 'accounting', AccessLevel::READ)) {
            $map = $this->locks->lockedMapForSources($supplierId, 'purchase_invoice', [$id]);
            $entryId = $map[$id]->journalEntryId ?? 0;
        }
        return Json::ok($response, ['entry_id' => $entryId, ...$summary]);
    }
}
