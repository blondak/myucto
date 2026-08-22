<?php

declare(strict_types=1);

namespace MyInvoice\Action\License;

use MyInvoice\Http\Json;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\License\LicenseService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * POST /api/license/upgrade {users} — in-place navýšení počtu uživatelů. Server
 * strhne poměrný doplatek z uložené karty a ihned navýší místa; lokálně se vynutí
 * obnova tokenu s vyšším limitem (admin only).
 */
final class UpgradeLicenseAction
{
    private const ERROR_MESSAGES = [
        'invalid_key'           => 'Aktivní licence nenalezena. Nejprve aktivujte licenční klíč.',
        'not_upgradable'        => 'Tuto licenci nelze navýšit.',
        'not_an_upgrade'        => 'Zadaný počet uživatelů není navýšením oproti aktuálnímu předplatnému.',
        'subscription_inactive' => 'Předplatné není aktivní. Zkontrolujte platbu na myucto.cz.',
        'no_parent_payment'     => 'Navýšení je možné jen u předplatného s uloženou kartou.',
        'charge_failed'         => 'Platbu se nepodařilo strhnout, zkontrolujte platební kartu.',
        'charge_pending'        => 'Platba se zpracovává. Nekupujte prosím znovu — jakmile ji brána potvrdí, změna se projeví sama.',
        'instance_required'     => 'U hostovaného provozu je pro navýšení nutné ověření této instalace.',
        'not_bound'             => 'Tato instalace není k licenci aktivně přiřazená.',
        'server_unreachable'    => 'Licenční server je nedostupný. Zkuste to prosím za chvíli.',
        'upgrade_failed'        => 'Navýšení se nezdařilo. Zkuste to prosím znovu.',
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
        $users = (int) ($body['users'] ?? 0);
        if ($users < 1) {
            return Json::error($response, 'validation_failed', 'Zadejte cílový počet uživatelů.', 400);
        }

        $result = $this->license->upgrade($users);
        if (($result['ok'] ?? false) !== true) {
            $error = (string) ($result['error'] ?? 'upgrade_failed');
            $message = self::ERROR_MESSAGES[$error] ?? self::ERROR_MESSAGES['upgrade_failed'];
            $status = $error === 'server_unreachable' ? 503 : 422;
            return Json::error($response, $error, $message, $status);
        }

        return Json::ok($response, [
            'new_users'      => $result['new_users'] ?? $users,
            'amount_charged' => $result['amount_charged'] ?? null,
            'state'          => $result['state']->toArray($this->license->buyUrl()),
        ]);
    }
}
