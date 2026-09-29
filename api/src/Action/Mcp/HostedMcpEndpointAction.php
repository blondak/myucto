<?php

declare(strict_types=1);

namespace MyInvoice\Action\Mcp;

use MyInvoice\Http\Json;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Service\Mcp\HostedMcp;
use MyInvoice\Service\Mcp\NodeBridge;
use Psr\Log\LoggerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class HostedMcpEndpointAction
{
    public function __construct(
        private readonly HostedMcp $hosted,
        private readonly NodeBridge $bridge,
        private readonly LoggerInterface $logger,
    ) {}

    public function handle(Request $request, Response $response): Response
    {
        if (!$this->hosted->enabled()) {
            return Json::error($response, 'mcp_disabled', 'Serverový MCP není zapnutý.', 404);
        }
        if ($request->getAttribute(AuthMiddleware::ATTR_METHOD) !== 'bearer') {
            return $this->unauthorized($response);
        }
        if ($request->getMethod() === 'GET') {
            return $response->withStatus(405)->withHeader('Allow', 'POST, DELETE');
        }
        if ($request->getMethod() === 'DELETE') {
            return $response->withStatus(405)->withHeader('Allow', 'POST');
        }

        $body = (string) $request->getBody();
        if (strlen($body) > 1024 * 1024) {
            return $this->rpc($response, null, -32600, 'Požadavek je příliš velký.', 413);
        }
        try {
            $message = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $this->rpc($response, null, -32700, 'Neplatný JSON.', 400);
        }
        if (!is_array($message) || array_is_list($message) || ($message['jsonrpc'] ?? null) !== '2.0') {
            return $this->rpc($response, null, -32600, 'Neplatný JSON-RPC požadavek.', 400);
        }
        $method = $message['method'] ?? null;
        $id = $message['id'] ?? null;
        if (!is_string($method)) {
            return $this->rpc($response, $id, -32600, 'Chybí metoda.', 400);
        }
        if (!array_key_exists('id', $message)) {
            return $response->withStatus(202)->withHeader('Cache-Control', 'no-store');
        }

        $token = (array) $request->getAttribute(AuthMiddleware::ATTR_API_TOKEN, []);
        $scope = (string) ($token['scope'] ?? 'read');
        $input = [
            'scope' => $scope,
            'token' => substr($request->getHeaderLine('Authorization'), 7),
            'supplierId' => (int) $request->getAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 0),
            'serverParams' => $request->getServerParams(),
        ];

        try {
            $result = match ($method) {
                'initialize' => [
                    'protocolVersion' => $this->protocolVersion($message['params']['protocolVersion'] ?? null),
                    'capabilities' => ['tools' => ['listChanged' => false]],
                    'serverInfo' => ['name' => 'myucto', 'version' => '1.0.0'],
                    'instructions' => 'Nástroje pracují s daty jedné firmy dle uděleného přístupu. Účetnictví a daně jsou pouze ke čtení. Zápisové a mazací akce vyžadují potvrzení uživatele.',
                ],
                'ping' => (object) [],
                'tools/list' => $this->listTools($message['params'] ?? null, $input),
                'tools/call' => $this->call($message['params'] ?? null, $input),
                default => null,
            };
        } catch (\InvalidArgumentException $e) {
            return $this->rpc($response, $id, -32602, $e->getMessage(), 400);
        } catch (\Throwable $e) {
            $this->logger->error('mcp_backend_failed', [
                'method' => $method,
                'error_type' => $e::class,
                'error' => substr($e->getMessage(), 0, 500),
            ]);
            return $this->rpc($response, $id, -32603, 'MCP nástroj se nepodařilo dokončit.', 500);
        }
        if ($result === null) {
            return $this->rpc($response, $id, -32601, 'Neznámá metoda.', 200);
        }
        return Json::ok($response, ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result]);
    }

    private function listTools(mixed $params, array $input): array
    {
        $cursor = is_array($params) ? ($params['cursor'] ?? null) : null;
        if ($params !== null && !is_array($params)) {
            throw new \InvalidArgumentException('Neplatné parametry seznamu nástrojů.');
        }
        if ($cursor !== null && (!is_string($cursor) || preg_match('/^(0|[1-9][0-9]{0,3})$/D', $cursor) !== 1)) {
            throw new \InvalidArgumentException('Neplatný kurzor seznamu nástrojů.');
        }
        $result = $this->bridge->execute($input + ['operation' => 'list', 'cursor' => $cursor === null ? 0 : (int) $cursor]);
        if (!isset($result['tools']) || !is_array($result['tools'])) {
            throw new \InvalidArgumentException('Neplatný kurzor seznamu nástrojů.');
        }
        return $result;
    }

    private function call(mixed $params, array $input): array
    {
        if (!is_array($params) || !is_string($params['name'] ?? null) || strlen($params['name']) > 128) {
            throw new \InvalidArgumentException('Neplatné jméno nástroje.');
        }
        $arguments = $params['arguments'] ?? [];
        if (!is_array($arguments)) {
            throw new \InvalidArgumentException('Neplatné argumenty nástroje.');
        }
        return $this->bridge->execute($input + [
            'operation' => 'call',
            'name' => $params['name'],
            'arguments' => $arguments,
        ]);
    }

    private function protocolVersion(mixed $requested): string
    {
        return in_array($requested, ['2025-11-25', '2025-06-18', '2025-03-26'], true)
            ? $requested : '2025-11-25';
    }

    private function unauthorized(Response $response): Response
    {
        return Json::error($response, 'unauthorized', 'MCP vyžaduje přihlášení.', 401)
            ->withHeader('WWW-Authenticate', 'Bearer resource_metadata="'
                . substr($this->hosted->endpoint(), 0, -4) . '/.well-known/oauth-protected-resource"');
    }

    private function rpc(Response $response, mixed $id, int $code, string $message, int $status): Response
    {
        return Json::ok($response, [
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => ['code' => $code, 'message' => $message],
        ], $status);
    }
}
