<?php

declare(strict_types=1);

namespace MyInvoice\Action\License;

use MyInvoice\Http\Json;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\License\LicenseService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * POST /api/license/period {quote_token} — přechod z měsíčního předplatného na roční
 * (admin only). Licenční server strhne roční cenu z uložené karty a prodlouží
 * platnost o rok od konce už zaplaceného měsíce.
 *
 * Opačný směr neexistuje a už zaplacenou roční licenci nelze prodloužit dopředu;
 * server takový požadavek odmítne chybou `already_annual`.
 */
final class AnnualSwitchAction
{
    public function __construct(private readonly LicenseService $license) {}

    public function __invoke(Request $request, Response $response): Response
    {
        if (!RequestAuthorization::isSuperadmin($request)) {
            return Json::error($response, 'forbidden', 'Pouze admin.', 403);
        }
        $body = (array) ($request->getParsedBody() ?? []);
        $quoteToken = trim((string) ($body['quote_token'] ?? ''));
        if ($quoteToken === '') {
            return Json::error($response, 'quote_required', 'Nejdříve si nechte spočítat aktuální cenu.', 400);
        }
        $result = $this->license->switchToAnnual($quoteToken);
        if (($result['ok'] ?? false) !== true) {
            $error = (string) ($result['error'] ?? 'change_failed');
            $message = $error === 'charge_failed'
                ? 'Platbu se nepodařilo strhnout z uložené karty. Zaplatíte ji jinou kartou '
                    . 'přes odkaz níž — poslali jsme ho i e-mailem.'
                : 'Přechod na roční předplatné se nezdařil.';
            // ⚠️ `pay_url` musí projít až na obrazovku — viz TierChangeAction.
            $extra = isset($result['pay_url']) ? ['pay_url' => (string) $result['pay_url']] : [];
            return Json::error($response, $error, $message, $error === 'server_unreachable' ? 503 : 422, $extra);
        }
        $state = $result['state_local'] ?? $this->license->current();
        unset($result['ok'], $result['state_local']);
        if (isset($result['order_id'])) {
            $result['order_id'] = (string) $result['order_id'];
        }
        $result['state'] = $state->toArray($this->license->buyUrl());
        return Json::ok($response, $result);
    }
}
