<?php

declare(strict_types=1);

namespace MyInvoice\Action\License;

use MyInvoice\Http\Json;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\License\LicenseService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * POST /api/license/activate {license_key} — aktivace licenčním klíčem (admin only).
 */
final class ActivateLicenseAction
{
    private const ERROR_MESSAGES = [
        'invalid_key'          => 'Neplatný licenční klíč.',
        'already_bound'        => 'Tato licence je aktivní na jiné instalaci.',
        'transfer_limit'       => 'Vyčerpali jste limit přenosů (2 za 30 dní). Napište nám na info@myucto.cz.',
        'subscription_inactive' => 'Předplatné není aktivní. Zkontrolujte platbu na myucto.cz.',
        'server_unreachable'   => 'Licenční server je nedostupný. Zkuste to prosím za chvíli.',
        'invalid_token'        => 'Server vrátil neplatný token. Kontaktujte podporu.',
        'activation_failed'    => 'Aktivace se nezdařila. Zkuste to prosím znovu.',
    ];

    public function __construct(
        private readonly LicenseService $license,
    ) {}

    public function __invoke(Request $request, Response $response): Response
    {
        if (!RequestAuthorization::isSuperadmin($request)) {
            return Json::error($response, 'forbidden', 'Pouze admin.', 403);
        }

        $body = (array) ($request->getParsedBody() ?? []);
        $key = trim((string) ($body['license_key'] ?? ''));
        if ($key === '') {
            return Json::error($response, 'validation_failed', 'Zadejte licenční klíč.', 400);
        }
        // Přenos vazby z jiné instalace (po `already_bound`) — vyžádaný uživatelem ve FE.
        $takeover = filter_var($body['takeover'] ?? false, FILTER_VALIDATE_BOOLEAN);

        $result = $this->license->activate($key, $takeover);
        if (($result['ok'] ?? false) !== true) {
            $error = (string) ($result['error'] ?? 'activation_failed');
            $message = self::ERROR_MESSAGES[$error] ?? self::ERROR_MESSAGES['activation_failed'];
            $status = $error === 'server_unreachable' ? 503 : 422;
            // transfers_remaining necháme projít do UI (kolik přenosů ještě zbývá).
            $extra = isset($result['transfers_remaining'])
                ? ['transfers_remaining' => (int) $result['transfers_remaining']]
                : [];
            return Json::error($response, $error, $message, $status, $extra);
        }

        return Json::ok($response, $result['state']->toArray($this->license->buyUrl()));
    }
}
