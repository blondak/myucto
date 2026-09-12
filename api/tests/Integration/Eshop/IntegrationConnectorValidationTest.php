<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Eshop;

use MyInvoice\Action\Eshop\IntegrationAction;
use MyInvoice\Action\Eshop\IntegrationWebhookAction;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Security\EffectiveRole;
use MyInvoice\Service\Integration\IntegrationConnectionService;
use MyInvoice\Tests\Integration\Stock\StockTestCase;
use PHPUnit\Framework\Attributes\Group;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;
use Slim\Psr7\Response;

#[Group('integration')]
final class IntegrationConnectorValidationTest extends StockTestCase
{
    private IntegrationAction $action;
    private IntegrationConnectionService $connections;

    protected function setUp(): void
    {
        parent::setUp();
        $this->action = $this->container->get(IntegrationAction::class);
        $this->connections = $this->container->get(IntegrationConnectionService::class);
    }

    public function testConnectorsEndpointReturnsDefinitionsAndOnlyOwnCompanyLookups(): void
    {
        $sid = $this->createSupplier();
        $other = $this->createSupplier();
        $this->warehouse($sid, 'VLASTNI');
        $this->warehouse($other, 'CIZI');
        $this->locale($sid, 'de', 'Deutsch');
        $this->locale($other, 'hu', 'Magyar');

        $result = $this->call('connectors', $sid);
        self::assertSame(200, $result['status']);
        $keys = array_column($result['body']['connectors'], 'key');
        self::assertContains('custom.webhook', $keys);
        self::assertSame(['local', 'remote', 'manual'], $result['body']['owners']);
        $warehouses = array_column($result['body']['lookups']['warehouses'], 'value');
        self::assertSame(['VLASTNI'], $warehouses);
        self::assertSame(['de'], array_column($result['body']['lookups']['languages'], 'value'));
        self::assertContains('CZK', array_column($result['body']['lookups']['currencies'], 'value'));
        self::assertNotEmpty($result['body']['lookups']['vat_rates']);
    }

    public function testPermissionsGuardConnectorsAndWrites(): void
    {
        $sid = $this->createSupplier();
        $none = new EffectiveRole(9, 'Bez integrací', 'staff', true, ['eshop' => 2]);
        $reader = new EffectiveRole(9, 'Čtenář integrací', 'staff', true, ['eshop.integrations' => 1]);

        self::assertSame(403, $this->call('connectors', $sid, role: $none)['status']);
        self::assertSame(200, $this->call('connectors', $sid, role: $reader)['status']);
        self::assertSame(403, $this->call('create', $sid, $this->validInput(), role: $reader)['status']);
        self::assertSame(0, $this->countConnections($sid));
    }

    public function testUnknownAndAnnouncedConnectorsAreRejectedForNewConnections(): void
    {
        $sid = $this->createSupplier();
        $unknown = $this->call('create', $sid, ['connector_key' => 'synthetic.unknown'] + $this->validInput());
        self::assertSame(422, $unknown['status']);
        self::assertSame('connector_key', $unknown['body']['error']['field']);
        self::assertStringContainsString('synthetic.unknown', $unknown['body']['error']['message']);

        $announced = $this->call('create', $sid, ['connector_key' => 'shoptet'] + $this->validInput());
        self::assertSame(422, $announced['status']);
        self::assertStringContainsString('zatím není k dispozici', $announced['body']['error']['message']);
        self::assertSame(0, $this->countConnections($sid));
    }

    public function testMappingsAcceptOnlyDefinedTypesAndExistingLocalValues(): void
    {
        $sid = $this->createSupplier();
        $other = $this->createSupplier();
        $this->warehouse($sid, 'HLAVNI');
        $this->warehouse($other, 'CIZI');

        $wrongType = $this->call('create', $sid, ['mappings' => ['payment_methods' => ['card' => 'CARD']]] + $this->validInput());
        self::assertSame(422, $wrongType['status']);
        self::assertSame('mappings.payment_methods', $wrongType['body']['error']['field']);

        $foreignWarehouse = $this->call('create', $sid, ['mappings' => ['warehouses' => ['CIZI' => 'store-1']]] + $this->validInput());
        self::assertSame(422, $foreignWarehouse['status']);
        self::assertStringContainsString('sklad „CIZI“ ve firmě neexistuje', $foreignWarehouse['body']['error']['message']);

        $emptyRemote = $this->call('create', $sid, ['mappings' => ['warehouses' => ['HLAVNI' => '  ']]] + $this->validInput());
        self::assertSame(422, $emptyRemote['status']);
        self::assertStringContainsString('vyplňte hodnotu v externím systému', $emptyRemote['body']['error']['message']);

        $valid = $this->call('create', $sid, ['mappings' => [
            'warehouses' => ['HLAVNI' => 'main-store'],
            'currencies' => ['CZK' => 'CZK'],
            'languages' => [],
        ]] + $this->validInput());
        self::assertSame(201, $valid['status']);
        self::assertSame(['warehouses' => ['HLAVNI' => 'main-store'], 'currencies' => ['CZK' => 'CZK']], $valid['body']['mappings']);
    }

    public function testNumericLocalCodeStaysAnObjectKeyAfterRoundTrip(): void
    {
        $sid = $this->createSupplier();
        $this->warehouse($sid, '0');
        $created = $this->connections->create($sid, ['mappings' => ['warehouses' => ['0' => 'store-zero']]] + $this->validInput(), $this->userId);

        $stored = $this->db->pdo()->query('SELECT mappings_json FROM integration_connections WHERE id = ' . (int) $created['id'])->fetchColumn();
        self::assertSame('{"warehouses":{"0":"store-zero"}}', $stored);
        self::assertSame('{"warehouses":{"0":"store-zero"}}', json_encode($created['mappings'], JSON_THROW_ON_ERROR));
    }

    public function testOwnershipAcceptsKnownFieldsFreeFieldsAndValidOwnersOnly(): void
    {
        $sid = $this->createSupplier();
        $badOwner = $this->call('create', $sid, ['field_ownership' => ['product.price' => 'eshop']] + $this->validInput());
        self::assertSame(422, $badOwner['status']);
        self::assertSame('field_ownership.product.price', $badOwner['body']['error']['field']);
        self::assertStringContainsString('Prodejní cena', $badOwner['body']['error']['message']);

        $badFreeField = $this->call('create', $sid, ['field_ownership' => ['Custom Field' => 'local']] + $this->validInput());
        self::assertSame(422, $badFreeField['status']);

        $valid = $this->call('create', $sid, ['field_ownership' => [
            'product.price' => 'local', 'order.status' => 'remote', 'custom.loyalty_points' => 'manual',
        ]] + $this->validInput());
        self::assertSame(201, $valid['status']);
        self::assertSame('manual', $valid['body']['field_ownership']['custom.loyalty_points']);
    }

    public function testLegacyConnectionWithUnknownConnectorStaysEditable(): void
    {
        $sid = $this->createSupplier();
        $this->db->pdo()->prepare("INSERT INTO integration_connections
            (connection_uuid, supplier_id, connector_key, name, status, mappings_json, field_ownership_json)
            VALUES (?, ?, 'legacy.synthetic', 'Starší připojení', 'draft', ?, ?)")
            ->execute(['00000000-0000-4000-8000-0000000000aa', $sid,
                '{"warehouse":"MAIN","nested":{"list":[1,2]}}', '{"legacy.field":"remote"}']);
        $id = (int) $this->db->pdo()->lastInsertId();

        $renamed = $this->call('update', $sid, ['name' => 'Starší připojení upravené'], $id);
        self::assertSame(200, $renamed['status'], json_encode($renamed['body'], JSON_UNESCAPED_UNICODE));
        self::assertSame('legacy.synthetic', $renamed['body']['connector_key']);
        self::assertSame(['warehouse' => 'MAIN', 'nested' => ['list' => [1, 2]]], $renamed['body']['mappings']);
        self::assertSame(['legacy.field' => 'remote'], $renamed['body']['field_ownership']);

        $otherUnknown = $this->call('update', $sid, ['connector_key' => 'legacy.other'], $id);
        self::assertSame(422, $otherUnknown['status']);

        $switched = $this->call('update', $sid, ['connector_key' => 'custom.webhook'], $id);
        self::assertSame(422, $switched['status'], 'Přechod na známý konektor musí projít kontrolou mapování.');
        self::assertSame('mappings.warehouse', $switched['body']['error']['field']);

        $legacyCredentials = $this->connections->setCredentials($sid, $id, ['token' => 'synthetic-legacy-token']);
        self::assertSame(['token'], $legacyCredentials['credentials_fields']);
    }

    public function testCredentialsFollowDefinitionMergeAndNeverReturnValues(): void
    {
        $sid = $this->createSupplier();
        $created = $this->connections->create($sid, $this->validInput(), $this->userId);
        $id = (int) $created['id'];

        $unknown = $this->call('credentials', $sid, ['credentials' => ['password' => 'synthetic']], $id);
        self::assertSame(422, $unknown['status']);
        self::assertSame('credentials.password', $unknown['body']['error']['field']);

        $insecure = $this->call('credentials', $sid, ['credentials' => ['endpoint_url' => 'http://shop.example.test/hook']], $id);
        self::assertSame(422, $insecure['status']);
        self::assertStringContainsString('https://', $insecure['body']['error']['message']);

        $nothing = $this->call('credentials', $sid, ['credentials' => ['endpoint_token' => '']], $id);
        self::assertSame(422, $nothing['status']);

        $token = $this->call('credentials', $sid, ['credentials' => ['endpoint_token' => 'synthetic-outbound-token']], $id);
        self::assertSame(200, $token['status']);
        self::assertSame(['endpoint_token'], $token['body']['credentials_fields']);
        self::assertStringNotContainsString('synthetic-outbound-token', $token['raw']);

        $url = $this->call('credentials', $sid, ['credentials' => ['endpoint_url' => 'https://shop.example.test/hook', 'endpoint_token' => '']], $id);
        self::assertSame(200, $url['status']);
        self::assertEqualsCanonicalizing(['endpoint_token', 'endpoint_url'], $url['body']['credentials_fields']);
        self::assertSame(['endpoint_token' => 'synthetic-outbound-token', 'endpoint_url' => 'https://shop.example.test/hook'],
            $this->connections->credentials($sid, $id));

        $cleared = $this->call('credentials', $sid, ['credentials' => [], 'clear' => ['endpoint_token']], $id);
        self::assertSame(200, $cleared['status']);
        self::assertSame(['endpoint_url'], $cleared['body']['credentials_fields']);
        self::assertSame(['endpoint_url' => 'https://shop.example.test/hook'], $this->connections->credentials($sid, $id));

        $listed = $this->call('list', $sid);
        self::assertStringNotContainsString('shop.example.test', $listed['raw']);
        self::assertStringNotContainsString('credentials_enc', $listed['raw']);
    }

    public function testConnectionsOfAnotherCompanyCannotBeEdited(): void
    {
        $sid = $this->createSupplier();
        $other = $this->createSupplier();
        $created = $this->connections->create($sid, $this->validInput(), $this->userId);

        self::assertSame(404, $this->call('update', $other, ['name' => 'Převzato'], (int) $created['id'])['status']);
        self::assertSame(404, $this->call('credentials', $other, ['credentials' => ['endpoint_token' => 'x']], (int) $created['id'])['status']);
        // Seznam druhé firmy smí obsahovat jen její vlastní ukázkové napojení.
        $otherList = $this->call('list', $other)['body'];
        self::assertNotContains((int) $created['id'], array_column($otherList, 'id'));
        self::assertSame([$other], array_values(array_unique(array_column($otherList, 'supplier_id'))));
    }

    public function testWebhookReportsIdempotencyConflictSeparatelyFromAuthentication(): void
    {
        $sid = $this->createSupplier();
        $created = $this->connections->create($sid, ['status' => 'active'] + $this->validInput(), $this->userId);
        $secret = $this->connections->rotateWebhookSecret($sid, (int) $created['id'])['secret'];
        $event = ['event_id' => 'evt-synthetic-conflict', 'entity_type' => 'product', 'entity_id' => 'SKU-1',
            'event_type' => 'product.updated', 'aggregate_version' => 1];

        $first = $this->webhook($created['connection_uuid'], $secret, json_encode($event, JSON_THROW_ON_ERROR));
        self::assertSame(202, $first['status']);
        self::assertFalse($first['body']['duplicate']);

        $conflict = $this->webhook($created['connection_uuid'], $secret, json_encode(['aggregate_version' => 2] + $event, JSON_THROW_ON_ERROR));
        self::assertSame(409, $conflict['status']);
        self::assertSame('webhook_idempotency_conflict', $conflict['body']['error']['code']);

        $badSignature = $this->webhook($created['connection_uuid'], 'wrong-secret', json_encode($event, JSON_THROW_ON_ERROR));
        self::assertSame(401, $badSignature['status']);
    }

    private function validInput(): array
    {
        return ['connector_key' => 'custom.webhook', 'name' => 'Syntetické napojení ' . bin2hex(random_bytes(3)),
            'status' => 'draft', 'mappings' => [], 'field_ownership' => []];
    }

    private function locale(int $supplierId, string $code, string $name): void
    {
        $this->db->pdo()->prepare('INSERT INTO stock_locales (supplier_id, code, name) VALUES (?, ?, ?)')
            ->execute([$supplierId, $code, $name]);
    }

    private function countConnections(int $supplierId): int
    {
        $stmt = $this->db->pdo()->prepare('SELECT COUNT(*) FROM integration_connections WHERE supplier_id = ?');
        $stmt->execute([$supplierId]);
        return (int) $stmt->fetchColumn();
    }

    /** @return array{status:int,body:array,raw:string} */
    private function call(string $method, int $supplierId, array $body = [], ?int $id = null, ?EffectiveRole $role = null): array
    {
        $http = match ($method) {
            'list', 'connectors' => 'GET',
            'create' => 'POST',
            default => 'PUT',
        };
        $request = (new ServerRequestFactory())->createServerRequest($http, '/api/eshop/integrations')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin'])
            ->withParsedBody($body);
        if ($role !== null) {
            $request = $request->withAttribute('auth.effective_role', $role);
        }
        $response = $id === null
            ? $this->action->{$method}($request, new Response())
            : $this->action->{$method}($request, new Response(), ['id' => (string) $id]);
        return $this->decode($response);
    }

    /** @return array{status:int,body:array,raw:string} */
    private function webhook(string $uuid, string $secret, string $body): array
    {
        $timestamp = (string) time();
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/public/integrations/webhooks/' . $uuid)
            ->withHeader('X-Integration-Timestamp', $timestamp)
            ->withHeader('X-Integration-Signature', 'sha256=' . hash_hmac('sha256', $timestamp . '.' . $body, $secret))
            ->withBody((new StreamFactory())->createStream($body));
        return $this->decode($this->container->get(IntegrationWebhookAction::class)
            ->receive($request, new Response(), ['uuid' => $uuid]));
    }

    /** @return array{status:int,body:array,raw:string} */
    private function decode(\Psr\Http\Message\ResponseInterface $response): array
    {
        $response->getBody()->rewind();
        $raw = (string) $response->getBody();
        $decoded = json_decode($raw, true);
        return ['status' => $response->getStatusCode(), 'body' => is_array($decoded) ? $decoded : [], 'raw' => $raw];
    }
}
