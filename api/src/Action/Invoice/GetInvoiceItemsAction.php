<?php

declare(strict_types=1);

namespace MyInvoice\Action\Invoice;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Repository\InvoiceRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class GetInvoiceItemsAction
{
    public function __construct(private readonly InvoiceRepository $repo) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $items = $this->repo->itemsForSupplier((int) ($args['id'] ?? 0), SupplierGuard::currentId($request));
        if ($items === null) {
            return Json::error($response, 'not_found', 'Faktura nenalezena.', 404);
        }
        return Json::ok($response, ['items' => $items]);
    }
}
