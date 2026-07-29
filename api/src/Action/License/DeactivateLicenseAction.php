<?php

declare(strict_types=1);

namespace MyInvoice\Action\License;

use MyInvoice\Http\Json;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\License\LicenseService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * POST /api/license/deactivate — uvolní vazbu na serveru a smaže klíč lokálně (admin only).
 */
final class DeactivateLicenseAction
{
    public function __construct(
        private readonly LicenseService $license,
    ) {}

    public function __invoke(Request $request, Response $response): Response
    {
        if (!RequestAuthorization::isSuperadmin($request)) {
            return Json::error($response, 'forbidden', 'Pouze admin.', 403);
        }

        $result = $this->license->deactivate();
        return Json::ok($response, [
            'transfers_remaining' => $result['transfers_remaining'],
            'state'               => $result['state']->toArray($this->license->buyUrl()),
        ]);
    }
}
