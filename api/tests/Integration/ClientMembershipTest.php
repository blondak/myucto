<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Auth\ApiTokenService;
use MyInvoice\Service\Auth\DatabaseSecurityClock;
use MyInvoice\Service\Auth\SessionManager;
use MyInvoice\Infrastructure\Cache\RedisFactory;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;

/**
 * Epic F6 — role client: fail-closed membership + izolace firem (§8.3 specu).
 *
 * In-process testy přes celou middleware pipeline (vzor SupplierMembershipTest):
 *   - client bez membership řádků = 403 na každý supplier-scoped request
 *     (resolver denied + SettingsAction::membershipDenies — H3), vč. GET /api/suppliers/1
 *   - client se 2 firmami: switch OK, cizí firma 403, /api/auth/me jen membership
 *   - GET /api/settings/supplier neobsahuje *_enc ani idoklad_access_token (L7)
 *   - PAT po degradaci role accountant → client jede podle client pravidel (L4)
 */
#[Group('integration')]
final class ClientMembershipTest extends TestCase
{
    private Connection $db;
    private Config $config;
    private ApiTokenService $svc;
    private SessionManager $sessions;
    private ?App $app = null;

    private int $supplierA = 0;
    private int $supplierB = 0;
    private bool $createdSupplierB = false;
    private int $currencyB = 0;

    /** @var list<int> */
    private array $userIds = [];
    /** @var list<int> */
    private array $tokenIds = [];
    /** @var list<string> */
    private array $sessionTokens = [];
    /** @var list<int> */
    private array $contactIds = [];
    /** @var list<int> */
    private array $extraCurrencyIds = [];

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 3);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php missing');
        }
        try {
            $this->config = Config::load($rootDir);
            $this->db = new Connection($this->config);
            $redis = new RedisFactory($this->config);
            $this->svc = new ApiTokenService($this->db, $redis);
            $this->sessions = new SessionManager($this->db, $this->config, new DatabaseSecurityClock());
            $this->db->pdo()->query('SELECT 1');
        } catch (\Exception $e) {
            $this->markTestSkipped('DB unavailable: ' . $e->getMessage());
        }

        if ($this->db->pdo()->query("SHOW TABLES LIKE 'roles'")->fetchColumn() === false) {
            $this->markTestSkipped('Dynamické role chybí — spusť api/bin/migrate.php.');
        }

        $this->supplierA = (int) $this->db->pdo()->query('SELECT MIN(id) FROM supplier')->fetchColumn();
        if ($this->supplierA <= 0) {
            $this->markTestSkipped('Žádný supplier v DB.');
        }

        $second = $this->db->pdo()->query(
            'SELECT id FROM supplier ORDER BY id LIMIT 1 OFFSET 1'
        )->fetchColumn();
        if ($second !== false && $second !== null) {
            $this->supplierB = (int) $second;
        } else {
            $stmt = $this->db->pdo()->prepare(
                "INSERT INTO supplier (company_name, display_name, street, city, zip, country_id,
                                       is_vat_payer, email, default_currency_id, default_vat_rate_id,
                                       default_payment_due_days, default_hourly_rate)
                 SELECT '__TEST F6 supplier B', '__TEST F6 supplier B', street, city, zip, country_id,
                        0, email, default_currency_id, default_vat_rate_id,
                        default_payment_due_days, default_hourly_rate
                   FROM supplier WHERE id = ?"
            );
            $stmt->execute([$this->supplierA]);
            $this->supplierB = (int) $this->db->pdo()->lastInsertId();
            $this->createdSupplierB = true;

            $cur = $this->db->pdo()->prepare(
                "INSERT INTO currencies (supplier_id, code, label, symbol, name_cs, name_en, decimals, is_active, is_default)
                 VALUES (?, 'CZK', 'CZK — test', 'Kč', 'Česká koruna', 'Czech Koruna', 2, 1, 1)"
            );
            $cur->execute([$this->supplierB]);
            $this->currencyB = (int) $this->db->pdo()->lastInsertId();
        }
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) return;
        $pdo = $this->db->pdo();

        foreach ($this->sessionTokens as $token) {
            try {
                $this->sessions->destroy($token);
            } catch (\Throwable) {
                // best-effort úklid
            }
        }

        if ($this->contactIds !== []) {
            $place = implode(',', array_fill(0, count($this->contactIds), '?'));
            $pdo->prepare("DELETE FROM clients WHERE id IN ($place)")->execute($this->contactIds);
        }
        if ($this->extraCurrencyIds !== []) {
            $place = implode(',', array_fill(0, count($this->extraCurrencyIds), '?'));
            $pdo->prepare("DELETE FROM currencies WHERE id IN ($place)")->execute($this->extraCurrencyIds);
        }
        if ($this->userIds !== []) {
            $place = implode(',', array_fill(0, count($this->userIds), '?'));
            $pdo->prepare("DELETE FROM activity_log WHERE user_id IN ($place)")->execute($this->userIds);
            $pdo->prepare("DELETE FROM user_suppliers WHERE user_id IN ($place)")->execute($this->userIds);
            $pdo->prepare("DELETE FROM api_tokens WHERE user_id IN ($place)")->execute($this->userIds);
            $pdo->prepare("DELETE FROM users WHERE id IN ($place)")->execute($this->userIds);
        }
        if ($this->createdSupplierB && $this->supplierB > 0) {
            $pdo->prepare('DELETE FROM activity_log WHERE supplier_id = ?')->execute([$this->supplierB]);
            if ($this->currencyB > 0) {
                $pdo->prepare('DELETE FROM currencies WHERE id = ?')->execute([$this->currencyB]);
            }
            $pdo->prepare('DELETE FROM supplier WHERE id = ?')->execute([$this->supplierB]);
        }

        $this->userIds = $this->tokenIds = $this->sessionTokens = [];
        $this->db->close();
        $this->app = null;
    }

    // ---------------------------------------------------------------- tests

    public function testClientWithoutMembershipFailClosed(): void
    {
        $userId = $this->mkUser('client');
        $token = $this->mkToken($userId);

        // Každý supplier-scoped request → 403 (resolver fail-closed, R3)
        foreach ([
            ['GET', '/api/settings/supplier'],
            ['GET', '/api/invoices'],
            ['GET', '/api/clients'],
            ['GET', '/api/suppliers'],
        ] as [$method, $path]) {
            $res = $this->request($method, $path, $token, $this->supplierA);
            self::assertSame(403, $res->getStatusCode(), "client bez membershipu $method $path");
        }

        // Bez explicitního headeru taky 403 (žádný fallback na MIN id)
        $res = $this->request('GET', '/api/invoices', $token, null);
        self::assertSame(403, $res->getStatusCode(), 'client bez membershipu nesmí dostat fallback firmu');

        // H3: GET /api/suppliers/1 — membershipDenies musí být fail-closed
        $res = $this->request('GET', '/api/suppliers/' . $this->supplierA, $token, $this->supplierA);
        self::assertContains($res->getStatusCode(), [403, 404],
            'client bez membershipu nesmí číst konfiguraci žádné firmy (H3)');

        // Self-service: /api/auth/me musí projít a vrátit prázdný seznam firem
        $session = $this->mkSession($userId);
        $res = $this->sessionRequest('GET', '/api/auth/me', $session);
        self::assertSame(200, $res->getStatusCode(), 'client bez membershipu musí umět /api/auth/me');
        $body = $this->json($res);
        self::assertSame([], $body['suppliers'] ?? null, 'client bez membershipu = prázdný seznam firem');
        self::assertSame('client', $body['user']['role']['type'] ?? null);
    }

    public function testClientBoundPatWithoutMembershipDenied(): void
    {
        $userId = $this->mkUser('client');
        $bound = $this->mkBoundToken($userId, $this->supplierA);

        $res = $this->request('GET', '/api/invoices', $bound, null);
        self::assertSame(403, $res->getStatusCode(),
            'Bound PAT klienta bez membershipu musí být odmítnut (fail-closed i v resolveBound)');
    }

    public function testClientIsolationBetweenTwoSuppliers(): void
    {
        $userId = $this->mkUser('client');
        $this->assign($userId, [[$this->supplierA, null]]);
        $token = $this->mkToken($userId);

        // Vlastní firma OK
        $res = $this->request('GET', '/api/settings/supplier', $token, $this->supplierA);
        self::assertSame(200, $res->getStatusCode());
        self::assertSame($this->supplierA, $this->json($res)['id'] ?? null);

        // Explicitní požadavek na cizí firmu → 403
        $res = $this->request('GET', '/api/settings/supplier', $token, $this->supplierB);
        self::assertSame(403, $res->getStatusCode(), 'client s membership {A} nesmí do firmy B');
        self::assertSame('forbidden_supplier', $this->json($res)['error']['code'] ?? null);

        // Switcher: GET /api/suppliers vrací jen firmu A
        $res = $this->request('GET', '/api/suppliers', $token, $this->supplierA);
        self::assertSame(200, $res->getStatusCode());
        $ids = array_map(fn ($r) => (int) $r['id'], $this->json($res));
        self::assertSame([$this->supplierA], $ids);

        // /api/auth/me vrací jen membership firmy
        $session = $this->mkSession($userId);
        $res = $this->sessionRequest('GET', '/api/auth/me', $session);
        self::assertSame(200, $res->getStatusCode());
        $ids = array_map(fn ($r) => (int) $r['id'], $this->json($res)['suppliers'] ?? []);
        self::assertSame([$this->supplierA], $ids, '/api/auth/me musí vracet jen membership firmy');
    }

    public function testClientSupplierSettingsWithoutEncKeys(): void
    {
        // L7: response GET /api/settings/supplier nesmí obsahovat žádný klíč
        // *_enc ani idoklad_access_token (kredenciály jsou redakčně odstraněné).
        $userId = $this->mkUser('client');
        $this->assign($userId, [[$this->supplierA, null]]);
        $token = $this->mkToken($userId);

        $res = $this->request('GET', '/api/settings/supplier', $token, $this->supplierA);
        self::assertSame(200, $res->getStatusCode());
        $body = $this->json($res);
        self::assertNotSame([], $body);
        $offending = [];
        $walk = function (array $arr, string $prefix) use (&$walk, &$offending): void {
            foreach ($arr as $k => $v) {
                $key = $prefix . $k;
                if (is_string($k) && (str_ends_with($k, '_enc') || $k === 'idoklad_access_token')) {
                    $offending[] = $key;
                }
                if (is_array($v)) $walk($v, $key . '.');
            }
        };
        $walk($body, '');
        self::assertSame([], $offending, 'Response nesmí obsahovat *_enc / idoklad_access_token klíče');
    }

    public function testProjectsSubrouteTenantScopedAndDeniedForClient(): void
    {
        // BOLA fix: GET /api/clients/{id}/projects musí ověřovat, že kontakt patří
        // aktuální firmě; pro roli client je sub-routa navíc úplně zakázaná (M4).
        $contactB = $this->mkContact($this->supplierB);

        // Accountant s membershipem jen na A: kontakt cizí firmy B → 404
        $acc = $this->mkUser('accountant');
        $this->assign($acc, [[$this->supplierA, null]]);
        $accSession = $this->mkSession($acc);
        $res = $this->sessionRequest('GET', '/api/clients/' . $contactB . '/projects', $accSession);
        self::assertSame(404, $res->getStatusCode(),
            'zakázky kontaktu cizí firmy nesmí být čitelné (cross-tenant BOLA)');

        // Role client: deny rule M4 — 403 bez ohledu na tenant
        $cli = $this->mkUser('client');
        $this->assign($cli, [[$this->supplierA, null]]);
        $cliSession = $this->mkSession($cli);
        $res = $this->sessionRequest('GET', '/api/clients/' . $contactB . '/projects', $cliSession);
        self::assertSame(403, $res->getStatusCode(),
            'client: /api/clients/{id}/projects je CLIENT_DENY_RULES (M4)');
    }

    public function testPatFollowsLiveRoleAfterDegradationToClient(): void
    {
        // L4: token vyražený jako accountant + downgrade role na client →
        // request s PAT jede podle client pravidel (role se čte živě).
        $userId = $this->mkUser('accountant');
        $this->assign($userId, [[$this->supplierA, null]]);
        $token = $this->mkToken($userId);

        // Accountant smí dashboard GET (bearer path allowlist accounting nekryje,
        // proto se degradace ověřuje na dashboardu — pro klienta stejně deny-by-default)
        $res = $this->request('GET', '/api/dashboard/summary', $token, $this->supplierA);
        self::assertSame(200, $res->getStatusCode(), 'accountant smí číst dashboard');

        $this->db->pdo()->prepare('UPDATE users SET role_id = ? WHERE id = ?')
            ->execute([$this->roleId('client'), $userId]);

        // Po degradaci: dashboard → 403, vlastní faktury dál OK
        $res = $this->request('GET', '/api/dashboard/summary', $token, $this->supplierA);
        self::assertSame(403, $res->getStatusCode(), 'PAT po degradaci na client nesmí na dashboard');

        $res = $this->request('GET', '/api/invoices', $token, $this->supplierA);
        self::assertSame(200, $res->getStatusCode(), 'PAT po degradaci jede podle client pravidel (vlastní data OK)');

        // PUBLIC_OR_SELF kryje self-service i pro klienta
        $session = $this->mkSession($userId);
        $res = $this->sessionRequest('GET', '/api/auth/me', $session);
        self::assertSame(200, $res->getStatusCode());
        self::assertSame('client', $this->json($res)['user']['role']['type'] ?? null);
    }

    // ------------------------------------------------------------- fixtures

    private function mkUser(string $role): int
    {
        $email = '__test_f6_' . bin2hex(random_bytes(6)) . '@example.com';
        $stmt = $this->db->pdo()->prepare(
            "INSERT INTO users (email, password_hash, name, role_id, locale, is_active)
             VALUES (?, '\$2y\$10\$abcdefghijklmnopqrstuvABCDEFGHIJKLMNOPQRSTUVWXYZ01234', '__TEST F6', ?, 'cs', 1)"
        );
        $stmt->execute([$email, $this->roleId($role)]);
        $id = (int) $this->db->pdo()->lastInsertId();
        $this->userIds[] = $id;
        return $id;
    }

    /** @param list<array{0:int,1:?string}> $rows [[supplier_id, role|null], …] */
    private function assign(int $userId, array $rows): void
    {
        $ins = $this->db->pdo()->prepare(
            'INSERT INTO user_suppliers (user_id, supplier_id, role_id) VALUES (?, ?, ?)'
        );
        foreach ($rows as [$sid, $role]) {
            $ins->execute([$userId, $sid, $role !== null ? $this->roleId($role) : null]);
        }
    }

    private function roleId(string $legacy): int
    {
        $key = $legacy === 'admin' ? 'superadmin' : $legacy;
        $stmt = $this->db->pdo()->prepare('SELECT id FROM roles WHERE system_key = ?');
        $stmt->execute([$key]);
        return (int) $stmt->fetchColumn();
    }

    private function mkContact(int $supplierId): int
    {
        $pdo = $this->db->pdo();
        $cur = $pdo->prepare('SELECT id FROM currencies WHERE supplier_id = ? LIMIT 1');
        $cur->execute([$supplierId]);
        $currencyId = (int) ($cur->fetchColumn() ?: 0);
        if ($currencyId === 0) {
            $ins = $pdo->prepare(
                "INSERT INTO currencies (supplier_id, code, label, symbol, name_cs, name_en, decimals, is_active, is_default)
                 VALUES (?, 'CZK', 'CZK — test', 'Kč', 'Česká koruna', 'Czech Koruna', 2, 1, 1)"
            );
            $ins->execute([$supplierId]);
            $currencyId = (int) $pdo->lastInsertId();
            $this->extraCurrencyIds[] = $currencyId;
        }
        $stmt = $pdo->prepare(
            "INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id,
                                  main_email, currency_default_id, is_customer, is_vendor)
             SELECT ?, '__TEST F6 kontakt', street, city, zip, country_id,
                    'f6-contact@example.com', ?, 1, 0
               FROM supplier WHERE id = ?"
        );
        $stmt->execute([$supplierId, $currencyId, $this->supplierA]);
        $id = (int) $pdo->lastInsertId();
        $this->contactIds[] = $id;
        return $id;
    }

    private function mkToken(int $userId): string
    {
        $out = $this->svc->generate($userId, null, '__test_f6_' . bin2hex(random_bytes(4)), 'read_write', null);
        $this->tokenIds[] = (int) $out['id'];
        return $out['plaintext'];
    }

    private function mkBoundToken(int $userId, int $supplierId): string
    {
        $out = $this->svc->generate($userId, $supplierId, '__test_f6_bound_' . bin2hex(random_bytes(4)), 'read_write', null);
        $this->tokenIds[] = (int) $out['id'];
        return $out['plaintext'];
    }

    /** @return array{token:string, csrf_token:string} */
    private function mkSession(int $userId): array
    {
        $out = $this->sessions->create($userId, '127.0.0.1', '__test_f6');
        $this->sessionTokens[] = (string) $out['token'];
        return ['token' => $out['token'], 'csrf_token' => $out['csrf_token']];
    }

    // -------------------------------------------------------------- helpers

    private function app(): App
    {
        return $this->app ??= Bootstrap::buildApp();
    }

    private function request(
        string $method,
        string $path,
        string $bearer,
        ?int $supplierId,
        ?array $body = null,
    ): ResponseInterface {
        $req = (new ServerRequestFactory())
            ->createServerRequest($method, $path, ['REMOTE_ADDR' => '127.0.0.1'])
            ->withHeader('Accept', 'application/json')
            ->withHeader('Authorization', 'Bearer ' . $bearer);
        if ($supplierId !== null) {
            $req = $req->withHeader('X-Supplier-Id', (string) $supplierId);
        }
        if ($body !== null) {
            $stream = (new StreamFactory())->createStream(
                json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)
            );
            $req = $req->withHeader('Content-Type', 'application/json')->withBody($stream);
        }
        return $this->app()->handle($req);
    }

    /** @param array{token:string, csrf_token:string} $session */
    private function sessionRequest(
        string $method,
        string $path,
        array $session,
        ?array $body = null,
    ): ResponseInterface {
        $cookieName = (string) $this->config->get('session.cookie_name', '__Host-myinvoice_session');
        $appUrl = rtrim((string) $this->config->get('app.url', ''), '/');
        $req = (new ServerRequestFactory())
            ->createServerRequest($method, $path, ['REMOTE_ADDR' => '127.0.0.1'])
            ->withCookieParams([$cookieName => $session['token']])
            ->withHeader('Accept', 'application/json')
            ->withHeader('Origin', $appUrl)
            ->withHeader('X-CSRF-Token', $session['csrf_token']);
        if ($body !== null) {
            $stream = (new StreamFactory())->createStream(
                json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)
            );
            $req = $req->withHeader('Content-Type', 'application/json')->withBody($stream);
        }
        return $this->app()->handle($req);
    }

    private function json(ResponseInterface $res): array
    {
        $decoded = json_decode((string) $res->getBody(), true);
        return is_array($decoded) ? $decoded : [];
    }
}
