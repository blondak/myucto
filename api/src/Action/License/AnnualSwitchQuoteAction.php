<?php

declare(strict_types=1);

namespace MyInvoice\Action\License;

use MyInvoice\Http\Json;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\License\LicenseService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * POST /api/license/period/quote — kolik stojí přechod na roční předplatné
 * a dokdy pak licence platí (admin only, nic nestrhává).
 */
final class AnnualSwitchQuoteAction
{
    public function __construct(private readonly LicenseService $license) {}

    public function __invoke(Request $request, Response $response): Response
    {
        if (!RequestAuthorization::isSuperadmin($request)) {
            return Json::error($response, 'forbidden', 'Pouze admin.', 403);
        }
        $result = $this->license->annualSwitchQuote();
        if (($result['ok'] ?? false) !== true) {
            $error = (string) ($result['error'] ?? 'quote_failed');
            return Json::error(
                $response,
                $error,
                'Přechod na roční předplatné se nepodařilo spočítat.',
                $error === 'server_unreachable' ? 503 : 422,
            );
        }
        unset($result['ok']);
        return Json::ok($response, $result);
    }
}
