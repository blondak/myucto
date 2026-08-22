<?php

declare(strict_types=1);

namespace MyInvoice\Action\License;

use MyInvoice\Http\Json;
use MyInvoice\Middleware\LicenseMiddleware;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\License\BillingSnapshot;
use MyInvoice\Service\License\LicenseService;
use MyInvoice\Service\System\ManagedModeGuard;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * GET /api/license/billing — stav neuhrazené platby pro BĚŽNÉHO admina.
 *
 * Zbytek `/api/license/*` je superadmin-only a má to tak zůstat: klíč, počty
 * míst i fakturační údaje jsou věc majitele instalace. Jenže „nezdařila se
 * platba, do 14 dnů se instalace pozastaví" je informace, kterou musí vidět
 * ten, kdo instalaci reálně spravuje — jinak se o dluhu dozví až tím, že
 * aplikace přestane fungovat. Doplatit se to z aplikace jinak NEDÁ.
 *
 * ⚠️ Tři pravidla, na kterých ten kompromis stojí:
 *
 *  1. **Ven jde jen dunning výřez** ({@see BillingSnapshot::dunning()}) —
 *     stav předplatného, fáze a termíny, dlužná částka a odkaz na úhradu.
 *     Nikdy licenční klíč, fakturační údaje ani obsazení míst.
 *  2. **Klientské účty ne.** Portál odběratele je cizí člověk v našich datech;
 *     o platbách provozovatele instalace mu nic není.
 *  3. **Self-hosted vrací `null`.** Ne prázdný objekt a ne 404: obrazovka se
 *     smí zeptat vždycky a odpověď „tady se nic neplatí" je legitimní stav.
 */
final class LicenseBillingAction
{
    public function __construct(
        private readonly LicenseService $license,
        private readonly ManagedModeGuard $managed,
        private readonly BillingSnapshot $snapshot,
    ) {}

    public function __invoke(Request $request, Response $response): Response
    {
        if (RequestAuthorization::isClientType($request)) {
            return Json::error($response, 'forbidden', 'Nepřístupné.', 403);
        }

        if (!$this->managed->isManaged()) {
            return Json::ok($response, ['billing' => null]);
        }

        $state = LicenseMiddleware::state($request) ?? $this->license->current();

        return Json::ok($response, ['billing' => $this->snapshot->dunning($state)]);
    }
}
