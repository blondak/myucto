<?php

declare(strict_types=1);

namespace MyInvoice\Action\License;

use MyInvoice\Http\Json;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\License\LicenseService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class TierQuoteAction
{
    private const TIERS = ['single', 'multi10', 'unlimited'];

    public function __construct(private readonly LicenseService $license) {}

    public function __invoke(Request $request, Response $response): Response
    {
        if (!RequestAuthorization::isSuperadmin($request)) {
            return Json::error($response, 'forbidden', 'Pouze admin.', 403);
        }
        $tier = trim((string) (((array) ($request->getParsedBody() ?? []))['tier'] ?? ''));
        if (!in_array($tier, self::TIERS, true)) {
            return Json::error($response, 'validation_failed', 'Zvolte platný tarif.', 400);
        }
        $result = $this->license->tierQuote($tier);
        if (($result['ok'] ?? false) !== true) {
            $error = (string) ($result['error'] ?? 'quote_failed');
            return Json::error($response, $error, 'Změnu tarifu se nepodařilo spočítat.', $error === 'server_unreachable' ? 503 : 422);
        }
        unset($result['ok']);
        return Json::ok($response, $result);
    }
}
