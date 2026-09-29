<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration;

use MyInvoice\Action\Mcp\HostedMcpEndpointAction;
use MyInvoice\Infrastructure\Cache\EntityCache;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\SupplierDomainRepository;
use MyInvoice\Service\Mcp\HostedMcp;
use MyInvoice\Service\Mcp\NodeBridge;
use MyInvoice\Service\Tenant\TenantUrlResolver;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

final class HostedMcpEndpointTest extends TestCase
{
    private string|false $priorSetting;

    protected function setUp(): void
    {
        $this->priorSetting = getenv('MYINVOICE_MCP_ENABLED');
        putenv('MYINVOICE_MCP_ENABLED=1');
    }

    protected function tearDown(): void
    {
        putenv($this->priorSetting === false
            ? 'MYINVOICE_MCP_ENABLED'
            : 'MYINVOICE_MCP_ENABLED=' . $this->priorSetting);
    }

    public function testReadScopeListsOnlyReadToolsAndRejectsWriteCall(): void
    {
        $config = Config::load(dirname(__DIR__, 3));
        $db = new Connection($config);
        $hosted = new HostedMcp(
            $db,
            new TenantUrlResolver($config, new SupplierDomainRepository($db, EntityCache::disabled())),
        );
        $action = new HostedMcpEndpointAction($hosted, new NodeBridge($hosted, $config));
        $factory = new ResponseFactory();

        $initialize = $this->send($action, $factory, 'initialize', ['protocolVersion' => '2025-06-18']);
        self::assertSame('2025-06-18', $initialize['result']['protocolVersion']);

        $list = $this->send($action, $factory, 'tools/list');
        self::assertGreaterThan(100, count($list['result']['tools']));
        foreach ($list['result']['tools'] as $tool) {
            self::assertTrue($tool['annotations']['readOnlyHint']);
        }

        $call = $this->send($action, $factory, 'tools/call', [
            'name' => 'create_client', 'arguments' => [],
        ]);
        self::assertTrue($call['result']['isError']);
    }

    private function send(
        HostedMcpEndpointAction $action,
        ResponseFactory $factory,
        string $method,
        array $params = [],
    ): array {
        $request = (new ServerRequestFactory())->createServerRequest('POST', 'https://example.test/mcp')
            ->withHeader('Authorization', 'Bearer mi_pat_synthetic')
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'bearer')
            ->withAttribute(AuthMiddleware::ATTR_API_TOKEN, ['scope' => 'read']);
        $request->getBody()->write(json_encode([
            'jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params,
        ], JSON_THROW_ON_ERROR));
        $request->getBody()->rewind();
        $response = $action->handle($request, $factory->createResponse());
        self::assertSame(200, $response->getStatusCode());
        return json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }
}
