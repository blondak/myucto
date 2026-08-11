<?php

declare(strict_types=1);

namespace MyInvoice\Action\Client;

use MyInvoice\Http\Json;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\ClientRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * FR 2 (vendor bugreport 2026-08-06) — report existujících duplicitních
 * karet dodavatele/odběratele v přehledu klientů: skupiny karet tenanta se shodným
 * IČO nebo DIČ po normalizaci (bez mezer, bez úvodní nuly u IČO). Typicky vzniklé
 * PŘED opravou create/update guardu (viz {@see CreateClientAction}), kdy import
 * jednou uložil DIČ s mezerou a podruhé bez ní.
 */
final class ClientDuplicatesAction
{
    public function __construct(private readonly ClientRepository $repo) {}

    public function __invoke(Request $request, Response $response): Response
    {
        $supplierId = (int) $request->getAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 0);
        $groups = $this->repo->findDuplicateGroups($supplierId);

        return Json::ok($response, [
            'groups' => $groups,
            'count'  => count($groups),
        ]);
    }
}
