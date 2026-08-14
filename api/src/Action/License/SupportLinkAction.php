<?php

declare(strict_types=1);

namespace MyInvoice\Action\License;

use MyInvoice\Http\Json;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\License\LicenseService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * POST /api/license/support-link — odkaz na portál podpory na myucto.cz (admin only).
 *
 * U placené licence vrací jednorázový přihlašovací odkaz, na kterém je zákazník
 * rovnou identifikovaný jako firma platící licenci. Jinak (trial, degradovaná
 * licence, nedostupný licenční server) vrací prostý veřejný odkaz — přechod na
 * podporu se nikdy nesmí zvrhnout v chybu, proto akce nemá chybovou větev.
 *
 * Fakturační údaje aktuální firmy (X-Supplier-Id) se přikládají jen jako ZÁLOŽNÍ
 * předvyplnění portálu — stejný zdroj, jaký používá {@see LicenseStatusAction}.
 */
final class SupportLinkAction
{
    public function __construct(
        private readonly LicenseService $license,
    ) {}

    public function __invoke(Request $request, Response $response): Response
    {
        if (!RequestAuthorization::isSuperadmin($request)) {
            return Json::error($response, 'forbidden', 'Pouze admin.', 403);
        }

        $supplierId = (int) $request->getAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 0);

        return Json::ok($response, $this->license->supportLink($supplierId));
    }
}
