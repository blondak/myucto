<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Cache\RedisFactory;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Auth\ApiTokenService;
use MyInvoice\Service\Auth\DatabaseSecurityClock;
use MyInvoice\Service\Auth\SessionManager;
use MyInvoice\Service\Auth\PasswordHasher;
use MyInvoice\Service\Auth\MfaStepUpService;
use MyInvoice\Service\Auth\TotpService;
use MyInvoice\Service\Mcp\McpOAuth;
use MyInvoice\Service\Mcp\NodeBridge;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;

#[Group('integration')]
final class McpOAuthGrantTest extends TestCase
{
    private Connection $db;
    private McpOAuth $oauth;
    private ApiTokenService $tokens;
    private int $userId;
    private int $supplierId;
    private array $clientIds = [];

    protected function setUp(): void
    {
        $config = Config::load(dirname(__DIR__, 3));
        $this->db = new Connection($config);
        $pdo = $this->db->pdo();
        $roleId = (int) $pdo->query("SELECT id FROM roles WHERE system_key = 'superadmin' AND is_active = 1 LIMIT 1")->fetchColumn();
        self::assertGreaterThan(0, $roleId);

        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        try {
            $pdo->prepare(
                'INSERT INTO supplier (company_name, street, city, zip, country_id, email,
                                       default_currency_id, default_vat_rate_id)
                 VALUES (?, ?, ?, ?, 1, ?, 0, 0)'
            )->execute(['MCP test s.r.o.', 'Testovací 1', 'Praha', '11000', 'mcp@example.test']);
            $this->supplierId = (int) $pdo->lastInsertId();
        } finally {
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        }

        $pdo->prepare(
            'INSERT INTO users (email, password_hash, name, role_id, is_active)
             VALUES (?, ?, ?, ?, 1)'
        )->execute([
            'mcp-' . bin2hex(random_bytes(8)) . '@example.test',
            (new PasswordHasher($config))->hash('synthetic-test-password'),
            'MCP test', $roleId,
        ]);
        $this->userId = (int) $pdo->lastInsertId();
        $this->tokens = new ApiTokenService($this->db, new RedisFactory($config));
        $this->oauth = new McpOAuth($this->db, $this->tokens);
    }

    protected function tearDown(): void
    {
        $pdo = $this->db->pdo();
        if (isset($this->userId)) {
            $pdo->prepare('DELETE FROM api_request_log WHERE user_id = ?')->execute([$this->userId]);
            $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$this->userId]);
        }
        foreach ($this->clientIds as $id) {
            $pdo->prepare('DELETE FROM mcp_oauth_clients WHERE client_id = ?')->execute([$id]);
        }
        if (isset($this->supplierId)) {
            $pdo->prepare('DELETE FROM supplier WHERE id = ?')->execute([$this->supplierId]);
        }
    }

    public function testPkceCodeIsSingleUseAndRefreshRotatesAndRevocationStopsAccess(): void
    {
        $client = $this->oauth->register('Synthetic assistant', ['https://client.example.test/callback']);
        $this->clientIds[] = $client;
        $verifier = str_repeat('a', 64);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        $resource = rtrim((string) Config::load(dirname(__DIR__, 3))->get('app.url'), '/') . '/mcp';
        $code = $this->oauth->createCode(
            $client, $this->userId, $this->supplierId,
            'https://client.example.test/callback', $challenge, 'read', $resource,
        );

        self::assertNull($this->oauth->exchange(
            $code, $client, 'https://client.example.test/callback', str_repeat('b', 64), $resource,
        ));
        $first = $this->oauth->exchange(
            $code, $client, 'https://client.example.test/callback', $verifier, $resource,
        );
        self::assertIsArray($first);
        self::assertSame('read', $this->tokens->validate($first['access_token'])['scope']);
        self::assertSame($this->supplierId, $this->tokens->validate($first['access_token'])['supplier_id']);
        $bridge = Bootstrap::buildContainer()->get(NodeBridge::class);
        $called = $bridge->execute([
            'operation' => 'call', 'name' => 'whoami', 'arguments' => [],
            'scope' => 'read', 'token' => $first['access_token'],
            'supplierId' => $this->supplierId,
            'serverParams' => ['REMOTE_ADDR' => '127.0.0.1'],
        ]);
        self::assertNotTrue($called['isError'] ?? false, json_encode($called));
        self::assertIsArray($called['structuredContent'] ?? null);
        $base = rtrim((string) Config::load(dirname(__DIR__, 3))->get('app.url'), '/');
        $direct = Bootstrap::buildApp()->handle((new ServerRequestFactory())->createServerRequest(
            'GET', $base . '/api/v1/auth/api-me', ['REMOTE_ADDR' => '127.0.0.1'],
        )->withHeader('Authorization', 'Bearer ' . $first['access_token']));
        self::assertSame(401, $direct->getStatusCode());
        self::assertNull($this->oauth->exchange(
            $code, $client, 'https://client.example.test/callback', $verifier, $resource,
        ));

        $secondClient = $this->oauth->register('Other assistant', ['https://other.example.test/callback']);
        $this->clientIds[] = $secondClient;
        self::assertNull($this->oauth->refresh($first['refresh_token'], $secondClient));

        $firstTokenId = (int) $this->tokens->validate($first['access_token'])['id'];
        $this->tokens->addIpRule($firstTokenId, $this->userId, '192.0.2.0/24', 'Synthetic restriction');

        $second = $this->oauth->refresh($first['refresh_token'], $client);
        self::assertIsArray($second);
        self::assertNull($this->tokens->validate($first['access_token']));
        self::assertNotNull($this->tokens->validate($second['access_token']));
        self::assertSame($firstTokenId, (int) $this->tokens->validate($second['access_token'])['id']);
        self::assertSame(['192.0.2.0/24'], $this->tokens->ipRulesFor(
            (int) $this->tokens->validate($second['access_token'])['id']
        ));
        self::assertNull($this->oauth->refresh($first['refresh_token'], $client));

        self::assertTrue($this->tokens->revoke($firstTokenId, $this->userId));
        self::assertNull($this->oauth->refresh($second['refresh_token'], $client));
    }

    public function testDiscoveryRegistrationAndMcpChallengePassFullMiddleware(): void
    {
        $previous = getenv('MYINVOICE_MCP_ENABLED');
        putenv('MYINVOICE_MCP_ENABLED=1');
        try {
            $app = Bootstrap::buildApp();
            $base = rtrim((string) Config::load(dirname(__DIR__, 3))->get('app.url'), '/');
            $host = (string) parse_url($base, PHP_URL_HOST);
            $port = parse_url($base, PHP_URL_PORT);
            $authority = $host . ($port !== null ? ':' . $port : '');
            $factory = new ServerRequestFactory();
            $metadata = $app->handle($factory->createServerRequest(
                'GET', $base . '/.well-known/oauth-authorization-server',
                ['REMOTE_ADDR' => '127.0.0.1'],
            )->withHeader('Host', $authority));
            self::assertSame(200, $metadata->getStatusCode(), (string) $metadata->getBody());
            $details = json_decode((string) $metadata->getBody(), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame($base, $details['issuer']);

            $request = $factory->createServerRequest('POST', $base . '/oauth/register',
                ['REMOTE_ADDR' => '127.0.0.1'])->withHeader('Host', $authority)
                ->withHeader('Content-Type', 'application/json');
            $request->getBody()->write(json_encode([
                'client_name' => 'Synthetic assistant',
                'redirect_uris' => ['https://client.example.test/callback'],
            ], JSON_THROW_ON_ERROR));
            $request->getBody()->rewind();
            $registered = $app->handle($request);
            self::assertSame(201, $registered->getStatusCode());
            $client = json_decode((string) $registered->getBody(), true, 512, JSON_THROW_ON_ERROR);
            $this->clientIds[] = $client['client_id'];

            $challenge = $app->handle($factory->createServerRequest('POST', $base . '/mcp',
                ['REMOTE_ADDR' => '127.0.0.1'])->withHeader('Host', $authority));
            self::assertSame(401, $challenge->getStatusCode());
            self::assertStringContainsString('oauth-protected-resource', $challenge->getHeaderLine('WWW-Authenticate'));

            $access = $this->tokens->generate(
                $this->userId, $this->supplierId, 'Synthetic MCP', 'read',
                new \DateTimeImmutable('+1 hour'),
            );
            $rpc = $factory->createServerRequest('POST', $base . '/mcp',
                ['REMOTE_ADDR' => '127.0.0.1'])->withHeader('Host', $authority)
                ->withHeader('Authorization', 'Bearer ' . $access['plaintext'])
                ->withHeader('Content-Type', 'application/json');
            $rpc->getBody()->write(json_encode([
                'jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize',
                'params' => ['protocolVersion' => '2025-06-18'],
            ], JSON_THROW_ON_ERROR));
            $rpc->getBody()->rewind();
            $initialized = $app->handle($rpc);
            self::assertSame(200, $initialized->getStatusCode(), (string) $initialized->getBody());
            $result = json_decode((string) $initialized->getBody(), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame('myucto', $result['result']['serverInfo']['name']);
        } finally {
            putenv($previous === false ? 'MYINVOICE_MCP_ENABLED' : 'MYINVOICE_MCP_ENABLED=' . $previous);
        }
    }

    public function testBrowserConsentIssuesCodeForSelectedSupplier(): void
    {
        $config = Config::load(dirname(__DIR__, 3));
        $sessions = new SessionManager($this->db, $config, new DatabaseSecurityClock());
        $session = $sessions->create($this->userId, '127.0.0.1', 'synthetic-mcp-test');
        $client = $this->oauth->register('Synthetic assistant', ['https://client.example.test/callback']);
        $this->clientIds[] = $client;
        $prior = getenv('MYINVOICE_MCP_ENABLED');
        putenv('MYINVOICE_MCP_ENABLED=1');
        try {
            $base = rtrim((string) $config->get('app.url'), '/');
            $params = [
                'client_id' => $client,
                'redirect_uri' => 'https://client.example.test/callback',
                'response_type' => 'code',
                'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', str_repeat('c', 64), true)), '+/', '-_'), '='),
                'code_challenge_method' => 'S256',
                'state' => 'synthetic-state',
                'resource' => $base . '/mcp',
                'scope' => 'read_write',
            ];
            $factory = new ServerRequestFactory();
            $cookie = (string) $config->get('session.cookie_name', '__Host-myinvoice_session');
            $get = $factory->createServerRequest('GET', $base . '/oauth/authorize?' . http_build_query($params),
                ['REMOTE_ADDR' => '127.0.0.1'])->withCookieParams([$cookie => $session['token']]);
            $app = Bootstrap::buildApp();
            $consent = $app->handle($get);
            self::assertSame(200, $consent->getStatusCode(), (string) $consent->getBody());
            self::assertStringContainsString('Synthetic assistant', (string) $consent->getBody());
            self::assertStringContainsString('/assets/mcp-consent-v3.js', (string) $consent->getBody());
            self::assertStringContainsString('<option value="read" selected>Pouze čtení</option>', (string) $consent->getBody());
            self::assertStringNotContainsString('name="password"', (string) $consent->getBody());

            $form = $params + [
                'supplier_id' => (string) $this->supplierId,
                'decision' => 'approve',
                'csrf_token' => $session['csrf_token'],
            ];
            $post = $factory->createServerRequest('POST', $base . '/oauth/authorize',
                ['REMOTE_ADDR' => '127.0.0.1'])
                ->withCookieParams([$cookie => $session['token']])
                ->withHeader('Origin', $base)
                ->withHeader('Content-Type', 'application/x-www-form-urlencoded')
                ->withParsedBody($form)
                ->withBody((new StreamFactory())->createStream(http_build_query($form)));
            $approvedWithoutMfa = $app->handle($post);
            self::assertSame(200, $approvedWithoutMfa->getStatusCode(), (string) $approvedWithoutMfa->getBody());
            self::assertStringContainsString('/assets/mcp-oauth-redirect-v1.js', (string) $approvedWithoutMfa->getBody());
            self::assertSame('no-referrer', $approvedWithoutMfa->getHeaderLine('Referrer-Policy'));
            $credential = random_bytes(32);
            $this->db->pdo()->prepare(
                'INSERT INTO webauthn_credentials
                 (user_id, credential_id, credential_id_hash, public_key, sign_count,
                  transports_json, label, created_at)
                 VALUES (?, ?, ?, ?, 0, ?, ?, UTC_TIMESTAMP(6))'
            )->execute([
                $this->userId, $credential, hash('sha256', $credential, true),
                'synthetic-public-key', '["internal"]', 'Synthetic passkey',
            ]);
            $passkeyRequired = $app->handle($post);
            self::assertSame(401, $passkeyRequired->getStatusCode());
            $passkeyConsent = $app->handle($get);
            self::assertStringContainsString('/assets/mcp-consent-v3.js', (string) $passkeyConsent->getBody());
            $this->db->pdo()->prepare('DELETE FROM webauthn_credentials WHERE user_id = ?')
                ->execute([$this->userId]);
            $approved = $app->handle($post);
            self::assertSame(200, $approved->getStatusCode(), (string) $approved->getBody());
            parse_str((string) parse_url(self::consentTarget($approved), PHP_URL_QUERY), $callback);
            self::assertSame('synthetic-state', $callback['state']);
            $grant = $this->oauth->exchange(
                $callback['code'], $client, $params['redirect_uri'], str_repeat('c', 64), $params['resource'],
            );
            self::assertIsArray($grant);
            self::assertSame($this->supplierId, $this->tokens->validate($grant['access_token'])['supplier_id']);
            self::assertSame('read', $this->tokens->validate($grant['access_token'])['scope']);

            $form['grant_scope'] = 'read_write';
            $writeApproval = $app->handle($post->withParsedBody($form)
                ->withBody((new StreamFactory())->createStream(http_build_query($form))));
            self::assertSame(200, $writeApproval->getStatusCode());
            parse_str((string) parse_url(self::consentTarget($writeApproval), PHP_URL_QUERY), $writeCallback);
            $writeGrant = $this->oauth->exchange(
                $writeCallback['code'], $client, $params['redirect_uri'], str_repeat('c', 64), $params['resource'],
            );
            self::assertIsArray($writeGrant);
            self::assertSame('read_write', $this->tokens->validate($writeGrant['access_token'])['scope']);

            $form['scope'] = 'read';
            $invalidApproval = $app->handle($post->withParsedBody($form)
                ->withBody((new StreamFactory())->createStream(http_build_query($form))));
            self::assertSame(400, $invalidApproval->getStatusCode());

            $form['decision'] = 'deny';
            $denied = $app->handle($post->withParsedBody($form)
                ->withBody((new StreamFactory())->createStream(http_build_query($form))));
            self::assertSame(200, $denied->getStatusCode());
            parse_str((string) parse_url(self::consentTarget($denied), PHP_URL_QUERY), $denial);
            self::assertSame('access_denied', $denial['error']);
            self::assertSame('synthetic-state', $denial['state']);
        } finally {
            $sessions->destroy($session['token']);
            putenv($prior === false ? 'MYINVOICE_MCP_ENABLED' : 'MYINVOICE_MCP_ENABLED=' . $prior);
        }
    }

    public function testBrowserConsentAcceptsFreshTotpAndPasskeyProofFromRealFormBody(): void
    {
        $config = Config::load(dirname(__DIR__, 3));
        $sessions = new SessionManager($this->db, $config, new DatabaseSecurityClock());
        $session = $sessions->create($this->userId, '127.0.0.1', 'synthetic-mcp-mfa');
        $client = $this->oauth->register('Synthetic assistant', ['https://client.example.test/callback']);
        $this->clientIds[] = $client;
        $prior = getenv('MYINVOICE_MCP_ENABLED');
        putenv('MYINVOICE_MCP_ENABLED=1');
        try {
            $base = rtrim((string) $config->get('app.url'), '/');
            $params = [
                'client_id' => $client,
                'redirect_uri' => 'https://client.example.test/callback',
                'response_type' => 'code',
                'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', str_repeat('c', 64), true)), '+/', '-_'), '='),
                'code_challenge_method' => 'S256',
                'state' => 'synthetic-state',
                'resource' => $base . '/mcp',
                'scope' => 'read_write',
            ];
            $cookie = (string) $config->get('session.cookie_name', '__Host-myinvoice_session');
            $factory = new ServerRequestFactory();
            $app = Bootstrap::buildApp();
            $get = $factory->createServerRequest('GET', $base . '/oauth/authorize?' . http_build_query($params),
                ['REMOTE_ADDR' => '127.0.0.1'])->withCookieParams([$cookie => $session['token']]);
            $secret = TotpService::generateSecret();
            $this->db->pdo()->prepare('UPDATE users SET totp_enabled = 1, totp_secret = ?, webauthn_user_handle = ? WHERE id = ?')
                ->execute([$secret, random_bytes(32), $this->userId]);
            $credential = random_bytes(32);
            $this->db->pdo()->prepare(
                'INSERT INTO webauthn_credentials
                 (user_id, credential_id, credential_id_hash, public_key, sign_count,
                  transports_json, aaguid, label, created_at)
                 VALUES (?, ?, ?, ?, 0, ?, ?, ?, UTC_TIMESTAMP(6))'
            )->execute([
                $this->userId, $credential, hash('sha256', $credential, true),
                random_bytes(77), '["internal"]', str_repeat("\0", 16), 'Synthetic passkey',
            ]);
            $credentialId = (int) $this->db->pdo()->lastInsertId();
            $consent = $app->handle($get);
            self::assertSame(200, $consent->getStatusCode(), (string) $consent->getBody());
            self::assertStringContainsString('Aktuální kód ověřovací aplikace', (string) $consent->getBody());
            self::assertStringContainsString('Ověřit passkey', (string) $consent->getBody());
            self::assertStringContainsString('value="deny" formnovalidate', (string) $consent->getBody());

            $form = $params + [
                'supplier_id' => (string) $this->supplierId,
                'decision' => 'approve',
                'csrf_token' => $session['csrf_token'],
                'totp_code' => (new TotpService())->currentCode($secret),
            ];
            $post = static function (array $body) use ($factory, $base, $cookie, $session, $app): \Psr\Http\Message\ResponseInterface {
                $request = $factory->createServerRequest('POST', $base . '/oauth/authorize',
                    ['REMOTE_ADDR' => '127.0.0.1'])
                    ->withCookieParams([$cookie => $session['token']])
                    ->withHeader('Origin', $base)
                    ->withHeader('Content-Type', 'application/x-www-form-urlencoded')
                    ->withBody((new StreamFactory())->createStream(http_build_query($body)));
                return $app->handle($request);
            };
            $approvedTotp = $post($form);
            self::assertSame(200, $approvedTotp->getStatusCode(), (string) $approvedTotp->getBody());
            $reusedTotp = $post($form);
            self::assertSame(401, $reusedTotp->getStatusCode());
            self::assertStringContainsString('Kód je neplatný nebo už byl použit', (string) $reusedTotp->getBody());

            $stepUp = Bootstrap::buildContainer()->get(MfaStepUpService::class);
            $proof = $stepUp->issue(
                $this->userId, $session['token'], MfaStepUpService::OPERATION_API_TOKEN_CREATE,
                'passkey', $credentialId,
            );
            unset($form['totp_code']);
            $form['step_up_token'] = $proof;
            $approvedPasskey = $post($form);
            self::assertSame(200, $approvedPasskey->getStatusCode(), (string) $approvedPasskey->getBody());
            $replayed = $post($form);
            self::assertSame(403, $replayed->getStatusCode());
            self::assertStringContainsString('Zkusit znovu', (string) $replayed->getBody());
            self::assertStringNotContainsString($proof, (string) $replayed->getBody());

            $nextSecret = TotpService::generateSecret();
            $this->db->pdo()->prepare('UPDATE users SET totp_secret = ? WHERE id = ?')
                ->execute([$nextSecret, $this->userId]);
            $form['totp_code'] = (new TotpService())->currentCode($nextSecret);
            $approvedWithStalePasskey = $post($form);
            self::assertSame(200, $approvedWithStalePasskey->getStatusCode(), (string) $approvedWithStalePasskey->getBody());
        } finally {
            $sessions->destroy($session['token']);
            putenv($prior === false ? 'MYINVOICE_MCP_ENABLED' : 'MYINVOICE_MCP_ENABLED=' . $prior);
        }
    }

    private static function consentTarget(\Psr\Http\Message\ResponseInterface $response): string
    {
        self::assertMatchesRegularExpression('/id="mcp-oauth-continue"[^>]+href="([^"]+)"/', (string) $response->getBody());
        preg_match('/id="mcp-oauth-continue"[^>]+href="([^"]+)"/', (string) $response->getBody(), $matches);
        return html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    public function testConsentOffersSupplierWithTokenPermissionWhenDefaultDeniesIt(): void
    {
        $pdo = $this->db->pdo();
        $superadminRole = (int) $pdo->query("SELECT id FROM roles WHERE system_key = 'superadmin'")->fetchColumn();
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        try {
            $pdo->prepare(
                'INSERT INTO supplier (company_name, street, city, zip, country_id, email,
                 default_currency_id, default_vat_rate_id) VALUES (?, ?, ?, ?, 1, ?, 0, 0)'
            )->execute(['MCP další s.r.o.', 'Testovací 2', 'Praha', '11000', 'mcp-other@example.test']);
            $otherSupplierId = (int) $pdo->lastInsertId();
        } finally {
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        }
        $pdo->prepare("INSERT INTO roles (name, role_type, is_active) VALUES (?, 'staff', 1)")
            ->execute(['MCP bez tokenů ' . bin2hex(random_bytes(4))]);
        $deniedRole = (int) $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO roles (name, role_type, is_active) VALUES (?, 'staff', 1)")
            ->execute(['MCP s tokeny ' . bin2hex(random_bytes(4))]);
        $allowedRole = (int) $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO role_permissions (role_id, permission_key, access_level) VALUES (?, 'profile.tokens', 2)")
            ->execute([$allowedRole]);
        $pdo->prepare('UPDATE users SET role_id = ?, default_supplier_id = ? WHERE id = ?')
            ->execute([$deniedRole, $this->supplierId, $this->userId]);
        $pdo->prepare('INSERT INTO user_suppliers (user_id, supplier_id, role_id) VALUES (?, ?, ?)')
            ->execute([$this->userId, $this->supplierId, $deniedRole]);
        $pdo->prepare('INSERT INTO user_suppliers (user_id, supplier_id, role_id) VALUES (?, ?, ?)')
            ->execute([$this->userId, $otherSupplierId, $allowedRole]);

        $config = Config::load(dirname(__DIR__, 3));
        $sessions = new SessionManager($this->db, $config, new DatabaseSecurityClock());
        $session = $sessions->create($this->userId, '127.0.0.1', 'synthetic-mcp-supplier');
        $client = $this->oauth->register('Synthetic assistant', ['https://client.example.test/callback']);
        $this->clientIds[] = $client;
        $prior = getenv('MYINVOICE_MCP_ENABLED');
        putenv('MYINVOICE_MCP_ENABLED=1');
        try {
            $base = rtrim((string) $config->get('app.url'), '/');
            $params = [
                'client_id' => $client,
                'redirect_uri' => 'https://client.example.test/callback',
                'response_type' => 'code',
                'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', str_repeat('d', 64), true)), '+/', '-_'), '='),
                'code_challenge_method' => 'S256',
                'resource' => $base . '/mcp',
                'scope' => 'read',
            ];
            $cookie = (string) $config->get('session.cookie_name', '__Host-myinvoice_session');
            $request = (new ServerRequestFactory())->createServerRequest(
                'GET', $base . '/oauth/authorize?' . http_build_query($params),
                ['REMOTE_ADDR' => '127.0.0.1'],
            )->withCookieParams([$cookie => $session['token']]);
            $response = Bootstrap::buildApp()->handle($request);
            self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
            self::assertStringContainsString('value="' . $otherSupplierId . '" selected', (string) $response->getBody());
            self::assertStringNotContainsString('value="' . $this->supplierId . '"', (string) $response->getBody());
        } finally {
            $sessions->destroy($session['token']);
            putenv($prior === false ? 'MYINVOICE_MCP_ENABLED' : 'MYINVOICE_MCP_ENABLED=' . $prior);
            $pdo->prepare('UPDATE users SET role_id = ?, default_supplier_id = NULL WHERE id = ?')
                ->execute([$superadminRole, $this->userId]);
            $pdo->prepare('DELETE FROM user_suppliers WHERE user_id = ?')->execute([$this->userId]);
            $pdo->prepare('DELETE FROM roles WHERE id IN (?, ?)')->execute([$deniedRole, $allowedRole]);
            $pdo->prepare('DELETE FROM supplier WHERE id = ?')->execute([$otherSupplierId]);
        }
    }
}
