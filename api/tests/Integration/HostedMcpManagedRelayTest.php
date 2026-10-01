<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration;

use MyInvoice\Action\Mcp\HostedMcpEndpointAction;
use MyInvoice\Action\Mcp\HostedMcpSettingsAction;
use MyInvoice\Infrastructure\Cache\EntityCache;
use MyInvoice\Infrastructure\Cache\RedisFactory;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\SupplierDomainRepository;
use MyInvoice\Repository\UserSupplierRepository;
use MyInvoice\Service\Auth\ApiTokenService;
use MyInvoice\Service\Auth\PasswordHasher;
use MyInvoice\Service\Mcp\HostedMcp;
use MyInvoice\Service\Mcp\ManagedNodeRelay;
use MyInvoice\Service\Mcp\NodeBridge;
use MyInvoice\Service\System\ManagedModeGuard;
use MyInvoice\Service\Tenant\TenantUrlResolver;
use MyInvoice\Service\Update\NativeUpdateService;
use MyInvoice\Service\Update\VersionService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * Serverový MCP ve spravované instalaci: dostupný jen tehdy, když provozovatel
 * nastaví `MYINVOICE_MCP_NODE_BINARY`, a volání API pak neodbavuje Node, ale PHP.
 */
#[Group('integration')]
final class HostedMcpManagedRelayTest extends TestCase
{
    private const ENV = ['MYINVOICE_MCP_ENABLED', 'MYINVOICE_MCP_NODE_BINARY'];

    /** @var array<string,string|false> */
    private array $prior = [];
    private Config $config;
    private Connection $db;
    private ?int $userId = null;
    private ?int $supplierId = null;

    protected function setUp(): void
    {
        foreach (self::ENV as $name) $this->prior[$name] = getenv($name);
        $this->config = Config::load(dirname(__DIR__, 3));
        $this->db = new Connection($this->config);
    }

    protected function tearDown(): void
    {
        foreach ($this->prior as $name => $value) {
            putenv($value === false ? $name : $name . '=' . $value);
        }
        $pdo = $this->db->pdo();
        if ($this->userId !== null) {
            $pdo->prepare('DELETE FROM api_request_log WHERE user_id = ?')->execute([$this->userId]);
            $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$this->userId]);
        }
        if ($this->supplierId !== null) {
            $pdo->prepare('DELETE FROM supplier WHERE id = ?')->execute([$this->supplierId]);
        }
    }

    public function testManagedInstallationWithoutOperatorBridgeStaysLocked(): void
    {
        putenv('MYINVOICE_MCP_ENABLED=1');
        putenv('MYINVOICE_MCP_NODE_BINARY');
        $hosted = $this->hosted(true);

        self::assertFalse($hosted->managedRelay());
        self::assertTrue($hosted->managedInstallation());
        self::assertFalse($hosted->enabled());
    }

    public function testNodeBinaryAloneDoesNotSwitchUnmanagedInstallationToRelay(): void
    {
        putenv('MYINVOICE_MCP_NODE_BINARY=' . $this->node());
        $hosted = $this->hosted(false);

        self::assertFalse($hosted->managedRelay());
        self::assertFalse($hosted->managedInstallation());
    }

    public function testOperatorBridgeMakesServerAvailableAndListsToolsThroughRelay(): void
    {
        putenv('MYINVOICE_MCP_ENABLED=1');
        putenv('MYINVOICE_MCP_NODE_BINARY=' . $this->node());
        $hosted = $this->hosted(true);
        self::assertTrue($hosted->managedRelay());
        self::assertFalse($hosted->managedInstallation());
        self::assertTrue($hosted->enabled());
        self::assertTrue($hosted->nodeAvailable());

        $bridge = new NodeBridge($hosted, $this->config);
        $endpoint = new HostedMcpEndpointAction($hosted, $bridge,
            new VersionService($this->db, new NativeUpdateService()), new NullLogger(),
            $this->db, new UserSupplierRepository($this->db));
        $request = (new ServerRequestFactory())->createServerRequest('POST', 'https://example.test/mcp')
            ->withHeader('Authorization', 'Bearer mi_pat_synthetic')
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'bearer')
            ->withAttribute(AuthMiddleware::ATTR_API_TOKEN, ['scope' => 'read']);
        $request->getBody()->write(json_encode([
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => [],
        ], JSON_THROW_ON_ERROR));
        $request->getBody()->rewind();
        $response = $endpoint->handle($request, (new ResponseFactory())->createResponse());
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $list = json_decode((string) $response->getBody(), false, 512, JSON_THROW_ON_ERROR);
        self::assertGreaterThan(100, count($list->result->tools));
        foreach ($list->result->tools as $tool) {
            self::assertIsObject($tool->inputSchema->properties);
            self::assertTrue($tool->annotations->readOnlyHint);
        }

        $settings = new HostedMcpSettingsAction($hosted, $bridge);
        $state = json_decode((string) $settings->show($request, (new ResponseFactory())->createResponse())->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($state['managed_relay']);
        self::assertTrue($state['enabled']);
    }

    public function testToolCallReachesApiThroughPhpWithTokenTheBridgeNeverSaw(): void
    {
        putenv('MYINVOICE_MCP_NODE_BINARY=' . $this->node());
        $token = $this->createToken();

        $result = (new ManagedNodeRelay($this->hosted(true), $this->config))->execute([
            'operation' => 'call', 'name' => 'whoami', 'arguments' => [],
            'scope' => 'read', 'token' => $token,
            'boundSupplierId' => $this->supplierId, 'lockedSupplierId' => null,
            'serverParams' => ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_COOKIE' => 'nesmí projít'],
        ]);

        self::assertNotTrue($result['isError'] ?? false, json_encode($result));
        self::assertIsObject($result['structuredContent'] ?? null);
    }

    /**
     * Nahraný soubor projde celým řetězem (Node → PHP relé → interní API →
     * Slim akce) jako UploadedFile. Neplatný ISDOC akce odmítne ještě před
     * zápisem čehokoli, takže test nic neukládá.
     */
    public function testUploadedFileReachesActionThroughRelay(): void
    {
        putenv('MYINVOICE_MCP_NODE_BINARY=' . $this->node());
        $token = $this->createToken('read_write');

        $result = (new ManagedNodeRelay($this->hosted(true), $this->config))->execute([
            'operation' => 'call', 'name' => 'import_purchase_invoice_file',
            'arguments' => ['filename' => 'faktura.isdoc', 'content_base64' => base64_encode('<Invoice>neplatný</Invoice>')],
            'scope' => 'read_write', 'token' => $token,
            'boundSupplierId' => $this->supplierId, 'lockedSupplierId' => null,
            'serverParams' => ['REMOTE_ADDR' => '127.0.0.1'],
        ]);

        self::assertTrue($result['isError'] ?? false, json_encode($result));
        $text = (string) ($result['content'][0]->text ?? '');
        self::assertMatchesRegularExpression('/HTTP 422 \((invalid_isdoc|invalid_document)\)/', $text);
        self::assertStringNotContainsString('no_file', $text);
    }

    public function testApiRequestIsBuiltFromApprovedConnectionNotFromBridgeOutput(): void
    {
        $relay = new ManagedNodeRelay($this->hosted(true), $this->config);
        $fromBridge = [
            'url' => 'https://example.test/api/v1/invoices', 'method' => 'GET', 'body' => '',
            'headers' => [
                'Authorization' => 'Bearer podvrzeny', 'X-Supplier-Id' => '999',
                'Accept' => 'application/json', 'X-MyUcto-Tool' => 'list_invoices', 'Cookie' => 'a=b',
            ],
        ];
        $call = ['operation' => 'call', 'name' => 'list_invoices', 'token' => 'mi_pat_skutecny',
            'serverParams' => ['REMOTE_ADDR' => '127.0.0.1']];

        $bound = $relay->apiRequest($fromBridge, $call + ['boundSupplierId' => 7, 'arguments' => ['supplier_id' => 8]]);
        self::assertSame('Bearer mi_pat_skutecny', $bound['headers']['Authorization']);
        self::assertSame('7', $bound['headers']['X-Supplier-Id']);
        self::assertSame('application/json', $bound['headers']['Accept']);
        self::assertArrayNotHasKey('Cookie', $bound['headers']);
        self::assertSame(['REMOTE_ADDR' => '127.0.0.1'], $bound['serverParams']);

        $unbound = $relay->apiRequest($fromBridge, $call + ['arguments' => ['supplier_id' => 8]]);
        self::assertSame('8', $unbound['headers']['X-Supplier-Id']);

        self::assertNull($relay->apiRequest($fromBridge, $call + ['arguments' => []]));
        self::assertNull($relay->apiRequest($fromBridge, $call + ['lockedSupplierId' => 7, 'arguments' => ['supplier_id' => 8]]));
        self::assertNull($relay->apiRequest($fromBridge, ['operation' => 'list'] + $call));

        $whoami = $relay->apiRequest($fromBridge, ['name' => 'whoami'] + $call);
        self::assertArrayNotHasKey('X-Supplier-Id', $whoami['headers']);
    }

    private function hosted(bool $managed): HostedMcp
    {
        return new HostedMcp(
            $this->db,
            new TenantUrlResolver($this->config, new SupplierDomainRepository($this->db, EntityCache::disabled())),
            new ManagedModeGuard(new Config(['app' => ['managed' => $managed]])),
        );
    }

    private function node(): string
    {
        $configured = $this->prior['MYINVOICE_MCP_NODE_BINARY'] ?? false;
        return is_string($configured) && trim($configured) !== '' ? $configured : 'node';
    }

    private function createToken(string $scope = 'read'): string
    {
        $pdo = $this->db->pdo();
        $roleId = (int) $pdo->query("SELECT id FROM roles WHERE system_key = 'superadmin' AND is_active = 1 LIMIT 1")->fetchColumn();
        self::assertGreaterThan(0, $roleId);
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        try {
            $pdo->prepare(
                'INSERT INTO supplier (company_name, street, city, zip, country_id, email,
                                       default_currency_id, default_vat_rate_id)
                 VALUES (?, ?, ?, ?, 1, ?, 0, 0)'
            )->execute(['MCP relay test s.r.o.', 'Testovací 1', 'Praha', '11000', 'mcp-relay@example.test']);
            $this->supplierId = (int) $pdo->lastInsertId();
        } finally {
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        }
        $pdo->prepare(
            'INSERT INTO users (email, password_hash, name, role_id, is_active)
             VALUES (?, ?, ?, ?, 1)'
        )->execute([
            'mcp-relay-' . bin2hex(random_bytes(8)) . '@example.test',
            (new PasswordHasher($this->config))->hash('synthetic-test-password'),
            'MCP relay test', $roleId,
        ]);
        $this->userId = (int) $pdo->lastInsertId();

        return (new ApiTokenService($this->db, new RedisFactory($this->config)))
            ->generate($this->userId, $this->supplierId, 'MCP relay test', $scope)['plaintext'];
    }
}
