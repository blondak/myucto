<?php

declare(strict_types=1);

namespace MyInvoice\Action\License;

use MyInvoice\Http\Json;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\License\LicenseService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class ChangeStatusAction
{
    public function __construct(private readonly LicenseService $license) {}

    public function __invoke(Request $request, Response $response): Response
    {
        if (!RequestAuthorization::isSuperadmin($request)) {
            return Json::error($response, 'forbidden', 'Pouze admin.', 403);
        }
        $orderId = trim((string) (((array) ($request->getParsedBody() ?? []))['order_id'] ?? ''));
        if ($orderId === '' || strlen($orderId) > 100) {
            return Json::error($response, 'validation_failed', 'Chybí identifikátor změny.', 400);
        }
        $result = $this->license->changeStatus($orderId);
        if (($result['ok'] ?? false) !== true) {
            $error = (string) ($result['error'] ?? 'status_failed');
            return Json::error($response, $error, 'Stav změny se nepodařilo ověřit.', $error === 'server_unreachable' ? 503 : 422);
        }
        if (isset($result['state_local'])) {
            $result['license'] = $result['state_local']->toArray($this->license->buyUrl());
            unset($result['state_local']);
        }
        unset($result['ok']);
        $result['order_id'] = (string) ($result['order_id'] ?? $orderId);
        return Json::ok($response, $result);
    }
}
