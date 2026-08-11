<?php

declare(strict_types=1);

namespace MyInvoice\Action\License;

use MyInvoice\Http\Json;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\License\LicenseService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * POST /api/license/cancel-renewal — vypne automatické prodlužování licence (admin only).
 *
 * Licence zůstává aktivní do konce zaplaceného období (`valid_until` se nemění),
 * jen se nestrhne další platba. Opakované volání je úspěch (idempotence na serveru).
 */
final class CancelRenewalLicenseAction
{
    private const ERROR_MESSAGES = [
        'invalid_key'        => 'Instalace nemá aktivní licenční klíč — není co rušit.',
        'not_bound'          => 'Licence není navázaná na tuto instalaci.',
        'no_subscription'    => 'K této licenci nepatří žádné předplatné (doživotní nebo ručně vystavená licence).',
        'server_unreachable' => 'Licenční server je nedostupný. Zkuste to prosím za chvíli.',
        'cancel_failed'      => 'Zrušení automatického prodlužování se nezdařilo. Zkuste to prosím znovu.',
    ];

    public function __construct(
        private readonly LicenseService $license,
    ) {}

    public function __invoke(Request $request, Response $response): Response
    {
        if (!RequestAuthorization::isSuperadmin($request)) {
            return Json::error($response, 'forbidden', 'Pouze admin.', 403);
        }

        $result = $this->license->cancelRenewal();
        if (($result['ok'] ?? false) !== true) {
            $error = (string) ($result['error'] ?? 'cancel_failed');
            $message = self::ERROR_MESSAGES[$error] ?? self::ERROR_MESSAGES['cancel_failed'];
            $status = $error === 'server_unreachable' ? 503 : 422;
            return Json::error($response, $error, $message, $status);
        }

        return Json::ok($response, [
            'already_cancelled' => (bool) ($result['already_cancelled'] ?? false),
            'valid_until'       => $result['valid_until'] ?? null,
            'state'             => $result['state']->toArray($this->license->buyUrl()),
        ]);
    }
}
