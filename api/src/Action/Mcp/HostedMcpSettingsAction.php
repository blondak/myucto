<?php

declare(strict_types=1);

namespace MyInvoice\Action\Mcp;

use MyInvoice\Http\Json;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Service\Mcp\HostedMcp;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class HostedMcpSettingsAction
{
    public function __construct(private readonly HostedMcp $hosted) {}

    public function show(Request $request, Response $response): Response
    {
        return Json::ok($response, $this->state());
    }

    public function update(Request $request, Response $response): Response
    {
        if ($request->getAttribute(AuthMiddleware::ATTR_METHOD) !== 'session') {
            return Json::sessionRequired($response);
        }
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        if (($user['is_superadmin'] ?? false) !== true) {
            return Json::error($response, 'forbidden_permission', 'Nastavení MCP může změnit pouze správce.', 403);
        }
        $body = (array) $request->getParsedBody();
        if (!array_key_exists('enabled', $body) || !is_bool($body['enabled'])) {
            return Json::error($response, 'invalid_enabled', 'Pole enabled musí být boolean.', 422);
        }
        if ($body['enabled'] && !$this->hosted->nodeAvailable()) {
            return Json::error($response, 'node_unavailable', 'Serverový MCP vyžaduje Node.js.', 422);
        }
        if ($this->hosted->managedByEnvironment()) {
            return Json::error($response, 'managed_by_environment', 'Nastavení MCP řídí proměnná prostředí.', 409);
        }
        $this->hosted->setEnabled($body['enabled']);
        return Json::ok($response, $this->state());
    }

    private function state(): array
    {
        return [
            'enabled' => $this->hosted->enabled(),
            'node_available' => $this->hosted->nodeAvailable(),
            'endpoint' => $this->hosted->endpoint(),
            'managed_by_environment' => $this->hosted->managedByEnvironment(),
        ];
    }
}
