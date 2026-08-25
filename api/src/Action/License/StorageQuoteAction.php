<?php

declare(strict_types=1);

namespace MyInvoice\Action\License;

use MyInvoice\Http\Json;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\License\LicenseService;
use MyInvoice\Service\System\ManagedModeGuard;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * POST /api/license/storage/quote {quota_gb} — kolik by stálo rozšíření úložiště.
 * Nic nestrhává, jen počítá (admin only).
 *
 * ⚠️ `quota_gb` je CÍLOVÁ hodnota, ne přírůstek: „+5 GB" se posílá jako 7.
 */
final class StorageQuoteAction
{
    public function __construct(
        private readonly LicenseService $license,
        private readonly ManagedModeGuard $managed,
    ) {}

    public function __invoke(Request $request, Response $response): Response
    {
        if (!RequestAuthorization::isSuperadmin($request)) {
            return Json::error($response, 'forbidden', 'Pouze admin.', 403);
        }
        // Self-hosted instalace si místo objednává u svého poskytovatele, ne u nás.
        if (!$this->managed->isManaged()) {
            return Json::error($response, 'not_managed', 'Úložiště se dokupuje jen u provozu zajištěného námi.', 409);
        }

        $body = (array) ($request->getParsedBody() ?? []);
        $quotaGb = (int) ($body['quota_gb'] ?? 0);
        if ($quotaGb < 1) {
            return Json::error($response, 'validation_failed', 'Zadejte cílovou velikost úložiště.', 400);
        }

        $result = $this->license->storageQuote($quotaGb);
        if (($result['ok'] ?? false) !== true) {
            $error = (string) ($result['error'] ?? 'not_upgradable');
            $status = $error === 'server_unreachable' ? 503 : 422;

            return Json::error($response, $error, StorageUpgradeAction::message($error), $status);
        }

        return Json::ok($response, [
            'current_quota_gb' => $result['current_quota_gb'] ?? null,
            'new_quota_gb'     => $result['new_quota_gb'] ?? $quotaGb,
            'amount'           => $result['amount'] ?? null,
            'recurring_delta'  => $result['recurring_delta'] ?? null,
            'currency'         => $result['currency'] ?? null,
            'period_end'       => $result['period_end'] ?? null,
            'quote_token'      => $result['quote_token'] ?? null,
            'expires_at'       => $result['expires_at'] ?? null,
            'scheduled'        => ($result['scheduled'] ?? false) === true || ($result['change'] ?? '') === 'scheduled',
            'effective_at'     => $result['effective_at'] ?? null,
        ]);
    }
}
