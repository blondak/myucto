<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration;

use MyInvoice\Action\Mcp\HostedMcpEndpointAction;
use MyInvoice\Action\Mcp\HostedMcpSettingsAction;
use MyInvoice\Infrastructure\Cache\EntityCache;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\SupplierDomainRepository;
use MyInvoice\Service\Mcp\HostedMcp;
use MyInvoice\Service\Mcp\NodeBridge;
use MyInvoice\Service\System\ManagedModeGuard;
use MyInvoice\Service\Tenant\TenantUrlResolver;
use MyInvoice\Service\Update\NativeUpdateService;
use MyInvoice\Service\Update\VersionService;
use Psr\Log\NullLogger;
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
            new ManagedModeGuard($config),
        );
        $action = new HostedMcpEndpointAction($hosted, new NodeBridge($hosted, $config),
            new VersionService($db, new NativeUpdateService()), new NullLogger());
        $factory = new ResponseFactory();

        $initialize = $this->send($action, $factory, 'initialize', ['protocolVersion' => '2025-06-18']);
        self::assertSame('2025-06-18', $initialize['result']['protocolVersion']);
        self::assertSame(trim((string) file_get_contents(dirname(__DIR__, 3) . '/VERSION')),
            $initialize['result']['serverInfo']['version']);

        $list = $this->send($action, $factory, 'tools/list', []);
        self::assertArrayNotHasKey('nextCursor', $list['result']);
        $rawList = json_decode((string) $this->sendResponse($action, $factory, 'tools/list')->getBody(), false, 512, JSON_THROW_ON_ERROR);
        foreach ($rawList->result->tools as $tool) {
            self::assertIsObject($tool->inputSchema->properties);
        }
        $readWriteList = json_decode((string) $this->sendResponse($action, $factory, 'tools/list', [], 'read_write')->getBody(), false, 512, JSON_THROW_ON_ERROR);
        self::assertGreaterThan(200, count($readWriteList->result->tools));
        self::assertObjectNotHasProperty('nextCursor', $readWriteList->result);
        foreach ($readWriteList->result->tools as $tool) {
            self::assertIsObject($tool->inputSchema->properties);
        }
        $names = [];
        foreach ($list['result']['tools'] as $tool) {
            self::assertTrue($tool['annotations']['readOnlyHint']);
            $names[] = $tool['name'];
        }
        self::assertGreaterThan(100, count($names));
        self::assertCount(count(array_unique($names)), $names);

        $call = $this->send($action, $factory, 'tools/call', [
            'name' => 'create_client', 'arguments' => [],
        ]);
        self::assertTrue($call['result']['isError']);
    }

    public function testManagedInstallationKeepsServerDisabledEvenWithEnvironmentOverride(): void
    {
        $config = Config::load(dirname(__DIR__, 3));
        $db = new Connection($config);
        $hosted = new HostedMcp(
            $db,
            new TenantUrlResolver($config, new SupplierDomainRepository($db, EntityCache::disabled())),
            new ManagedModeGuard(new Config(['app' => ['managed' => true]])),
        );
        self::assertFalse($hosted->enabled());

        $factory = new ResponseFactory();
        $request = (new ServerRequestFactory())->createServerRequest('POST', 'https://example.test/mcp');
        $bridge = new NodeBridge($hosted, $config);
        $endpoint = new HostedMcpEndpointAction($hosted, $bridge,
            new VersionService($db, new NativeUpdateService()), new NullLogger());
        $disabled = $endpoint->handle($request, $factory->createResponse());
        self::assertSame(404, $disabled->getStatusCode());

        $settings = new HostedMcpSettingsAction($hosted, $bridge);
        $change = $settings->update($request->withParsedBody(['enabled' => true])
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session')
            ->withAttribute(AuthMiddleware::ATTR_USER, ['is_superadmin' => true]), $factory->createResponse());
        self::assertSame(409, $change->getStatusCode());
        self::assertStringContainsString('managed_installation', (string) $change->getBody());
    }

    private function send(
        HostedMcpEndpointAction $action,
        ResponseFactory $factory,
        string $method,
        array $params = [],
    ): array {
        return json_decode((string) $this->sendResponse($action, $factory, $method, $params)->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }

    private function sendResponse(
        HostedMcpEndpointAction $action,
        ResponseFactory $factory,
        string $method,
        array $params = [],
        string $scope = 'read',
    ): \Psr\Http\Message\ResponseInterface {
        $request = (new ServerRequestFactory())->createServerRequest('POST', 'https://example.test/mcp')
            ->withHeader('Authorization', 'Bearer mi_pat_synthetic')
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'bearer')
            ->withAttribute(AuthMiddleware::ATTR_API_TOKEN, ['scope' => $scope]);
        $request->getBody()->write(json_encode([
            'jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params,
        ], JSON_THROW_ON_ERROR));
        $request->getBody()->rewind();
        $response = $action->handle($request, $factory->createResponse());
        self::assertSame(200, $response->getStatusCode());
        return $response;
    }
}
