<?php

declare(strict_types=1);

namespace MyInvoice\Action\Mcp;

use MyInvoice\Http\Json;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\Mcp\McpOAuth;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class McpOAuthGrantsAction
{
    public function __construct(private readonly McpOAuth $oauth) {}

    public function listing(Request $request, Response $response): Response
    {
        $userId = $this->sessionUserId($request);
        if ($userId <= 0) return Json::sessionRequired($response);
        return Json::ok($response, ['grants' => $this->oauth->listForUser($userId)])
            ->withHeader('Cache-Control', 'no-store');
    }

    public function revoke(Request $request, Response $response, array $args): Response
    {
        $userId = $this->sessionUserId($request);
        if ($userId <= 0) return Json::sessionRequired($response);
        $id = (int) ($args['id'] ?? 0);
        if ($id <= 0 || !$this->oauth->revokeForUser($id, $userId)) {
            return Json::error($response, 'not_found', 'Připojení nebylo nalezeno.', 404);
        }
        return Json::ok($response, ['revoked' => true]);
    }

    private function sessionUserId(Request $request): int
    {
        if (!RequestAuthorization::isSessionAuth($request)) return 0;
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        return (int) ($user['id'] ?? 0);
    }
}
