<?php

declare(strict_types=1);

namespace MyInvoice\Action\License;

use MyInvoice\Http\Json;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\License\LicenseService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * POST /api/license/upgrade/quote {users} — poměrný doplatek za navýšení počtu
 * uživatelů (jen kalkulace, nic se nestrhává; admin only).
 */
final class UpgradeQuoteLicenseAction
{
    private const ERROR_MESSAGES = [
        'invalid_key'           => 'Aktivní licence nenalezena. Nejprve aktivujte licenční klíč.',
        'not_upgradable'        => 'Tuto licenci nelze navýšit.',
        'not_an_upgrade'        => 'Zadaný počet uživatelů není navýšením oproti aktuálnímu předplatnému.',
        'subscription_inactive' => 'Předplatné není aktivní. Zkontrolujte platbu na myucto.cz.',
        'no_parent_payment'     => 'Navýšení je možné jen u předplatného s uloženou kartou.',
        'server_unreachable'    => 'Licenční server je nedostupný. Zkuste to prosím za chvíli.',
        'quote_failed'          => 'Výpočet doplatku se nezdařil. Zkuste to prosím znovu.',
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

        $result = $this->license->upgradeQuote($users);
        if (($result['ok'] ?? false) !== true) {
            $error = (string) ($result['error'] ?? 'quote_failed');
            $message = self::ERROR_MESSAGES[$error] ?? self::ERROR_MESSAGES['quote_failed'];
            $status = $error === 'server_unreachable' ? 503 : 422;
            return Json::error($response, $error, $message, $status);
        }

        return Json::ok($response, [
            'current_users' => $result['current_users'] ?? null,
            'new_users'     => $result['new_users'] ?? $users,
            'amount'        => $result['amount'] ?? null,
            'currency'      => $result['currency'] ?? null,
            'period_end'    => $result['period_end'] ?? null,
        ]);
    }
}
